<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Mappers;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\Concerns\BaseReturnsMapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReturnRequestMapper extends BaseReturnsMapper
{
    protected function getChannel(): OrderChannel
    {
        return OrderChannel::SHOPIFY;
    }

    /**
     * Map Shopify return request (GraphQL) to OrderReturn
     * This handles return requests BEFORE refunds are created
     */
    public function mapReturn(array $shopifyReturn): ?OrderReturn
    {
        return DB::transaction(function () use ($shopifyReturn) {
            // Extract the numeric order ID from GraphQL ID format (gid://shopify/Order/123456)
            $orderGraphQLId = $shopifyReturn['order']['id'] ?? null;
            $orderId = $shopifyReturn['order']['legacyResourceId'] ?? $this->extractIdFromGraphQL($orderGraphQLId);

            if (! $orderId) {
                activity()
                    ->withProperties([
                        'shopify_return_id' => $shopifyReturn['id'] ?? null,
                        'shopify_return_name' => $shopifyReturn['name'] ?? null,
                    ])
                    ->log('shopify_return_request_no_order_id');

                return null;
            }

            // Find the order in our system
            $order = $this->findOrderByShopifyId((string) $orderId);

            if (! $order) {
                activity()
                    ->withProperties([
                        'shopify_return_id' => $shopifyReturn['id'] ?? null,
                        'shopify_order_id' => $orderId,
                    ])
                    ->log('shopify_return_request_order_not_found');

                return null;
            }

            // Check if return already exists
            $returnGraphQLId = $shopifyReturn['id'] ?? null;
            $returnId = $this->extractIdFromGraphQL($returnGraphQLId);

            if (! $returnId) {
                activity()
                    ->withProperties([
                        'shopify_return' => $shopifyReturn,
                    ])
                    ->log('shopify_return_request_no_return_id');

                return null;
            }

            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $returnId)
                ->where('entity_type', OrderReturn::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $return = $existingMapping->entity;
                $this->updateReturn($return, $shopifyReturn, $order);
            } else {
                // Clean up orphaned mapping
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $return = $this->createReturn($order, $shopifyReturn);
            }

            return $return->fresh();
        });
    }

    protected function findOrderByShopifyId(string $shopifyOrderId): ?Order
    {
        $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
            ->where('platform_id', $shopifyOrderId)
            ->where('entity_type', Order::class)
            ->first();

        return $mapping?->entity;
    }

    protected function createReturn(Order $order, array $shopifyReturn): OrderReturn
    {
        $currency = $order->currency;

        // Map Shopify return status to our return status
        $status = $this->mapReturnStatus($shopifyReturn['status'] ?? 'REQUESTED');

        // Calculate original shipping cost from order
        $originalShippingCost = $order->total_shipping_cost?->getAmount() ?? 0;

        // Get customer note from first return line item
        $customerNote = $this->getCustomerNote($shopifyReturn);
        $returnReason = $this->getReturnReason($shopifyReturn);

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'channel' => $this->getChannel(),
            'external_return_id' => $this->extractIdFromGraphQL($shopifyReturn['id'] ?? ''),
            'status' => $status,
            'requested_at' => isset($shopifyReturn['createdAt'])
                ? Carbon::parse($shopifyReturn['createdAt'])
                : now(),
            'approved_at' => isset($shopifyReturn['requestApprovedAt'])
                ? Carbon::parse($shopifyReturn['requestApprovedAt'])
                : null,
            'completed_at' => $status === ReturnStatus::Completed && isset($shopifyReturn['closedAt'])
                ? Carbon::parse($shopifyReturn['closedAt'])
                : null,
            'reason_code' => $returnReason,
            'reason_name' => $returnReason ?? 'Customer Return Request',
            'customer_note' => $customerNote,
            'original_shipping_cost' => $originalShippingCost,
            'currency' => $currency,
            'platform_data' => $shopifyReturn,
        ]);

        // Create platform mapping
        PlatformMapping::create([
            'platform' => $this->getChannel()->value,
            'platform_id' => $this->extractIdFromGraphQL($shopifyReturn['id'] ?? ''),
            'entity_type' => OrderReturn::class,
            'entity_id' => $return->id,
            'platform_data' => $shopifyReturn,
            'last_synced_at' => now(),
        ]);

        // Sync return items using variant matching
        $this->syncReturnItems($return, $shopifyReturn, $order);

        return $return;
    }

    protected function updateReturn(OrderReturn $return, array $shopifyReturn, Order $order): void
    {
        $status = $this->mapReturnStatus($shopifyReturn['status'] ?? 'REQUESTED');
        $customerNote = $this->getCustomerNote($shopifyReturn);
        $returnReason = $this->getReturnReason($shopifyReturn);

        $updateData = [
            'status' => $status,
            'approved_at' => isset($shopifyReturn['requestApprovedAt'])
                ? Carbon::parse($shopifyReturn['requestApprovedAt'])
                : $return->approved_at,
            'completed_at' => $status === ReturnStatus::Completed && isset($shopifyReturn['closedAt'])
                ? Carbon::parse($shopifyReturn['closedAt'])
                : $return->completed_at,
            'reason_code' => $returnReason ?? $return->reason_code,
            'reason_name' => $returnReason ?? $return->reason_name,
            'customer_note' => $customerNote ?? $return->customer_note,
            'platform_data' => $shopifyReturn,
        ];

        $return->update($updateData);

        // Update platform mapping
        $return->platformMappings()
            ->where('platform', $this->getChannel()->value)
            ->update([
                'platform_data' => $shopifyReturn,
                'last_synced_at' => now(),
            ]);

        // Sync return items using variant matching
        $this->syncReturnItems($return, $shopifyReturn, $order);
    }

    protected function syncReturnItems(OrderReturn $return, array $shopifyReturn, Order $order): void
    {
        // Use returnLineItems with fulfillmentLineItem for variant data
        foreach ($shopifyReturn['returnLineItems']['edges'] ?? [] as $edge) {
            $lineItem = $edge['node'] ?? null;

            if (! $lineItem) {
                continue;
            }

            // Get the variant ID from fulfillmentLineItem.lineItem.variant
            $variantGraphQLId = $lineItem['fulfillmentLineItem']['lineItem']['variant']['id'] ?? null;
            $variantId = $lineItem['fulfillmentLineItem']['lineItem']['variant']['legacyResourceId'] ?? $this->extractIdFromGraphQL($variantGraphQLId);

            if (! $variantId) {
                // No variant data - skip this item
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'line_item' => $lineItem,
                    ])
                    ->log('shopify_return_item_no_variant');

                continue;
            }

            // Find the variant in our system
            $variant = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $variantId)
                ->where('entity_type', \App\Models\Product\ProductVariant::class)
                ->first()?->entity;

            if (! $variant) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'shopify_variant_id' => $variantId,
                        'line_item' => $lineItem,
                    ])
                    ->log('shopify_return_variant_not_found');

                continue;
            }

            // Find the order item with this variant
            $orderItem = $order->items()->where('product_variant_id', $variant->id)->first();

            if (! $orderItem) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'variant_id' => $variant->id,
                        'order_id' => $order->id,
                    ])
                    ->log('shopify_return_order_item_not_found');

                continue;
            }

            $quantity = (int) ($lineItem['quantity'] ?? 1);
            $returnReason = $lineItem['returnReason'] ?? null;
            $returnReasonNote = $lineItem['returnReasonNote'] ?? null;
            $customerNote = $lineItem['customerNote'] ?? null;

            $return->items()->updateOrCreate(
                [
                    'order_item_id' => $orderItem->id,
                ],
                [
                    'quantity' => $quantity,
                    'reason_name' => $returnReason ?? $returnReasonNote ?? 'Customer return request',
                    'reason_code' => $returnReason,
                    'customer_note' => $customerNote,
                    'external_item_id' => $this->extractIdFromGraphQL($lineItem['id'] ?? ''),
                    'platform_data' => $lineItem,
                ]
            );
        }
    }

    protected function mapReturnStatus(string $shopifyStatus): ReturnStatus
    {
        return match (strtoupper($shopifyStatus)) {
            'REQUESTED' => ReturnStatus::Requested,
            'OPEN' => ReturnStatus::PendingReview,
            'APPROVED' => ReturnStatus::Approved,
            'DECLINED' => ReturnStatus::Rejected,
            'CLOSED' => ReturnStatus::Completed,
            'CANCELED', 'CANCELLED' => ReturnStatus::Cancelled,
            default => ReturnStatus::Requested,
        };
    }

    protected function getCustomerNote(array $shopifyReturn): ?string
    {
        // Try to get customer note from first return line item
        foreach ($shopifyReturn['returnLineItems']['edges'] ?? [] as $edge) {
            $lineItem = $edge['node'] ?? null;
            $customerNote = $lineItem['customerNote'] ?? null;

            if ($customerNote) {
                return $customerNote;
            }
        }

        return null;
    }

    protected function getReturnReason(array $shopifyReturn): ?string
    {
        // Try to get return reason from first return line item
        foreach ($shopifyReturn['returnLineItems']['edges'] ?? [] as $edge) {
            $lineItem = $edge['node'] ?? null;
            $returnReason = $lineItem['returnReason'] ?? null;

            if ($returnReason) {
                return $returnReason;
            }
        }

        return null;
    }

    /**
     * Extract numeric ID from Shopify GraphQL ID format
     * Example: "gid://shopify/Return/123456" => "123456"
     */
    protected function extractIdFromGraphQL(?string $graphQLId): ?string
    {
        if (! $graphQLId) {
            return null;
        }

        // Extract the numeric ID from the GraphQL ID format
        $parts = explode('/', $graphQLId);

        return end($parts) ?: null;
    }
}
