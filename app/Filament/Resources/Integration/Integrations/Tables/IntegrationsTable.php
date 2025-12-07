<?php

namespace App\Filament\Resources\Integration\Integrations\Tables;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Order\OrderChannel;
use App\Filament\Actions\Integration\SyncShopifyOrderReturnsAction;
use App\Jobs\FetchAndSyncShopifyRefunds;
use App\Jobs\SyncShopifyOrders;
use App\Jobs\SyncShopifyProducts;
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
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
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
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->badge()
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
                        if (! in_array($record->provider, [IntegrationProvider::TRENDYOL, IntegrationProvider::SHOPIFY])) {
                            return null;
                        }

                        return isset($record->config['webhook']);
                    })
                    ->tooltip(function ($record) {
                        if (! in_array($record->provider, [IntegrationProvider::TRENDYOL, IntegrationProvider::SHOPIFY])) {
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
                    ->visible(fn (Integration $record) => in_array($record->provider, [IntegrationProvider::TRENDYOL, IntegrationProvider::SHOPIFY]) && $record->is_active)
                    ->fillForm([
                        'sync_images' => false,
                        'sync_inventory' => false,
                    ])
                    ->schema([
                        Checkbox::make('sync_images')
                            ->label(__('Sync Product Images'))
                            ->helperText(__('Import product images from the platform. Leave unchecked to keep existing images.')),
                        Checkbox::make('sync_inventory')
                            ->label(__('Sync Inventory/Stock'))
                            ->helperText(__('Import stock quantities from the platform. Leave unchecked to keep your app as the source of truth for inventory.')),
                    ])
                    ->modalHeading(__('Sync Products'))
                    ->modalDescription(__('This will fetch and sync all products from :provider to your store. By default, images and inventory are NOT synced - your app remains the source of truth.', ['provider' => fn (Integration $record) => $record->provider->getLabel()]))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record, array $data) {
                        try {
                            $adapter = match ($record->provider) {
                                IntegrationProvider::TRENDYOL => new TrendyolAdapter($record),
                                IntegrationProvider::SHOPIFY => new ShopifyAdapter($record),
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
                                    ->body(__('No products to sync from :provider', ['provider' => $record->provider->getLabel()]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Get sync options from form
                            $syncImages = $data['sync_images'] ?? false;
                            $syncInventory = $data['sync_inventory'] ?? false;

                            // Create jobs for each product with sync options
                            $jobs = collect($products)->map(function ($product) use ($record, $syncImages, $syncInventory) {
                                return match ($record->provider) {
                                    IntegrationProvider::SHOPIFY => new SyncShopifyProducts($record, $product, $syncImages, $syncInventory),
                                    IntegrationProvider::TRENDYOL => new SyncTrendyolProducts($record, $product, $syncImages, $syncInventory),
                                };
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync {$totalProducts} products from ".$record->provider->getLabel())
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalProducts, $record) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Product sync completed'))
                                        ->body(__('Successfully synced all :count products from :provider', [
                                            'count' => $totalProducts,
                                            'provider' => $record->provider->getLabel(),
                                        ]))
                                        ->success()
                                        ->sendToDatabase($user);
                                })
                                ->catch(function (Batch $batch, \Throwable $e) use ($user, $record) {
                                    // First batch job failure
                                    Notification::make()
                                        ->title(__('Product sync error'))
                                        ->body(__('An error occurred during product sync from :provider: :error', [
                                            'provider' => $record->provider->getLabel(),
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
                                                'provider' => $record->provider->getLabel(),
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
                                    'provider' => $record->provider->getLabel(),
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
                    ->visible(fn (Integration $record) => in_array($record->provider, [IntegrationProvider::TRENDYOL, IntegrationProvider::SHOPIFY]) && $record->is_active)
                    ->form([
                        DatePicker::make('since_date')
                            ->label(__('Sync orders since'))
                            ->helperText(__('Select the start date to sync orders from. Leave empty to sync last 7 days.'))
                            ->default(now()->subDays(7))
                            ->maxDate(now())
                            ->native(false),
                    ])
                    ->modalHeading(__('Sync Orders'))
                    ->modalDescription(__('This will fetch and sync orders from :provider. The process will run in the background.', ['provider' => fn (Integration $record) => $record->provider->getLabel()]))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record, array $data) {
                        try {
                            $adapter = match ($record->provider) {
                                IntegrationProvider::TRENDYOL => new TrendyolAdapter($record),
                                IntegrationProvider::SHOPIFY => new ShopifyAdapter($record),
                                default => null,
                            };

                            if (! $adapter) {
                                throw new \Exception('Unsupported provider');
                            }

                            // Get the date from form data, default to 7 days ago if not set
                            $sinceDate = $data['since_date'] ? \Carbon\Carbon::parse($data['since_date']) : now()->subDays(7);

                            // Fetch all orders (just metadata, not full sync yet)
                            $orders = $adapter->fetchAllOrders($sinceDate);
                            $totalOrders = count($orders);

                            if ($totalOrders === 0) {
                                Notification::make()
                                    ->title(__('No orders found'))
                                    ->body(__('No orders to sync from :provider', ['provider' => $record->provider->getLabel()]))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            // Create jobs for each order
                            $jobs = collect($orders)->map(function ($order) use ($record) {
                                return match ($record->provider) {
                                    IntegrationProvider::SHOPIFY => new SyncShopifyOrders($record, $order),
                                    IntegrationProvider::TRENDYOL => new SyncTrendyolOrders($record, $order),
                                };
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync {$totalOrders} orders from ".$record->provider->getLabel())
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalOrders, $record) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Order sync completed'))
                                        ->body(__('Successfully synced all :count orders from :provider', [
                                            'count' => $totalOrders,
                                            'provider' => $record->provider->getLabel(),
                                        ]))
                                        ->success()
                                        ->sendToDatabase($user);
                                })
                                ->catch(function (Batch $batch, \Throwable $e) use ($user, $record) {
                                    // First batch job failure
                                    Notification::make()
                                        ->title(__('Order sync error'))
                                        ->body(__('An error occurred during order sync from :provider: :error', [
                                            'provider' => $record->provider->getLabel(),
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
                                                'provider' => $record->provider->getLabel(),
                                            ]))
                                            ->warning()
                                            ->sendToDatabase($user);
                                    }
                                })
                                ->dispatch();

                            Notification::make()
                                ->title(__('Order sync started'))
                                ->body(__('Syncing :count orders from :provider (since :date) in the background. You will be notified when complete.', [
                                    'count' => $totalOrders,
                                    'provider' => $record->provider->getLabel(),
                                    'date' => $sinceDate->format('Y-m-d'),
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
                    ->visible(fn (Integration $record) => $record->provider === IntegrationProvider::SHOPIFY && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(__('Sync Refunds'))
                    ->modalDescription(__('This will fetch and sync refunds from Shopify orders. The process will run in the background.'))
                    ->modalSubmitActionLabel(__('Start Sync'))
                    ->action(function (Integration $record) {
                        try {
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

                            $totalOrders = count($orderIds);

                            // Create jobs for each order (each job will fetch and sync its refunds)
                            $jobs = $orderIds->map(function ($orderId) use ($record) {
                                return new FetchAndSyncShopifyRefunds($record, $orderId);
                            })->toArray();

                            // Get current user for notifications
                            $user = auth()->user();

                            // Dispatch batch
                            $batch = Bus::batch($jobs)
                                ->name("Sync refunds from {$totalOrders} Shopify orders")
                                ->allowFailures()
                                ->onQueue('default')
                                ->then(function (Batch $batch) use ($user, $totalOrders) {
                                    // All jobs completed successfully
                                    Notification::make()
                                        ->title(__('Refund sync completed'))
                                        ->body(__('Successfully synced refunds from all :count Shopify orders', [
                                            'count' => $totalOrders,
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
                                ->finally(function (Batch $batch) use ($user, $totalOrders) {
                                    // Always runs, even with failures
                                    if ($batch->failedJobs > 0) {
                                        $successCount = $batch->totalJobs - $batch->failedJobs;

                                        Notification::make()
                                            ->title(__('Refund sync completed with errors'))
                                            ->body(__('Synced refunds from :success of :total Shopify orders. :failed failed.', [
                                                'success' => $successCount,
                                                'total' => $totalOrders,
                                                'failed' => $batch->failedJobs,
                                            ]))
                                            ->warning()
                                            ->sendToDatabase($user);
                                    }
                                })
                                ->dispatch();

                            Notification::make()
                                ->title(__('Refund sync started'))
                                ->body(__('Syncing refunds from :count Shopify orders in the background. You will be notified when complete.', [
                                    'count' => $totalOrders,
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

                SyncShopifyOrderReturnsAction::make(),

                Action::make('sync_claims')
                    ->label(__('Sync Claims'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (Integration $record) => $record->provider === IntegrationProvider::TRENDYOL && $record->is_active)
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
                    ->visible(fn (Integration $record) => in_array($record->provider, [IntegrationProvider::TRENDYOL, IntegrationProvider::SHOPIFY]) && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Integration $record) => isset($record->config['webhook'])
                        ? __('Update :provider Webhook', ['provider' => $record->provider->getLabel()])
                        : __('Register :provider Webhook', ['provider' => $record->provider->getLabel()]))
                    ->modalDescription(__('This will create/update webhooks to receive real-time updates from :provider.', ['provider' => fn (Integration $record) => $record->provider->getLabel()]))
                    ->modalSubmitActionLabel(__('Proceed'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = match ($record->provider) {
                                IntegrationProvider::TRENDYOL => new TrendyolAdapter($record),
                                IntegrationProvider::SHOPIFY => new ShopifyAdapter($record),
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
