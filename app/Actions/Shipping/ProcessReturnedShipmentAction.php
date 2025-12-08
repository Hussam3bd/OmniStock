<?php

namespace App\Actions\Shipping;

use App\Enums\Order\OrderStatus;
use App\Enums\Order\ReturnReason;
use App\Enums\Order\ReturnStatus;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;

class ProcessReturnedShipmentAction
{
    /**
     * Auto-create OrderReturn for returned shipments
     * Handles both COD and non-COD returns with different statuses
     */
    public function execute(
        Order $order,
        array $shipmentData,
        Integration $integration
    ): ?OrderReturn {
        // Check if return already exists (idempotency)
        $existingReturn = OrderReturn::where('order_id', $order->id)
            ->whereIn('status', [
                ReturnStatus::Requested,
                ReturnStatus::PendingReview,
                ReturnStatus::Approved,
                ReturnStatus::Received,
                ReturnStatus::Completed,
            ])
            ->first();

        if ($existingReturn) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'order_number' => $order->order_number,
                    'tracking_number' => $order->shipping_tracking_number,
                    'existing_return_id' => $existingReturn->id,
                ])
                ->log('shipment_return_already_exists');

            return $existingReturn;
        }

        // Determine if COD
        $isCOD = strtolower($order->payment_method ?? '') === 'cod';

        // Set status, reason, and order status based on payment method
        if ($isCOD) {
            $status = ReturnStatus::Received; // Already physically returned, no label needed
            $reason = ReturnReason::COD_REJECTED;
            $reasonText = __('Customer did not accept COD delivery');
            $orderStatus = OrderStatus::REJECTED; // COD rejection at delivery
        } else {
            $status = ReturnStatus::Received; // Auto-received for non-COD
            $reason = ReturnReason::RETURNED_BY_CARRIER;
            $reasonText = __('Shipment returned by carrier');
            $orderStatus = OrderStatus::RETURNED; // Post-delivery return
        }

        // Extract tracking info
        $trackingNumber = $order->shipping_tracking_number;
        $shipmentId = $shipmentData['raw_data']['id'] ?? null;
        $handlerName = $shipmentData['raw_data']['shipmentInfo']['handler']['name'] ?? null;

        // Parse carrier from handler name
        $returnCarrier = null;
        if ($handlerName) {
            $returnCarrier = \App\Enums\Shipping\ShippingCarrier::fromString($handlerName);
        }
        // Fallback to order's carrier if parsing fails
        if (! $returnCarrier && $order->shipping_carrier) {
            $returnCarrier = $order->shipping_carrier;
        }

        // Get delivery attempt time from traces
        $receivedAt = null;
        if (isset($shipmentData['raw_data']['traces']) && is_array($shipmentData['raw_data']['traces'])) {
            // Find the RETURNED trace entry
            foreach ($shipmentData['raw_data']['traces'] as $trace) {
                if (str_contains(strtolower($trace['status'] ?? ''), 'geri dön') ||
                    str_contains(strtolower($trace['status'] ?? ''), 'iade')) {
                    $receivedAt = isset($trace['time']) ? \Carbon\Carbon::parse($trace['time']) : null;
                    break;
                }
            }
        }

        // Calculate original shipping cost
        $originalShippingCost = $order->total_shipping_cost?->getAmount() ?? 0;

        // Create return request
        $return = OrderReturn::create([
            'order_id' => $order->id,
            'channel' => $order->channel,
            'external_return_id' => $shipmentId,
            'status' => $status,
            'return_reason' => $reason->value,
            'reason_name' => $reasonText,
            'requested_at' => now(),
            'received_at' => $receivedAt ?? now(), // Already physically returned
            'customer_note' => $isCOD
                ? __('Customer refused to accept COD delivery')
                : __('Shipment could not be delivered and was returned'),
            'internal_note' => __('Automatic return created from BasitKargo webhook. Shipment returned by :handler', [
                'handler' => $handlerName ?? 'shipping carrier',
            ]),
            // Copy outbound shipping details since same shipment returned
            'return_shipping_carrier' => $returnCarrier?->value,
            'return_tracking_number' => $trackingNumber,
            'return_shipping_aggregator_integration_id' => $integration->id,
            'return_shipping_aggregator_shipment_id' => $shipmentId,
            'return_shipping_aggregator_data' => $shipmentData['raw_data'] ?? [],
            // Shipping costs
            'original_shipping_cost' => $originalShippingCost,
            'currency' => $order->currency,
            'platform_data' => [
                'auto_created' => true,
                'source' => 'basitkargo_webhook',
                'is_cod' => $isCOD,
                'shipment_data' => $shipmentData,
            ],
        ]);

        // Copy all order items to return items with full quantities
        foreach ($order->items as $orderItem) {
            $return->items()->create([
                'order_item_id' => $orderItem->id,
                'quantity' => $orderItem->quantity,
                'refund_amount' => $orderItem->total_price, // Full refund
                'reason_name' => $reasonText,
            ]);
        }

        // Update order status
        $order->update(['order_status' => $orderStatus]);

        activity()
            ->performedOn($return)
            ->withProperties([
                'integration_id' => $integration->id,
                'order_number' => $order->order_number,
                'tracking_number' => $trackingNumber,
                'is_cod' => $isCOD,
                'status' => $status->value,
                'reason' => $reason->value,
                'order_status' => $orderStatus->value,
                'auto_created' => true,
            ])
            ->log('shipment_return_auto_created');

        return $return;
    }
}
