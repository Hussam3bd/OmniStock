<?php

namespace App\Filament\Resources\Integration\Integrations\Tables;

use App\Enums\Order\OrderChannel;
use App\Jobs\SyncShopifyOrders;
use App\Jobs\SyncShopifyProducts;
use App\Jobs\SyncShopifyReturns;
use App\Jobs\SyncTrendyolClaims;
use App\Jobs\SyncTrendyolOrders;
use App\Jobs\SyncTrendyolProducts;
use App\Models\Integration\Integration;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class IntegrationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sales_channel' => __('Sales Channel'),
                        'shipping_provider' => __('Shipping Provider'),
                        'payment_gateway' => __('Payment Gateway'),
                        'invoice_provider' => __('Invoice Provider'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sales_channel' => 'success',
                        'shipping_provider' => 'info',
                        'payment_gateway' => 'warning',
                        'invoice_provider' => 'primary',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),

                IconColumn::make('webhook_configured')
                    ->label(__('Webhook'))
                    ->boolean()
                    ->getStateUsing(function ($record) {
                        if (! in_array($record->provider, ['trendyol', 'shopify'])) {
                            return null;
                        }

                        return isset($record->config['webhook']);
                    })
                    ->tooltip(function ($record) {
                        if (! in_array($record->provider, ['trendyol', 'shopify'])) {
                            return null;
                        }

                        if (isset($record->config['webhook']['id'])) {
                            return __('Webhook ID: :id', ['id' => $record->config['webhook']['id']]);
                        }

                        if (isset($record->config['webhook']['webhooks'])) {
                            $count = count($record->config['webhook']['webhooks']);

                            return __(':count webhooks configured', ['count' => $count]);
                        }

                        return __('No webhook configured');
                    }),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label(__('Updated At'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('sync_products')
                    ->label(__('Sync Products'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('info')
                    ->visible(fn (Integration $record) => in_array($record->provider, ['trendyol', 'shopify']) && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync Products'))
                    ->modalDescription(__('This will fetch and sync all products from :provider to your store. The process will run in the background.', ['provider' => fn (Integration $record) => ucfirst($record->provider)]))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = match ($record->provider) {
                                'trendyol' => new TrendyolAdapter($record),
                                'shopify' => new ShopifyAdapter($record),
                                default => null,
                            };

                            if (! $adapter) {
                                throw new \Exception('Unsupported provider');
                            }

                            // Fetch all products (just metadata, not full sync yet)
                            $products = $adapter->fetchAllProducts();
                            $totalProducts = count($products);

                            if ($totalProducts === 0) {
                                Notification::make()
                                    ->title(__('No products found'))
                                    ->body(__('No products to sync from :provider', ['provider' => ucfirst($record->provider)]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Create jobs for each product
                            $jobs = collect($products)->map(function ($product) use ($record) {
                                return match ($record->provider) {
                                    'shopify' => new SyncShopifyProducts($record, $product),
                                    'trendyol' => new SyncTrendyolProducts($record, $product),
                                };
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync {$totalProducts} products from ".ucfirst($record->provider))
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalProducts, $record) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Product sync completed'))
                                        ->body(__('Successfully synced all :count products from :provider', [
                                            'count' => $totalProducts,
                                            'provider' => ucfirst($record->provider),
                                        ]))
                                        ->success()
                                        ->sendToDatabase($user);
                                })
                                ->catch(function (Batch $batch, \Throwable $e) use ($user, $record) {
                                    // First batch job failure
                                    Notification::make()
                                        ->title(__('Product sync error'))
                                        ->body(__('An error occurred during product sync from :provider: :error', [
                                            'provider' => ucfirst($record->provider),
                                            'error' => $e->getMessage(),
                                        ]))
                                        ->danger()
                                        ->sendToDatabase($user);
                                })
                                ->finally(function (Batch $batch) use ($user, $totalProducts, $record) {
                                    // Always runs, even with failures
                                    if ($batch->failedJobs > 0) {
                                        $successCount = $batch->totalJobs - $batch->failedJobs;

                                        Notification::make()
                                            ->title(__('Product sync completed with errors'))
                                            ->body(__('Synced :success of :total products from :provider. :failed failed.', [
                                                'success' => $successCount,
                                                'total' => $totalProducts,
                                                'failed' => $batch->failedJobs,
                                                'provider' => ucfirst($record->provider),
                                            ]))
                                            ->warning()
                                            ->sendToDatabase($user);
                                    }
                                })
                                ->dispatch();

                            Notification::make()
                                ->title(__('Product sync started'))
                                ->body(__('Syncing :count products from :provider in the background. You will be notified when complete.', [
                                    'count' => $totalProducts,
                                    'provider' => ucfirst($record->provider),
                                ]))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error starting product sync'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('sync_orders')
                    ->label(__('Sync Orders'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (Integration $record) => in_array($record->provider, ['trendyol', 'shopify']) && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync Orders'))
                    ->modalDescription(__('This will fetch and sync recent orders from :provider. The process will run in the background.', ['provider' => fn (Integration $record) => ucfirst($record->provider)]))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = match ($record->provider) {
                                'trendyol' => new TrendyolAdapter($record),
                                'shopify' => new ShopifyAdapter($record),
                                default => null,
                            };

                            if (! $adapter) {
                                throw new \Exception('Unsupported provider');
                            }

                            // Fetch all orders (just metadata, not full sync yet)
                            $orders = $adapter->fetchAllOrders(now()->subDays(30));
                            $totalOrders = count($orders);

                            if ($totalOrders === 0) {
                                Notification::make()
                                    ->title(__('No orders found'))
                                    ->body(__('No orders to sync from :provider', ['provider' => ucfirst($record->provider)]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Create jobs for each order
                            $jobs = collect($orders)->map(function ($order) use ($record) {
                                return match ($record->provider) {
                                    'shopify' => new SyncShopifyOrders($record, $order),
                                    'trendyol' => new SyncTrendyolOrders($record, $order),
                                };
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync {$totalOrders} orders from ".ucfirst($record->provider))
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalOrders, $record) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Order sync completed'))
                                        ->body(__('Successfully synced all :count orders from :provider', [
                                            'count' => $totalOrders,
                                            'provider' => ucfirst($record->provider),
                                        ]))
                                        ->success()
                                        ->sendToDatabase($user);
                                })
                                ->catch(function (Batch $batch, \Throwable $e) use ($user, $record) {
                                    // First batch job failure
                                    Notification::make()
                                        ->title(__('Order sync error'))
                                        ->body(__('An error occurred during order sync from :provider: :error', [
                                            'provider' => ucfirst($record->provider),
                                            'error' => $e->getMessage(),
                                        ]))
                                        ->danger()
                                        ->sendToDatabase($user);
                                })
                                ->finally(function (Batch $batch) use ($user, $totalOrders, $record) {
                                    // Always runs, even with failures
                                    if ($batch->failedJobs > 0) {
                                        $successCount = $batch->totalJobs - $batch->failedJobs;

                                        Notification::make()
                                            ->title(__('Order sync completed with errors'))
                                            ->body(__('Synced :success of :total orders from :provider. :failed failed.', [
                                                'success' => $successCount,
                                                'total' => $totalOrders,
                                                'failed' => $batch->failedJobs,
                                                'provider' => ucfirst($record->provider),
                                            ]))
                                            ->warning()
                                            ->sendToDatabase($user);
                                    }
                                })
                                ->dispatch();

                            Notification::make()
                                ->title(__('Order sync started'))
                                ->body(__('Syncing :count orders from :provider in the background. You will be notified when complete.', [
                                    'count' => $totalOrders,
                                    'provider' => ucfirst($record->provider),
                                ]))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error starting order sync'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('sync_refunds')
                    ->label(__('Sync Refunds'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (Integration $record) => $record->provider === 'shopify' && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync Refunds'))
                    ->modalDescription(__('This will fetch and sync refunds from Shopify orders. The process will run in the background.'))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = new ShopifyAdapter($record);

                            // Get all Shopify order IDs from platform mappings
                            $orderIds = PlatformMapping::where('platform', OrderChannel::SHOPIFY->value)
                                ->where('entity_type', \App\Models\Order\Order::class)
                                ->pluck('platform_id');

                            if ($orderIds->isEmpty()) {
                                Notification::make()
                                    ->title(__('No orders found'))
                                    ->body(__('No Shopify orders to sync refunds for'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Fetch all refunds for these orders
                            $allRefunds = collect();

                            foreach ($orderIds as $orderId) {
                                $refunds = $adapter->fetchOrderRefunds($orderId);

                                foreach ($refunds as $refund) {
                                    // Add order_id to refund data for mapping
                                    $refund['order_id'] = $orderId;
                                    $allRefunds->push($refund);
                                }
                            }

                            $totalRefunds = count($allRefunds);

                            if ($totalRefunds === 0) {
                                Notification::make()
                                    ->title(__('No refunds found'))
                                    ->body(__('No refunds to sync from Shopify'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Create jobs for each refund
                            $jobs = $allRefunds->map(function ($refund) use ($record) {
                                return new SyncShopifyReturns($record, $refund);
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync {$totalRefunds} refunds from Shopify")
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalRefunds) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Refund sync completed'))
                                        ->body(__('Successfully synced all :count refunds from Shopify', [
                                            'count' => $totalRefunds,
                                        ]))
                                        ->success()
                                        ->sendToDatabase($user);
                                })
                                ->catch(function (Batch $batch, \Throwable $e) use ($user) {
                                    // First batch job failure
                                    Notification::make()
                                        ->title(__('Refund sync error'))
                                        ->body(__('An error occurred during refund sync from Shopify: :error', [
                                            'error' => $e->getMessage(),
                                        ]))
                                        ->danger()
                                        ->sendToDatabase($user);
                                })
                                ->finally(function (Batch $batch) use ($user, $totalRefunds) {
                                    // Always runs, even with failures
                                    if ($batch->failedJobs > 0) {
                                        $successCount = $batch->totalJobs - $batch->failedJobs;

                                        Notification::make()
                                            ->title(__('Refund sync completed with errors'))
                                            ->body(__('Synced :success of :total refunds from Shopify. :failed failed.', [
                                                'success' => $successCount,
                                                'total' => $totalRefunds,
                                                'failed' => $batch->failedJobs,
                                            ]))
                                            ->warning()
                                            ->sendToDatabase($user);
                                    }
                                })
                                ->dispatch();

                            Notification::make()
                                ->title(__('Refund sync started'))
                                ->body(__('Syncing :count refunds from Shopify in the background. You will be notified when complete.', [
                                    'count' => $totalRefunds,
                                ]))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error starting refund sync'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('sync_claims')
                    ->label(__('Sync Claims'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (Integration $record) => $record->provider === 'trendyol' && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync Claims'))
                    ->modalDescription(__('This will fetch and sync return claims from Trendyol. The process will run in the background.'))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = new TrendyolAdapter($record);

                            // Fetch all claims
                            $allClaims = $adapter->fetchAllClaims();

                            $totalClaims = count($allClaims);

                            if ($totalClaims === 0) {
                                Notification::make()
                                    ->title(__('No claims found'))
                                    ->body(__('No claims to sync from Trendyol'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Create jobs for each claim
                            $jobs = $allClaims->map(function ($claim) use ($record) {
                                return new SyncTrendyolClaims($record, $claim);
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync {$totalClaims} claims from Trendyol")
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalClaims) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Claim sync completed'))
                                        ->body(__('Successfully synced all :count claims from Trendyol', [
                                            'count' => $totalClaims,
                                        ]))
                                        ->success()
                                        ->sendToDatabase($user);
                                })
                                ->catch(function (Batch $batch, \Throwable $e) use ($user) {
                                    // First batch job failure
                                    Notification::make()
                                        ->title(__('Claim sync error'))
                                        ->body(__('An error occurred during claim sync from Trendyol: :error', [
                                            'error' => $e->getMessage(),
                                        ]))
                                        ->danger()
                                        ->sendToDatabase($user);
                                })
                                ->finally(function (Batch $batch) use ($user, $totalClaims) {
                                    // Always runs, even with failures
                                    if ($batch->failedJobs > 0) {
                                        $successCount = $batch->totalJobs - $batch->failedJobs;

                                        Notification::make()
                                            ->title(__('Claim sync completed with errors'))
                                            ->body(__('Synced :success of :total claims from Trendyol. :failed failed.', [
                                                'success' => $successCount,
                                                'total' => $totalClaims,
                                                'failed' => $batch->failedJobs,
                                            ]))
                                            ->warning()
                                            ->sendToDatabase($user);
                                    }
                                })
                                ->dispatch();

                            Notification::make()
                                ->title(__('Claim sync started'))
                                ->body(__('Syncing :count claims from Trendyol in the background. You will be notified when complete.', [
                                    'count' => $totalClaims,
                                ]))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error starting claim sync'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('register_webhook')
                    ->label(fn (Integration $record) => isset($record->config['webhook'])
                        ? __('Update Webhook')
                        : __('Register Webhook'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->color(fn (Integration $record) => isset($record->config['webhook'])
                        ? 'info'
                        : 'success')
                    ->visible(fn (Integration $record) => in_array($record->provider, ['trendyol', 'shopify']) && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Integration $record) => isset($record->config['webhook'])
                        ? __('Update :provider Webhook', ['provider' => ucfirst($record->provider)])
                        : __('Register :provider Webhook', ['provider' => ucfirst($record->provider)]))
                    ->modalDescription(__('This will create/update webhooks to receive real-time updates from :provider.', ['provider' => fn (Integration $record) => ucfirst($record->provider)]))
                    ->modalSubmitActionLabel(__('Proceed'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = match ($record->provider) {
                                'trendyol' => new TrendyolAdapter($record),
                                'shopify' => new ShopifyAdapter($record),
                                default => null,
                            };

                            if (! $adapter) {
                                throw new \Exception('Unsupported provider');
                            }

                            if ($adapter->registerWebhooks()) {
                                Notification::make()
                                    ->title(__('Webhooks registered successfully'))
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title(__('Failed to register webhooks'))
                                    ->body(__('Please check your integration settings and try again.'))
                                    ->danger()
                                    ->send();
                            }
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error registering webhooks'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
