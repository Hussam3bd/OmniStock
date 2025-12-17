<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Mappers;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentGateway;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ReturnStatus;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Order\ReturnRefund;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\Concerns\BaseReturnsMapper;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use App\Services\Shipping\ShippingCostService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ShopifyRefundMapper extends BaseReturnsMapper
{
    public function __construct(
        protected ShippingCostService $shippingCostService
    ) {}

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

            // Check if return already exists by refund ID
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

                // Check if this refund is linked to a return request
                // Shopify refunds have a 'return' field with the return ID when created from a return
                $linkedReturnId = $shopifyRefund['return']['id'] ?? null;
                $existingLinkedReturn = null;

                if ($linkedReturnId) {
                    // Find the return by the linked return ID
                    $linkedReturnMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                        ->where('platform_id', (string) $linkedReturnId)
                        ->where('entity_type', OrderReturn::class)
                        ->first();

                    if ($linkedReturnMapping && $linkedReturnMapping->entity) {
                        $existingLinkedReturn = $linkedReturnMapping->entity;
                    } elseif ($linkedReturnMapping) {
                        // Clean up orphaned mapping (entity was deleted)
                        $linkedReturnMapping->delete();
                    }
                }

                // If no linked return found, check if the order has any return
                // This handles cases where refund webhook arrives before return webhook
                if (! $existingLinkedReturn) {
                    $existingLinkedReturn = $order->returns()
                        ->where('channel', $this->getChannel())
                        ->first();
                }

                if ($existingLinkedReturn) {
                    // Update existing return with refund data
                    $return = $existingLinkedReturn;
                    $this->updateReturn($return, $shopifyRefund, $order, $isCOD);

                    // Update existing platform mapping for this return
                    // Use entity_id in WHERE clause to update existing mapping (changes platform_id from return ID to refund ID)
                    PlatformMapping::updateOrCreate(
                        [
                            'platform' => $this->getChannel()->value,
                            'entity_type' => OrderReturn::class,
                            'entity_id' => $return->id,
                        ],
                        [
                            'platform_id' => (string) $shopifyRefund['id'],
                            'platform_data' => $shopifyRefund,
                            'last_synced_at' => now(),
                        ]
                    );
                } else {
                    $return = $this->createReturn($order, $shopifyRefund, $isCOD);
                }
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

        // Calculate actual refund amount from transactions (what customer received)
        $actualRefundAmount = 0;
        foreach ($shopifyRefund['transactions'] ?? [] as $transaction) {
            if (($transaction['kind'] ?? null) === 'refund' && ($transaction['status'] ?? null) === 'success') {
                $actualRefundAmount += (float) ($transaction['amount'] ?? 0);
            }
        }

        // Calculate item value from refund line items (original value)
        $itemsValue = 0;
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $itemsValue += (float) ($lineItem['subtotal'] ?? 0);
        }

        // Calculate restocking fee (deducted from refund)
        // This includes return shipping costs deducted by Shopify
        $restockingFee = $itemsValue - $actualRefundAmount;

        // For COD rejected deliveries with no transactions, check if items were restocked
        $isRejectedDelivery = $isCOD && $this->hasNoRefundTransactions($shopifyRefund);

        // Determine status
        if ($isRejectedDelivery && ($shopifyRefund['restock'] ?? false)) {
            // COD rejected delivery with items restocked = Completed
            $status = ReturnStatus::Completed;
        } else {
            $status = $this->mapRefundStatus($shopifyRefund);
        }

        // Get merchant's internal note from refund (NOT the customer's return reason)
        $merchantNote = $shopifyRefund['note'] ?? null;

        // Calculate original shipping cost from order (total cost including VAT)
        $originalShippingCost = $order->total_shipping_cost?->getAmount() ?? 0;

        // Calculate return shipping costs based on carrier and desi
        $returnShippingCosts = $this->calculateReturnShippingCosts($order);

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
            'reason_name' => $isRejectedDelivery ? 'COD delivery rejected by customer' : 'Shopify Refund',
            'customer_note' => null,
            'internal_note' => $isRejectedDelivery ? 'COD delivery rejected - items returned by shipping company' : $merchantNote,
            'total_refund_amount' => $this->convertToMinorUnits($actualRefundAmount, $currency),
            'original_shipping_cost' => $originalShippingCost,
            'return_shipping_cost' => $returnShippingCosts['return_shipping_cost'],
            'return_shipping_vat_rate' => $returnShippingCosts['return_shipping_vat_rate'],
            'return_shipping_vat_amount' => $returnShippingCosts['return_shipping_vat_amount'],
            'return_shipping_rate_id' => $returnShippingCosts['return_shipping_rate_id'],
            // For COD rejected deliveries, same shipment came back - copy tracking details
            'return_shipping_carrier' => $isRejectedDelivery ? $order->shipping_carrier : $returnShippingCosts['carrier'],
            'return_tracking_number' => $isRejectedDelivery ? $order->shipping_tracking_number : null,
            'return_tracking_url' => $isRejectedDelivery ? $order->shipping_tracking_url : null,
            'restocking_fee' => $this->convertToMinorUnits($restockingFee, $currency),
            'currency' => $currency,
            'platform_data' => $shopifyRefund,
        ]);

        // Create or update platform mapping
        // Use entity_id in WHERE clause to update existing mapping if return was created by ReturnRequestMapper
        PlatformMapping::updateOrCreate(
            [
                'platform' => $this->getChannel()->value,
                'entity_type' => OrderReturn::class,
                'entity_id' => $return->id,
            ],
            [
                'platform_id' => (string) $shopifyRefund['id'],
                'platform_data' => $shopifyRefund,
                'last_synced_at' => now(),
            ]
        );

        // Sync return items
        $this->syncReturnItems($return, $shopifyRefund, $order);

        return $return;
    }

    protected function updateReturn(OrderReturn $return, array $shopifyRefund, Order $order, bool $isCOD = false): void
    {
        $currency = $order->currency;

        // Calculate actual refund amount from transactions (what customer received)
        $actualRefundAmount = 0;
        foreach ($shopifyRefund['transactions'] ?? [] as $transaction) {
            if (($transaction['kind'] ?? null) === 'refund' && ($transaction['status'] ?? null) === 'success') {
                $actualRefundAmount += (float) ($transaction['amount'] ?? 0);
            }
        }

        // Calculate item value from refund line items (original value)
        $itemsValue = 0;
        foreach ($shopifyRefund['refund_line_items'] ?? [] as $lineItem) {
            $itemsValue += (float) ($lineItem['subtotal'] ?? 0);
        }

        // Calculate restocking fee (deducted from refund)
        // This includes return shipping costs deducted by Shopify
        $restockingFee = $itemsValue - $actualRefundAmount;

        // For COD rejected deliveries with no transactions, check if items were restocked
        $isRejectedDelivery = $isCOD && $this->hasNoRefundTransactions($shopifyRefund);

        // Determine status
        if ($isRejectedDelivery && ($shopifyRefund['restock'] ?? false)) {
            // COD rejected delivery with items restocked = Completed
            $status = ReturnStatus::Completed;
        } else {
            $status = $this->mapRefundStatus($shopifyRefund);
        }

        // Get merchant's internal note from refund (NOT the customer's return reason)
        $merchantNote = $shopifyRefund['note'] ?? null;

        $updateData = [
            'status' => $status,
            'approved_at' => isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : $return->approved_at,
            'completed_at' => $status === ReturnStatus::Completed && isset($shopifyRefund['processed_at'])
                ? Carbon::parse($shopifyRefund['processed_at'])
                : $return->completed_at,
            // Preserve existing reason_name and customer_note (set by ReturnRequestMapper)
            // Only update if currently empty
            'reason_name' => $return->reason_name ?: ($isRejectedDelivery ? 'COD delivery rejected by customer' : 'Shopify Refund'),
            'customer_note' => $return->customer_note,
            'total_refund_amount' => $this->convertToMinorUnits($actualRefundAmount, $currency),
            'restocking_fee' => $this->convertToMinorUnits($restockingFee, $currency),
            'platform_data' => $shopifyRefund,
        ];

        // Calculate and update return shipping costs if not already set or for updates
        if (! $return->return_shipping_cost || $return->return_shipping_cost === 0) {
            $returnShippingCosts = $this->calculateReturnShippingCosts($order);
            $updateData['return_shipping_carrier'] = $returnShippingCosts['carrier'];
            $updateData['return_shipping_cost'] = $returnShippingCosts['return_shipping_cost'];
            $updateData['return_shipping_vat_rate'] = $returnShippingCosts['return_shipping_vat_rate'];
            $updateData['return_shipping_vat_amount'] = $returnShippingCosts['return_shipping_vat_amount'];
            $updateData['return_shipping_rate_id'] = $returnShippingCosts['return_shipping_rate_id'];
        }

        // Add COD-specific fields or merchant note
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
            }

            // For COD rejected deliveries, same shipment came back - copy tracking details
            $updateData['return_shipping_carrier'] = $order->shipping_carrier;
            $updateData['return_tracking_number'] = $order->shipping_tracking_number;
            $updateData['return_tracking_url'] = $order->shipping_tracking_url;
        } elseif ($merchantNote && ! $return->internal_note) {
            // Store merchant's refund note as internal note if not already set
            $updateData['internal_note'] = $merchantNote;
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
                'method' => PaymentGateway::parse($transaction['gateway'] ?? null)?->value,
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
     * Calculate return shipping costs from order carrier and desi
     * For Shopify: Try to get actual costs from BasitKargo first, fallback to rate table
     */
    protected function calculateReturnShippingCosts(Order $order): array
    {
        $shippingData = [
            'carrier' => null,
            'return_shipping_cost' => null,
            'return_shipping_vat_rate' => 20.00,
            'return_shipping_vat_amount' => null,
            'return_shipping_rate_id' => null,
        ];

        // Try to get actual costs from BasitKargo first (if order has tracking number)
        if ($order->shipping_tracking_number) {
            $actualCosts = $this->getActualCostsFromBasitKargo($order);
            if ($actualCosts) {
                return $actualCosts;
            }
        }

        // Fallback to rate table calculation
        $carrier = $order->shipping_carrier;
        $desi = $order->shipping_desi;

        // If missing carrier or desi, use order's existing shipping costs
        if (! $carrier || ! $desi) {
            if ($order->shipping_cost_excluding_vat) {
                $shippingData['carrier'] = $carrier;
                $shippingData['return_shipping_cost'] = $order->shipping_cost_excluding_vat?->getAmount() ?? 0;
                $shippingData['return_shipping_vat_rate'] = $order->shipping_vat_rate ?? 20.00;
                $shippingData['return_shipping_vat_amount'] = $order->shipping_vat_amount?->getAmount() ?? 0;
                $shippingData['return_shipping_rate_id'] = $order->shipping_rate_id;
            }

            return $shippingData;
        }

        $shippingData['carrier'] = $carrier;

        // Calculate shipping cost using ShippingCostService (rate table)
        $costCalculation = $this->shippingCostService->calculateCost($carrier, (float) $desi);

        if ($costCalculation) {
            $shippingData['return_shipping_cost'] = $costCalculation['cost_excluding_vat'];
            $shippingData['return_shipping_vat_rate'] = $costCalculation['vat_rate'];
            $shippingData['return_shipping_vat_amount'] = $costCalculation['vat_amount'];
            $shippingData['return_shipping_rate_id'] = $costCalculation['rate_id'];
        } else {
            // Final fallback to order costs if rate table calculation fails
            activity()
                ->withProperties([
                    'order_id' => $order->id,
                    'carrier' => $carrier->value ?? null,
                    'desi' => $desi,
                ])
                ->log('shopify_return_shipping_cost_calculation_failed');

            $shippingData['return_shipping_cost'] = $order->shipping_cost_excluding_vat?->getAmount() ?? 0;
            $shippingData['return_shipping_vat_rate'] = $order->shipping_vat_rate ?? 20.00;
            $shippingData['return_shipping_vat_amount'] = $order->shipping_vat_amount?->getAmount() ?? 0;
            $shippingData['return_shipping_rate_id'] = $order->shipping_rate_id;
        }

        return $shippingData;
    }

    /**
     * Get actual shipping costs from BasitKargo API
     * Returns actual costs paid, not estimates from rate table
     */
    protected function getActualCostsFromBasitKargo(Order $order): ?array
    {
        try {
            // Get active BasitKargo integration
            $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
                ->where('provider', IntegrationProvider::BASIT_KARGO)
                ->where('is_active', true)
                ->first();

            if (! $integration) {
                return null;
            }

            // Create adapter and fetch shipment details
            $adapter = new BasitKargoAdapter($integration);
            $shipmentData = $adapter->trackShipment($order->shipping_tracking_number);

            if (! $shipmentData) {
                return null;
            }

            // Extract price info
            $priceInfo = $shipmentData['raw_data']['priceInfo'] ?? null;
            if (! $priceInfo) {
                return null;
            }

            $outboundCost = (float) ($priceInfo['shipmentFee'] ?? 0); // Outbound shipping cost
            $totalCost = (float) ($priceInfo['totalCost'] ?? 0); // Total (outbound + return if applicable)
            $returnCost = $totalCost - $outboundCost; // Return shipping cost (if shipment was returned)

            // For returns, assume the return cost equals outbound cost if not explicitly returned
            // This is a reasonable estimate: return shipping typically costs the same as outbound
            $returnShippingCost = $returnCost > 0 ? $returnCost : $outboundCost;

            // Convert to minor units (cents)
            $returnShippingCostMinor = (int) round($returnShippingCost * 100);

            // VAT calculation
            $vatIncluded = $integration->settings['vat_included'] ?? true;
            $vatRate = 20.00;

            if ($vatIncluded) {
                // Price includes VAT - extract it
                $priceExcludingVat = (int) round($returnShippingCostMinor / 1.20);
                $vatAmount = $returnShippingCostMinor - $priceExcludingVat;
            } else {
                // Price excludes VAT - add it
                $priceExcludingVat = $returnShippingCostMinor;
                $vatAmount = (int) round($returnShippingCostMinor * 0.20);
            }

            // Parse carrier from BasitKargo response
            $carrier = $order->shipping_carrier;
            if (isset($shipmentData['carrier_code'])) {
                $carrier = $this->mapBasitKargoCodeToCarrier($shipmentData['carrier_code']) ?? $carrier;
            }

            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'source' => 'basitkargo_api',
                    'outbound_cost' => $outboundCost,
                    'return_cost' => $returnCost,
                    'used_return_cost' => $returnShippingCost,
                ])
                ->log('shopify_return_shipping_cost_from_basitkargo');

            return [
                'carrier' => $carrier,
                'return_shipping_cost' => $priceExcludingVat,
                'return_shipping_vat_rate' => $vatRate,
                'return_shipping_vat_amount' => $vatAmount,
                'return_shipping_rate_id' => null, // No rate table used
            ];
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_return_basitkargo_fetch_failed');

            return null;
        }
    }

    /**
     * Map BasitKargo carrier codes to our ShippingCarrier enum
     */
    protected function mapBasitKargoCodeToCarrier(string $code): ?\App\Enums\Shipping\ShippingCarrier
    {
        return match (strtoupper($code)) {
            'MNG' => null, // MNG not in our enum
            'YURTICI' => \App\Enums\Shipping\ShippingCarrier::YURTICI,
            'ARAS' => \App\Enums\Shipping\ShippingCarrier::ARAS,
            'SURAT' => \App\Enums\Shipping\ShippingCarrier::SURAT,
            'PTT' => \App\Enums\Shipping\ShippingCarrier::PTT,
            'DHL' => \App\Enums\Shipping\ShippingCarrier::DHL,
            'HEPSIJET' => \App\Enums\Shipping\ShippingCarrier::HEPSIJET,
            default => null,
        };
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
