<?php

namespace App\Filament\Actions\Order;

use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\OrderMapper as ShopifyOrderMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper as TrendyolOrderMapper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ResyncOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resync';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resync'))
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Order from Channel'))
            ->modalDescription(fn (Order $record) => __('This will fetch the latest data for order :number from :channel and update it.', [
                'number' => $record->order_number,
                'channel' => $record->channel->getLabel(),
            ]))
            ->visible(fn (Order $record) => $record->isExternal())
            ->action(function (Order $record) {
                try {
                    // Get platform mapping to find the platform order ID
                    $mapping = $record->platformMappings()->first();

                    if (! $mapping) {
                        Notification::make()
                            ->danger()
                            ->title(__('Resync Failed'))
                            ->body(__('No platform mapping found for this order.'))
                            ->send();

                        return;
                    }

                    // Get the integration for this channel
                    $integration = Integration::where('provider', $record->channel->value)
                        ->where('is_active', true)
                        ->first();

                    if (! $integration) {
                        Notification::make()
                            ->danger()
                            ->title(__('Resync Failed'))
                            ->body(__('No active integration found for :channel.', ['channel' => $record->channel->getLabel()]))
                            ->send();

                        return;
                    }

                    // Fetch and sync order based on channel
                    match ($record->channel) {
                        OrderChannel::SHOPIFY => $this->resyncShopifyOrder($record, $mapping, $integration),
                        OrderChannel::TRENDYOL => $this->resyncTrendyolOrder($record, $mapping, $integration),
                        default => throw new \Exception(__('Channel not supported for resync')),
                    };

                    Notification::make()
                        ->success()
                        ->title(__('Order Resynced'))
                        ->body(__('Order :number has been successfully resynced from :channel.', [
                            'number' => $record->order_number,
                            'channel' => $record->channel->getLabel(),
                        ]))
                        ->send();

                    // Refresh the page to show updated data
                    $this->redirect(request()->url());
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Resync Failed'))
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    protected function resyncShopifyOrder($record, $mapping, Integration $integration): void
    {
        $adapter = new ShopifyAdapter($integration);
        $mapper = app(ShopifyOrderMapper::class);

        // Fetch order with transactions from Shopify
        $orderData = $adapter->fetchOrderWithTransactions($mapping->platform_id);

        if (! $orderData) {
            throw new \Exception(__('Could not fetch order from Shopify.'));
        }

        // Map/sync the order
        $mapper->mapOrder($orderData);
    }

    protected function resyncTrendyolOrder($record, $mapping, Integration $integration): void
    {
        $mapper = app(TrendyolOrderMapper::class);

        // For Trendyol, use the stored platform_data from the mapping
        // The Trendyol API doesn't have a direct endpoint to fetch a single package by ID
        // So we'll use the cached data which is sufficient for resyncing costs and calculations
        $packageData = $mapping->platform_data;

        if (! $packageData) {
            throw new \Exception(__('No platform data available for this Trendyol order.'));
        }

        // Map/sync the order - this will recalculate costs, profits, etc.
        $mapper->mapOrder($packageData);
    }
}
