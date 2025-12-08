<?php

namespace App\Actions\Shipping;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentMethod;
use App\Enums\Order\PaymentStatus;
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
        $isCOD = $order->payment_method == PaymentMethod::COD;

        // For COD rejections: cancel the order at the channel instead of creating a return
        // Customer never received the order, so it's a cancellation not a return
        if ($isCOD) {
            // Update order status
            $order->update([
                'order_status' => OrderStatus::REJECTED,
                'fulfillment_status' => FulfillmentStatus::RETURNED,
                'payment_status' => PaymentStatus::VOIDED,
            ]);

            // Cancel order at sales channel (Shopify/Trendyol)
            // Get integration by channel if order doesn't have direct integration
            $channelIntegration = $order->integration ?? Integration::where('provider', $order->channel->value)
                ->where('type', 'sales_channel')
                ->first();

            if ($channelIntegration) {
                $adapter = $channelIntegration->adapter();
                if ($adapter) {
                    try {
                        $adapter->cancelOrder($order, __('Customer refused COD delivery'));
                    } catch (\Exception $e) {
                        activity()
                            ->performedOn($order)
                            ->withProperties([
                                'error' => $e->getMessage(),
                            ])
                            ->log('order_cancellation_failed');
                    }
                }
            }

            activity()
                ->performedOn($order)
                ->withProperties([
                    'integration_id' => $integration->id,
                    'order_number' => $order->order_number,
                    'tracking_number' => $order->shipping_tracking_number,
                    'reason' => 'cod_rejected',
                    'auto_cancelled' => true,
                ])
                ->log('order_auto_cancelled_cod_rejection');

            return null; // No return created for COD rejections
        }

        // For non-COD: create actual return (customer already received and paid)
        $status = ReturnStatus::Received; // Already physically returned, no label needed
        $reason = ReturnReason::RETURNED_BY_CARRIER;
        $reasonText = __('Shipment returned by carrier');
        $orderStatus = OrderStatus::RETURNED; // Post-delivery return

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
            'customer_note' => __('Shipment could not be delivered and was returned'),
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
                'is_cod' => false, // This path is only for non-COD
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

        // Update order status for non-COD returns
        $order->update([
            'order_status' => $orderStatus,
            'fulfillment_status' => FulfillmentStatus::RETURNED,
            // Payment status remains as is (customer already paid, refund handled separately)
        ]);

        activity()
            ->performedOn($return)
            ->withProperties([
                'integration_id' => $integration->id,
                'order_number' => $order->order_number,
                'tracking_number' => $trackingNumber,
                'is_cod' => false,
                'status' => $status->value,
                'reason' => $reason->value,
                'order_status' => $orderStatus->value,
                'auto_created' => true,
            ])
            ->log('shipment_return_auto_created');

        return $return;
    }
}
