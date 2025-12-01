<?php

namespace App\Filament\Resources\Integration\Integrations\Tables;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\OrderMapper as ShopifyOrderMapper;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ProductMapper as ShopifyProductMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper as TrendyolOrderMapper;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\ProductMapper as TrendyolProductMapper;
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
                    ->modalDescription(__('This will fetch and sync all products from :provider to your store.', ['provider' => fn (Integration $record) => ucfirst($record->provider)]))
                    ->modalSubmitActionLabel(__('Sync Now'))
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

                            $products = $adapter->fetchAllProducts();
                            $mapper = match ($record->provider) {
                                'trendyol' => app(TrendyolProductMapper::class),
                                'shopify' => app(ShopifyProductMapper::class),
                            };

                            $synced = 0;
                            foreach ($products as $product) {
                                $mapper->mapProduct($product);
                                $synced++;
                            }

                            Notification::make()
                                ->title(__('Products synced successfully'))
                                ->body(__(':count products synced from :provider', ['count' => $synced, 'provider' => ucfirst($record->provider)]))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error syncing products'))
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
                    ->modalDescription(__('This will fetch and sync recent orders from :provider.', ['provider' => fn (Integration $record) => ucfirst($record->provider)]))
                    ->modalSubmitActionLabel(__('Sync Now'))
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

                            $orders = $adapter->fetchAllOrders(now()->subDays(30));
                            $mapper = match ($record->provider) {
                                'trendyol' => app(TrendyolOrderMapper::class),
                                'shopify' => app(ShopifyOrderMapper::class),
                            };

                            $synced = 0;
                            foreach ($orders as $order) {
                                $mapper->mapOrder($order);
                                $synced++;
                            }

                            Notification::make()
                                ->title(__('Orders synced successfully'))
                                ->body(__(':count orders synced from :provider', ['count' => $synced, 'provider' => ucfirst($record->provider)]))
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title(__('Error syncing orders'))
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
