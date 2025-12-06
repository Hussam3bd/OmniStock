<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Mappers;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Order\ReturnRefund;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\Concerns\BaseReturnsMapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReturnsMapper extends BaseReturnsMapper
{
    protected function getChannel(): OrderChannel
    {
        return OrderChannel::SHOPIFY;
    }

    /**
     * Map Shopify refund data to our OrderReturn system
     */
    public function mapReturn(array $shopifyRefund): ?OrderReturn
    {
        return DB::transaction(function () use ($shopifyRefund) {
            // Find the order this refund belongs to
            $order = $this->findOrderByShopifyId($shopifyRefund['order_id'] ?? null);

            if (! $order) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'shopify_order_id' => $shopifyRefund['order_id'] ?? null,
                    ])
                    ->log('shopify_refund_order_not_found');

                return null;
            }

            // Skip cancelled orders - these are not returns
            if ($order->order_status === OrderStatus::CANCELLED) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'order_number' => $order->order_number,
                        'reason' => 'order_cancelled',
                    ])
                    ->log('shopify_refund_skipped');

                return null;
            }

            // Skip if this is a void transaction (order cancellation, not a return)
            if ($this->isVoidTransaction($shopifyRefund)) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'order_number' => $order->order_number,
                        'reason' => 'void_transaction',
                    ])
                    ->log('shopify_refund_skipped');

                return null;
            }

            // For COD orders, allow refunds even with failed payment status
            // COD orders that are rejected at delivery will have failed payment
            $isCOD = strtolower($order->payment_method ?? '') === 'cod';

            // Skip refunds for orders that were never paid (except COD)
            if (! $isCOD && in_array($order->payment_status, [PaymentStatus::FAILED, PaymentStatus::VOIDED])) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'order_number' => $order->order_number,
                        'payment_status' => $order->payment_status->value,
                        'reason' => 'payment_not_completed',
                    ])
                    ->log('shopify_refund_skipped');

                return null;
            }

            // Skip if no actual refund line items (price adjustment or fee refund)
            if (empty($shopifyRefund['refund_line_items'])) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'order_number' => $order->order_number,
                        'reason' => 'no_refund_line_items',
                    ])
                    ->log('shopify_refund_skipped');

                return null;
            }

            // Skip order edits/modifications (restock_type = 'cancel' means item removed, not returned)
            if ($this->isOrderEdit($shopifyRefund)) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'order_number' => $order->order_number,
                        'reason' => 'order_edit_not_return',
                    ])
                    ->log('shopify_refund_skipped');

                return null;
            }

            // For COD orders, allow refunds even without transactions (rejected deliveries)
            // Skip if no refund transactions (no money actually refunded) - except for COD
            if (! $isCOD && $this->hasNoRefundTransactions($shopifyRefund)) {
                activity()
                    ->withProperties([
                        'shopify_refund_id' => $shopifyRefund['id'] ?? null,
                        'order_number' => $order->order_number,
                        'reason' => 'no_refund_transactions',
                    ])
                    ->log('shopify_refund_skipped');

                return null;
            }

            // Check if return already exists
            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $shopifyRefund['id'])
                ->where('entity_type', OrderReturn::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $return = $existingMapping->entity;
                $this->updateReturn($return, $shopifyRefund, $order, $isCOD);
            } else {
                // Clean up orphaned mapping
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $return = $this->createReturn($order, $shopifyRefund, $isCOD);
            }

            // Sync refund transactions (only if there are transactions)
            $this->syncRefundTransactions($return, $shopifyRefund, $order);

            // Update order status for COD rejected deliveries
            if ($isCOD && $this->hasNoRefundTransactions($shopifyRefund)) {
                $order->update(['order_status' => OrderStatus::REJECTED]);

                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'return_id' => $return->id,
                        'order_number' => $order->order_number,
                        'reason' => 'cod_delivery_rejected',
                    ])
                    ->log('order_status_updated_to_rejected');

                // Update shipping costs breakdown from BasitKargo using centralized service
                $this->syncShippingCostsForRejectedDelivery($order, $return);
            }

            return $return->fresh();
        });
    }

    protected function findOrderByShopifyId(?string $shopifyOrderId): ?Order
    {
        if (! $shopifyOrderId) {
            return null;
        }

        $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
            ->where('platform_id', (string) $shopifyOrderId)
            ->where('entity_type', Order::class)
            ->first();

        return $mapping?->entity;
    }

    protected function createReturn(Order $order, array $shopifyRefund, bool $isCOD = false): OrderReturn
    {
        $currency = $order->currency;

        // Calculate total refund amount from refund line items
        $totalRefundAmount = 0;
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $totalRefundAmount += (float) ($lineItem['subtotal'] ?? 0);
        }

        // For COD rejected deliveries with no transactions, check if items were restocked
        $isRejectedDelivery = $isCOD && $this->hasNoRefundTransactions($shopifyRefund);

        // Determine status
        if ($isRejectedDelivery && ($shopifyRefund['restock'] ?? false)) {
            // COD rejected delivery with items restocked = Completed
            $status = ReturnStatus::Completed;
        } else {
            $status = $this->mapRefundStatus($shopifyRefund);
        }

        // Get note
        $note = $shopifyRefund['note'] ?? null;
        if ($isRejectedDelivery && ! $note) {
            $note = 'COD delivery rejected by customer';
        }

        // Calculate original shipping cost from order (total cost including VAT)
        $originalShippingCost = $order->total_shipping_cost?->getAmount() ?? 0;

        // Calculate shipping refund amount (if any)
        $shippingRefundAmount = 0;
        foreach ($shopifyRefund['refund_shipping_lines'] ?? [] as $shippingLine) {
            $shippingRefundAmount += (float) ($shippingLine['subtotal_amount_set']['shop_money']['amount'] ?? 0);
        }

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'channel' => $this->getChannel(),
            'external_return_id' => (string) $shopifyRefund['id'],
            'status' => $status,
            'requested_at' => isset($shopifyRefund['created_at'])
                ? Carbon::parse($shopifyRefund['created_at'])
                : now(),
            'approved_at' => isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : null,
            'completed_at' => $status === ReturnStatus::Completed && isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : null,
            'received_at' => ($isRejectedDelivery && isset($shopifyRefund['processed_at']))
                ? Carbon::parse($shopifyRefund['processed_at'])
                : null,
            'reason_code' => $isRejectedDelivery ? 'cod_rejected' : null,
            'reason_name' => $note ?? 'Shopify Refund',
            'customer_note' => $note,
            'internal_note' => $isRejectedDelivery ? 'COD delivery rejected - items returned by shipping company' : null,
            'total_refund_amount' => $this->convertToMinorUnits($totalRefundAmount, $currency),
            'original_shipping_cost' => $originalShippingCost,
            'return_shipping_cost_excluding_vat' => $originalShippingCost, // Loss = outbound shipping cost (will be updated by BasitKargo sync)
            // For COD rejected deliveries, same shipment came back - copy tracking details
            'return_shipping_carrier' => $isRejectedDelivery ? $order->shipping_carrier : null,
            'return_tracking_number' => $isRejectedDelivery ? $order->shipping_tracking_number : null,
            'return_tracking_url' => $isRejectedDelivery ? $order->shipping_tracking_url : null,
            'restocking_fee' => $this->convertToMinorUnits((float) ($shopifyRefund['restock'] ?? 0), $currency),
            'currency' => $currency,
            'platform_data' => $shopifyRefund,
        ]);

        // Create platform mapping
        PlatformMapping::create([
            'platform' => $this->getChannel()->value,
            'platform_id' => (string) $shopifyRefund['id'],
            'entity_type' => OrderReturn::class,
            'entity_id' => $return->id,
            'platform_data' => $shopifyRefund,
            'last_synced_at' => now(),
        ]);

        // Sync return items
        $this->syncReturnItems($return, $shopifyRefund, $order);

        return $return;
    }

    protected function updateReturn(OrderReturn $return, array $shopifyRefund, Order $order, bool $isCOD = false): void
    {
        $currency = $order->currency;

        // Calculate total refund amount
        $totalRefundAmount = 0;
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $totalRefundAmount += (float) ($lineItem['subtotal'] ?? 0);
        }

        // For COD rejected deliveries with no transactions, check if items were restocked
        $isRejectedDelivery = $isCOD && $this->hasNoRefundTransactions($shopifyRefund);

        // Determine status
        if ($isRejectedDelivery && ($shopifyRefund['restock'] ?? false)) {
            // COD rejected delivery with items restocked = Completed
            $status = ReturnStatus::Completed;
        } else {
            $status = $this->mapRefundStatus($shopifyRefund);
        }

        $note = $shopifyRefund['note'] ?? null;
        if ($isRejectedDelivery && ! $note) {
            $note = 'COD delivery rejected by customer';
        }

        $updateData = [
            'status' => $status,
            'approved_at' => isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : $return->approved_at,
            'completed_at' => $status === ReturnStatus::Completed && isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : $return->completed_at,
            'reason_name' => $note ?? $return->reason_name,
            'customer_note' => $note ?? $return->customer_note,
            'total_refund_amount' => $this->convertToMinorUnits($totalRefundAmount, $currency),
            'restocking_fee' => $this->convertToMinorUnits((float) ($shopifyRefund['restock'] ?? 0), $currency),
            'platform_data' => $shopifyRefund,
        ];

        // Add COD-specific fields
        if ($isRejectedDelivery) {
            $updateData['received_at'] = isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : $return->received_at;
            $updateData['reason_code'] = 'cod_rejected';
            $updateData['internal_note'] = 'COD delivery rejected - items returned by shipping company';

            // Calculate original shipping cost from order if not already set
            if (! $return->original_shipping_cost || $return->original_shipping_cost === 0) {
                $originalShippingCost = $order->total_shipping_cost?->getAmount() ?? 0;
                $updateData['original_shipping_cost'] = $originalShippingCost;
                $updateData['return_shipping_cost_excluding_vat'] = $originalShippingCost;
            }

            // For COD rejected deliveries, same shipment came back - copy tracking details
            $updateData['return_shipping_carrier'] = $order->shipping_carrier;
            $updateData['return_tracking_number'] = $order->shipping_tracking_number;
            $updateData['return_tracking_url'] = $order->shipping_tracking_url;
        }

        $return->update($updateData);

        // Update platform mapping
        $return->platformMappings()
            ->where('platform', $this->getChannel()->value)
            ->update([
                'platform_data' => $shopifyRefund,
                'last_synced_at' => now(),
            ]);

        // Sync return items
        $this->syncReturnItems($return, $shopifyRefund, $order);
    }

    protected function syncReturnItems(OrderReturn $return, array $shopifyRefund, Order $order): void
    {
        // Get the original Shopify order data from platform mapping
        $orderPlatformData = $order->platformMappings()
            ->where('platform', $this->getChannel()->value)
            ->first()?->platform_data;

        if (! $orderPlatformData) {
            return;
        }

        $shopifyLineItems = $orderPlatformData['line_items'] ?? [];

        foreach ($shopifyRefund['refund_line_items'] ?? [] as $refundLineItem) {
            $lineItemId = $refundLineItem['line_item_id'] ?? null;

            if (! $lineItemId) {
                continue;
            }

            // Find the original line item in Shopify order data
            $shopifyLineItem = collect($shopifyLineItems)->firstWhere('id', $lineItemId);

            if (! $shopifyLineItem) {
                continue;
            }

            // Find the order item by variant
            $variantId = $shopifyLineItem['variant_id'] ?? null;
            if (! $variantId) {
                continue;
            }

            // Find the variant in our system
            $variant = \App\Models\Platform\PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $variantId)
                ->where('entity_type', \App\Models\Product\ProductVariant::class)
                ->first()?->entity;

            if (! $variant) {
                continue;
            }

            // Find the order item with this variant
            $orderItem = $order->items()->where('product_variant_id', $variant->id)->first();

            if (! $orderItem) {
                continue;
            }

            $quantity = (int) ($refundLineItem['quantity'] ?? 1);
            $refundAmount = $this->convertToMinorUnits(
                (float) ($refundLineItem['subtotal'] ?? 0),
                $order->currency
            );

            $return->items()->updateOrCreate(
                [
                    'order_item_id' => $orderItem->id,
                ],
                [
                    'quantity' => $quantity,
                    'refund_amount' => $refundAmount,
                    'reason_name' => $shopifyRefund['note'] ?? 'Refunded via Shopify',
                    'external_item_id' => (string) ($refundLineItem['id'] ?? null),
                    'platform_data' => $refundLineItem,
                ]
            );
        }
    }

    protected function syncRefundTransactions(OrderReturn $return, array $shopifyRefund, Order $order): void
    {
        foreach ($shopifyRefund['transactions'] ?? [] as $transaction) {
            // Only process refund transactions
            if (($transaction['kind'] ?? null) !== 'refund') {
                continue;
            }

            $externalRefundId = (string) ($transaction['id'] ?? null);

            if (! $externalRefundId) {
                continue;
            }

            // Check if refund already exists
            $existingRefund = $return->refunds()
                ->where('external_refund_id', $externalRefundId)
                ->first();

            $status = $this->mapTransactionStatus($transaction['status'] ?? 'pending');
            $amount = $this->convertToMinorUnits(
                (float) ($transaction['amount'] ?? 0),
                $order->currency
            );

            $refundData = [
                'amount' => $amount,
                'currency' => $order->currency,
                'method' => $this->mapPaymentMethod($transaction['gateway'] ?? null),
                'status' => $status,
                'payment_gateway' => $transaction['gateway'] ?? null,
                'initiated_at' => isset($transaction['created_at'])
                    ? Carbon::parse($transaction['created_at'])
                    : now(),
                'processed_at' => isset($transaction['processed_at'])
                    ? Carbon::parse($transaction['processed_at'])
                    : null,
                'completed_at' => $status === 'completed' && isset($transaction['processed_at'])
                    ? Carbon::parse($transaction['processed_at'])
                    : null,
                'platform_data' => $transaction,
            ];

            if ($existingRefund) {
                $existingRefund->update($refundData);
            } else {
                ReturnRefund::create(array_merge($refundData, [
                    'return_id' => $return->id,
                    'external_refund_id' => $externalRefundId,
                ]));
            }
        }
    }

    protected function mapRefundStatus(array $shopifyRefund): ReturnStatus
    {
        // Shopify doesn't have a clear "return" status, but we can infer from refund state
        // If all transactions are successful, consider it completed
        $allTransactionsSuccessful = true;
        $hasTransactions = false;

        foreach ($shopifyRefund['transactions'] ?? [] as $transaction) {
            if (($transaction['kind'] ?? null) === 'refund') {
                $hasTransactions = true;
                if (($transaction['status'] ?? 'pending') !== 'success') {
                    $allTransactionsSuccessful = false;
                }
            }
        }

        if ($hasTransactions && $allTransactionsSuccessful) {
            return ReturnStatus::Completed;
        }

        if ($hasTransactions) {
            return ReturnStatus::Approved; // Refund in progress
        }

        return ReturnStatus::Requested;
    }

    protected function mapTransactionStatus(string $shopifyStatus): string
    {
        return match (strtolower($shopifyStatus)) {
            'success' => 'completed',
            'pending' => 'pending',
            'failure', 'error' => 'failed',
            default => 'pending',
        };
    }

    protected function mapPaymentMethod(?string $gateway): string
    {
        if (! $gateway) {
            return 'original_payment_method';
        }

        return match (true) {
            str_contains(strtolower($gateway), 'bank') => 'bank_transfer',
            str_contains(strtolower($gateway), 'credit') => 'credit_card',
            str_contains(strtolower($gateway), 'cash') => 'cash',
            default => 'original_payment_method',
        };
    }

    /**
     * Check if this refund is a void transaction (order cancellation)
     */
    protected function isVoidTransaction(array $shopifyRefund): bool
    {
        foreach ($shopifyRefund['transactions'] ?? [] as $transaction) {
            if (($transaction['kind'] ?? null) === 'void') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if this is an order edit (item removed/modified, not a return)
     * In Shopify, restock_type = 'cancel' means item was removed from order
     */
    protected function isOrderEdit(array $shopifyRefund): bool
    {
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $restockType = $lineItem['restock_type'] ?? null;

            // If any item has restock_type 'cancel', it's an order edit
            if ($restockType === 'cancel') {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if there are no actual refund transactions
     * If transactions array is empty or has no 'refund' kind, no money was refunded
     */
    protected function hasNoRefundTransactions(array $shopifyRefund): bool
    {
        $transactions = $shopifyRefund['transactions'] ?? [];

        if (empty($transactions)) {
            return true;
        }

        // Check if there's at least one refund transaction
        foreach ($transactions as $transaction) {
            if (($transaction['kind'] ?? null) === 'refund') {
                return false;
            }
        }

        return true;
    }

    /**
     * Sync shipping costs for rejected delivery using centralized service
     * This uses ShippingCostSyncService to avoid duplicate logic
     */
    protected function syncShippingCostsForRejectedDelivery(Order $order, OrderReturn $return): void
    {
        // Check if order has tracking number
        if (! $order->shipping_tracking_number) {
            return;
        }

        try {
            // Get BasitKargo integration
            $integration = \App\Models\Integration\Integration::where('provider', 'basit_kargo')
                ->where('is_active', true)
                ->first();

            if (! $integration) {
                return;
            }

            // Use centralized service for shipping cost sync
            $service = app(\App\Services\Shipping\ShippingCostSyncService::class);
            $service->syncShippingCostWithBreakdown($order, $integration, force: true, return: $return);
        } catch (\Exception $e) {
            // Log error but don't fail the entire sync
            activity()
                ->performedOn($order)
                ->withProperties([
                    'order_number' => $order->order_number,
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_shipping_costs_update_failed');
        }
    }
}
