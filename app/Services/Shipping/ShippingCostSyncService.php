<?php

namespace App\Services\Shipping;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Shipping\ShippingCarrier;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;

class ShippingCostSyncService
{
    public function __construct(
        protected ShippingCostService $shippingCostService
    ) {}

    /**
     * Sync shipping costs for an order from BasitKargo
     */
    public function syncShippingCostFromBasitKargo(Order $order): bool
    {
        // Skip if order doesn't have tracking number
        if (! $order->shipping_tracking_number) {
            return false;
        }

        // Skip if order already has calculated shipping costs
        if ($order->shipping_cost_excluding_vat && $order->carrier) {
            return false;
        }

        // Get active BasitKargo integration
        $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            activity()
                ->performedOn($order)
                ->log('basitkargo_integration_not_found');

            return false;
        }

        try {
            // Create adapter
            $adapter = new BasitKargoAdapter($integration);

            // Fetch shipment cost
            $costData = $adapter->getShipmentCost($order->shipping_tracking_number);

            if (! $costData) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'tracking_number' => $order->shipping_tracking_number,
                    ])
                    ->log('basitkargo_cost_not_found');

                return false;
            }

            // Parse carrier to enum
            $carrier = $this->shippingCostService->parseCarrier($costData['carrier_name']);

            // If carrier couldn't be parsed, try to map BasitKargo code directly
            if (! $carrier && $costData['carrier_code']) {
                $carrier = $this->mapBasitKargoCodeToCarrier($costData['carrier_code']);
            }

            // Update order with shipping cost data
            $order->update([
                'carrier' => $carrier?->value,
                'shipping_desi' => $costData['desi'],
                'shipping_cost_excluding_vat' => $costData['price_excluding_vat'],
                'shipping_vat_rate' => $costData['vat_rate'],
                'shipping_vat_amount' => $costData['vat_amount'],
                // Note: We don't update shipping_amount as it's what the customer paid
                // shipping_cost_excluding_vat is what we actually paid to the carrier
            ]);

            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'carrier' => $carrier?->value,
                    'cost' => $costData['price_excluding_vat'],
                    'desi' => $costData['desi'],
                ])
                ->log('basitkargo_shipping_cost_synced');

            return true;
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_sync_failed');

            return false;
        }
    }

    /**
     * Sync shipping costs for multiple orders
     */
    public function syncMultipleOrders(int $limit = 50): int
    {
        // Find orders that need cost sync:
        // - Shopify channel
        // - Has tracking number
        // - Missing carrier or shipping_cost_excluding_vat
        $orders = Order::where('channel', 'shopify')
            ->whereNotNull('shipping_tracking_number')
            ->where(function ($query) {
                $query->whereNull('carrier')
                    ->orWhereNull('shipping_cost_excluding_vat');
            })
            ->limit($limit)
            ->get();

        $synced = 0;

        foreach ($orders as $order) {
            if ($this->syncShippingCostFromBasitKargo($order)) {
                $synced++;
            }

            // Add small delay to avoid rate limiting
            usleep(200000); // 200ms delay
        }

        return $synced;
    }

    /**
     * Map BasitKargo carrier codes to our ShippingCarrier enum
     */
    protected function mapBasitKargoCodeToCarrier(string $code): ?ShippingCarrier
    {
        return match (strtoupper($code)) {
            'MNG' => null, // MNG not in our enum
            'YURTICI' => ShippingCarrier::YURTICI,
            'ARAS' => ShippingCarrier::ARAS,
            'SURAT' => ShippingCarrier::SURAT,
            'PTT' => ShippingCarrier::PTT,
            'DHL' => ShippingCarrier::DHL,
            default => null,
        };
    }
}
