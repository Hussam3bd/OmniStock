<?php

namespace App\Actions\Order;

use App\Enums\Order\OrderStatus;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\Order;

/**
 * Single source of truth for updating order return_status and order_status
 * based on completed/approved returns.
 *
 * Used by:
 * - OrderReturn model (when a return is completed/approved)
 * - Order sync/resync operations (Shopify, Trendyol mappers)
 * - Artisan commands (bulk status updates)
 */
class UpdateOrderReturnStatusAction
{
    /**
     * Update order's return_status and order_status based on return data
     *
     * @param  Order  $order  The order to update
     * @param  bool  $forceRecalculate  Force reload of items/returns even if already loaded
     * @param  bool  $dryRun  Calculate changes without saving to database
     * @return array{return_status: string|null, order_status: string, changed: bool} Status info and whether anything changed
     */
    public function execute(Order $order, bool $forceRecalculate = false, bool $dryRun = false): array
    {
        // Ensure order has items and returns loaded
        if ($forceRecalculate || ! $order->relationLoaded('items')) {
            $order->load('items');
        }

        if ($forceRecalculate || ! $order->relationLoaded('returns')) {
            $order->load('returns');
        }

        // Store current state
        $beforeReturnStatus = $order->return_status;
        $beforeOrderStatus = $order->order_status;

        // Calculate return quantities
        $totalItemsInOrder = $order->items->sum('quantity');
        $totalItemsReturned = $order->returns()
            ->whereIn('status', [ReturnStatus::Approved, ReturnStatus::Completed])
            ->get()
            ->flatMap->items
            ->sum('quantity');

        // Determine new statuses
        [$returnStatus, $orderStatus] = $this->calculateStatuses(
            $order,
            $totalItemsReturned,
            $totalItemsInOrder,
            $beforeOrderStatus
        );

        // Check if anything changed
        $changed = $beforeReturnStatus !== $returnStatus || $beforeOrderStatus->value !== $orderStatus->value;

        // Update if changed (only if not dry-run)
        if ($changed && ! $dryRun) {
            $updateData = [];

            if ($beforeReturnStatus !== $returnStatus) {
                $updateData['return_status'] = $returnStatus;
            }

            if ($beforeOrderStatus->value !== $orderStatus->value) {
                $updateData['order_status'] = $orderStatus;
            }

            if (! empty($updateData)) {
                $order->update($updateData);
            }
        }

        return [
            'return_status' => $returnStatus,
            'order_status' => $orderStatus->value,
            'changed' => $changed,
            'before' => [
                'return_status' => $beforeReturnStatus,
                'order_status' => $beforeOrderStatus->value,
            ],
        ];
    }

    /**
     * Calculate the appropriate return_status and order_status based on return quantities
     *
     * Business Logic:
     * - COD orders where customer refused delivery → REJECTED (never paid, no refund)
     * - Orders where customer paid then returned → REFUNDED (got money back)
     *
     * Detection:
     * - COD rejected = payment_method is 'cod' AND payment_status is 'failed' or 'pending'
     * - Customer paid = payment_status is 'paid', 'partially_paid', 'refunded', 'partially_refunded'
     *
     * @return array{0: string|null, 1: OrderStatus} [return_status, order_status]
     */
    protected function calculateStatuses(
        Order $order,
        int $totalItemsReturned,
        int $totalItemsInOrder,
        OrderStatus $currentOrderStatus
    ): array {
        if ($totalItemsReturned === 0) {
            // No returns - set return_status to 'none', keep current order_status
            return ['none', $currentOrderStatus];
        }

        // Detect COD rejected orders (customer refused delivery, never paid)
        $isCodRejected = $this->isCodRejectedOrder($order);

        if ($isCodRejected) {
            // COD delivery refused - customer never accepted or paid
            // These should be REJECTED, not REFUNDED (no payment = no refund)
            if ($totalItemsReturned >= $totalItemsInOrder) {
                return ['full', OrderStatus::REJECTED];
            } else {
                return ['partial', OrderStatus::REJECTED];
            }
        }

        // Normal case: Customer paid and then returned items
        if ($totalItemsReturned >= $totalItemsInOrder) {
            // Full return (100%) - all items returned, customer got full refund
            return ['full', OrderStatus::REFUNDED];
        }

        // Partial return - some items returned, customer got partial refund
        return ['partial', OrderStatus::PARTIALLY_REFUNDED];
    }

    /**
     * Detect if an order is a COD rejected order (customer refused delivery)
     *
     * Indicators:
     * - Payment method is COD
     * - Payment never succeeded (failed or pending)
     * - Has returns (product came back)
     */
    protected function isCodRejectedOrder(Order $order): bool
    {
        // Must be COD payment
        if ($order->payment_method !== 'cod') {
            return false;
        }

        // Payment must not have succeeded
        // If payment is 'paid', 'partially_paid', 'refunded', or 'partially_refunded', customer DID pay
        $paidStatuses = ['paid', 'partially_paid', 'refunded', 'partially_refunded'];

        if (in_array($order->payment_status->value, $paidStatuses)) {
            return false; // Customer paid, this is a normal return
        }

        // COD + payment failed/pending/voided = customer refused delivery
        return true;
    }
}
