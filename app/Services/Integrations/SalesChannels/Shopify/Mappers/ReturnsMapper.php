<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Mappers;

use App\Enums\Order\OrderChannel;
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

            // Check if return already exists
            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $shopifyRefund['id'])
                ->where('entity_type', OrderReturn::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $return = $existingMapping->entity;
                $this->updateReturn($return, $shopifyRefund, $order);
            } else {
                // Clean up orphaned mapping
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $return = $this->createReturn($order, $shopifyRefund);
            }

            // Sync refund transactions
            $this->syncRefundTransactions($return, $shopifyRefund, $order);

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

    protected function createReturn(Order $order, array $shopifyRefund): OrderReturn
    {
        $currency = $order->currency;

        // Calculate total refund amount from refund line items
        $totalRefundAmount = 0;
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $totalRefundAmount += (float) ($lineItem['subtotal'] ?? 0);
        }

        // Determine status
        $status = $this->mapRefundStatus($shopifyRefund);

        // Get note
        $note = $shopifyRefund['note'] ?? null;

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
            'reason_code' => null,
            'reason_name' => $note ?? 'Shopify Refund',
            'customer_note' => $note,
            'internal_note' => null,
            'total_refund_amount' => $this->convertToMinorUnits($totalRefundAmount, $currency),
            'original_shipping_cost' => 0,
            'return_shipping_cost' => 0,
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

    protected function updateReturn(OrderReturn $return, array $shopifyRefund, Order $order): void
    {
        $currency = $order->currency;

        // Calculate total refund amount
        $totalRefundAmount = 0;
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $totalRefundAmount += (float) ($lineItem['subtotal'] ?? 0);
        }

        $status = $this->mapRefundStatus($shopifyRefund);
        $note = $shopifyRefund['note'] ?? null;

        $return->update([
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
        ]);

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
}
