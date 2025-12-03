<?php

namespace App\Services\Shipping;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Shipping\ShippingCarrier;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use Carbon\Carbon;

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
     * Sync shipping costs using BasitKargo bulk filter API
     * Processes orders month by month for efficiency
     */
    public function syncFromBasitKargoBulk(?string $sinceDate = null, ?\Closure $progressCallback = null): array
    {
        // Get active BasitKargo integration
        $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return [
                'success' => false,
                'error' => 'BasitKargo integration not found',
                'synced' => 0,
                'matched' => 0,
                'total_fetched' => 0,
            ];
        }

        $adapter = new BasitKargoAdapter($integration);

        // Default to last 6 months if no date specified
        $startDate = $sinceDate ? Carbon::parse($sinceDate)->startOfDay() : Carbon::now()->subMonths(6)->startOfDay();
        $endDate = Carbon::now()->endOfDay();

        $totalFetched = 0;
        $totalMatched = 0;
        $totalSynced = 0;

        // Process month by month
        $currentStart = $startDate->copy();

        while ($currentStart->lessThan($endDate)) {
            $currentEnd = $currentStart->copy()->endOfMonth();

            // Don't go beyond the end date
            if ($currentEnd->greaterThan($endDate)) {
                $currentEnd = $endDate->copy();
            }

            $monthStart = $currentStart->format('Y-m-d\TH:i:s');
            $monthEnd = $currentEnd->format('Y-m-d\TH:i:s');

            try {
                // Fetch orders from BasitKargo for this month
                $page = 0;
                $hasMore = true;

                while ($hasMore) {
                    $basitkargo_orders = $adapter->filterOrders(
                        startDate: $monthStart,
                        endDate: $monthEnd,
                        page: $page,
                        size: 100
                    );

                    $totalFetched += count($basitkargo_orders);

                    foreach ($basitkargo_orders as $basitkargo_order) {
                        $matched = $this->matchAndSyncOrder($basitkargo_order, $integration);

                        if ($matched) {
                            $totalMatched++;

                            if ($matched['synced']) {
                                $totalSynced++;
                            }
                        }
                    }

                    // Check if there are more pages
                    $hasMore = count($basitkargo_orders) === 100;
                    $page++;
                }
            } catch (\Exception $e) {
                activity()
                    ->withProperties([
                        'integration_id' => $integration->id,
                        'month_start' => $monthStart,
                        'month_end' => $monthEnd,
                        'error' => $e->getMessage(),
                    ])
                    ->log('basitkargo_bulk_sync_failed');
            }

            // Move to next month
            $currentStart->addMonth()->startOfMonth();
        }

        return [
            'success' => true,
            'synced' => $totalSynced,
            'matched' => $totalMatched,
            'total_fetched' => $totalFetched,
        ];
    }

    /**
     * Match BasitKargo order with local Shopify order and sync costs
     */
    protected function matchAndSyncOrder(array $basitkargo_order, Integration $integration): ?array
    {
        // Extract tracking number and order number
        $handlerShipmentCode = $basitkargo_order['shipmentInfo']['handlerShipmentCode'] ?? null;
        $orderNumber = $basitkargo_order['orderNumber'] ?? null;

        $order = null;

        // Try to match by order_number first
        if ($orderNumber) {
            $order = Order::where('channel', 'shopify')
                ->where('order_number', $orderNumber)
                ->first();
        }

        // Fallback to matching by tracking number
        if (! $order && $handlerShipmentCode) {
            $order = Order::where('channel', 'shopify')
                ->where('shipping_tracking_number', $handlerShipmentCode)
                ->first();
        }

        // No match found
        if (! $order) {
            return null;
        }

        // Skip if already has costs
        if ($order->shipping_cost_excluding_vat && $order->carrier) {
            return [
                'order_id' => $order->id,
                'synced' => false,
                'reason' => 'already_synced',
            ];
        }

        // Extract shipping cost data
        $price = $basitkargo_order['priceInfo']['totalCost'] ?? null;
        $desi = $basitkargo_order['content']['totalDesiKg'] ?? null;
        $handlerCode = $basitkargo_order['shipmentInfo']['handler']['code'] ?? null;

        if (! $price || ! $desi) {
            return [
                'order_id' => $order->id,
                'synced' => false,
                'reason' => 'missing_data',
            ];
        }

        // Convert price to minor units (cents)
        $priceInCents = (int) round($price * 100);

        // Calculate VAT
        $vatIncluded = $integration->settings['vat_included'] ?? true;
        $vatRate = 20.00;

        if ($vatIncluded) {
            // Price includes VAT - extract it
            $priceExcludingVat = (int) round($priceInCents / 1.20);
            $vatAmount = $priceInCents - $priceExcludingVat;
        } else {
            // Price excludes VAT - add it
            $priceExcludingVat = $priceInCents;
            $vatAmount = (int) round($priceExcludingVat * 0.20);
        }

        // Parse carrier
        $carrier = null;
        if ($handlerCode) {
            $carrier = $this->mapBasitKargoCodeToCarrier($handlerCode);
        }

        // Update order
        $order->update([
            'carrier' => $carrier?->value,
            'shipping_desi' => $desi,
            'shipping_cost_excluding_vat' => $priceExcludingVat,
            'shipping_vat_rate' => $vatRate,
            'shipping_vat_amount' => $vatAmount,
            'shipping_tracking_number' => $handlerShipmentCode ?? $order->shipping_tracking_number,
        ]);

        activity()
            ->performedOn($order)
            ->withProperties([
                'basitkargo_order_number' => $orderNumber,
                'tracking_number' => $handlerShipmentCode,
                'carrier' => $carrier?->value,
                'cost' => $priceExcludingVat,
                'desi' => $desi,
            ])
            ->log('basitkargo_bulk_shipping_cost_synced');

        return [
            'order_id' => $order->id,
            'synced' => true,
        ];
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
            'HEPSIJET' => ShippingCarrier::HEPSIJET,
            default => null,
        };
    }
}
