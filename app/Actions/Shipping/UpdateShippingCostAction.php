<?php

namespace App\Actions\Shipping;

use App\Models\Integration\Integration;
use App\Models\Order\Order;

class UpdateShippingCostAction
{
    /**
     * Update shipping cost data from BasitKargo
     * IMPORTANT: This DOES NOT touch shipping_amount (customer-charged amount)
     */
    public function execute(Order $order, array $shipmentData, Integration $integration): bool
    {
        // Extract price info
        $priceInfo = $shipmentData['raw_data']['priceInfo'] ?? null;
        if (! $priceInfo) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'reason' => 'price_info_missing',
                ])
                ->log('shipping_cost_update_skipped');

            return false;
        }

        $outboundCost = (float) ($priceInfo['shipmentFee'] ?? 0); // Kargo Ücreti (outbound only)
        $totalCost = (float) ($priceInfo['totalCost'] ?? 0); // Total (outbound + return if rejected)
        $returnCost = $totalCost - $outboundCost; // Kargo İade Ücreti (return only)
        $isReturned = $shipmentData['is_returned'] ?? false;

        // Determine if this is a COD rejected delivery
        $isRejectedDelivery = $isReturned && $order->order_status === \App\Enums\Order\OrderStatus::REJECTED;

        // For rejected deliveries, use outbound cost only
        // For normal orders, use total cost
        $orderShippingCost = $isRejectedDelivery ? $outboundCost : $totalCost;

        // Convert to minor units
        $orderShippingCostMinor = (int) round($orderShippingCost * 100);

        // VAT calculation
        $vatIncluded = $integration->settings['vat_included'] ?? true;
        $vatRate = 20.00;

        if ($vatIncluded) {
            // Price includes VAT - extract it
            $priceExcludingVat = (int) round($orderShippingCostMinor / 1.20);
            $vatAmount = $orderShippingCostMinor - $priceExcludingVat;
        } else {
            // Price excludes VAT - add it
            $priceExcludingVat = $orderShippingCostMinor;
            $vatAmount = (int) round($orderShippingCostMinor * 0.20);
        }

        // Update order with shipping cost data
        // CRITICAL: We do NOT update shipping_amount here - that's what customer was charged
        $order->update([
            'shipping_cost_excluding_vat' => $priceExcludingVat,
            'shipping_vat_rate' => $vatRate,
            'shipping_vat_amount' => $vatAmount,
            // shipping_amount is INTENTIONALLY not included - it must be preserved
        ]);

        activity()
            ->performedOn($order)
            ->withProperties([
                'tracking_number' => $order->shipping_tracking_number,
                'is_rejected_delivery' => $isRejectedDelivery,
                'outbound_cost' => $outboundCost,
                'return_cost' => $returnCost,
                'total_cost' => $totalCost,
                'order_shipping_cost' => $orderShippingCost,
                'cost_excluding_vat' => $priceExcludingVat,
                'vat_amount' => $vatAmount,
                'shipping_amount_preserved' => true, // Explicitly log that we preserved it
            ])
            ->log('shipping_cost_updated');

        return true;
    }
}
