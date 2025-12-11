<?php

namespace App\Services\Order;

use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\OrderMapper as ShopifyOrderMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper as TrendyolOrderMapper;

class OrderSyncService
{
    /**
     * Get active integration for an order
     * Centralizes the integration lookup logic used across sync operations
     */
    public function getActiveIntegration(Order $order, ?IntegrationType $type = null): ?Integration
    {
        // Prefer the order's integration if available
        $integration = $order->integration;

        if ($integration && $integration->is_active) {
            // If type filter is specified, validate it matches
            if ($type && $integration->type !== $type) {
                $integration = null;
            } else {
                return $integration;
            }
        }

        // Fall back to finding an active integration for this channel
        $query = Integration::where('provider', $order->channel->value)
            ->where('is_active', true);

        if ($type) {
            $query->where('type', $type);
        }

        return $query->first();
    }

    /**
     * Sync fulfillment data from the source channel
     * Handles Shopify and Trendyol orders
     *
     * @return array{success: bool, error?: string}
     */
    public function syncFulfillmentData(Order $order): array
    {
        // Validate order is external
        if (! $order->isExternal()) {
            return [
                'success' => false,
                'error' => 'order_not_external',
            ];
        }

        // Get platform mapping
        $mapping = $order->platformMappings()->first();

        if (! $mapping) {
            return [
                'success' => false,
                'error' => 'no_platform_mapping',
            ];
        }

        // Get active integration
        $integration = $this->getActiveIntegration($order);

        if (! $integration) {
            return [
                'success' => false,
                'error' => 'no_active_integration',
            ];
        }

        // Sync based on channel
        try {
            match ($order->channel) {
                OrderChannel::SHOPIFY => $this->syncShopifyFulfillment($order, $mapping, $integration),
                OrderChannel::TRENDYOL => $this->syncTrendyolFulfillment($order, $mapping, $integration),
                default => throw new \Exception('Channel not supported for fulfillment sync'),
            };

            activity()
                ->performedOn($order)
                ->withProperties([
                    'channel' => $order->channel->value,
                    'platform_id' => $mapping->platform_id,
                ])
                ->log('fulfillment_data_synced');

            return ['success' => true];
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'channel' => $order->channel->value,
                    'error' => $e->getMessage(),
                ])
                ->log('fulfillment_data_sync_failed');

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync Shopify fulfillment data
     */
    protected function syncShopifyFulfillment(Order $order, $mapping, Integration $integration): void
    {
        $adapter = new ShopifyAdapter($integration);
        $mapper = app(ShopifyOrderMapper::class);

        // Fetch order with transactions from Shopify
        $orderData = $adapter->fetchOrderWithTransactions($mapping->platform_id);

        if (! $orderData) {
            throw new \Exception('Could not fetch order from Shopify');
        }

        // Map/sync the order - updates fulfillment status, tracking number, carrier, etc.
        $mapper->mapOrder($orderData, $integration);
    }

    /**
     * Sync Trendyol fulfillment data
     */
    protected function syncTrendyolFulfillment(Order $order, $mapping, Integration $integration): void
    {
        $mapper = app(TrendyolOrderMapper::class);

        // For Trendyol, use the stored platform_data from the mapping
        // The Trendyol API doesn't have a direct endpoint to fetch a single package by ID
        $packageData = $mapping->platform_data;

        if (! $packageData) {
            throw new \Exception('No platform data available for this Trendyol order');
        }

        // Map/sync the order - this will recalculate costs, profits, etc.
        $mapper->mapOrder($packageData, $integration);
    }
}
