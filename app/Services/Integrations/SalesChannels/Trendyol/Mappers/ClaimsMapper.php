<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Mappers;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Order\ReturnItem;
use App\Services\Integrations\Concerns\BaseReturnsMapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClaimsMapper extends BaseReturnsMapper
{
    protected function getChannel(): OrderChannel
    {
        return OrderChannel::TRENDYOL;
    }

    /**
     * Map Trendyol claim to OrderReturn
     */
    public function mapReturn(array $claim): ?OrderReturn
    {
        // Find order by order number
        $order = Order::where('order_number', $claim['orderNumber'])->first();

        if (! $order) {
            return null;
        }

        return DB::transaction(function () use ($claim, $order) {
            // Map claim status to our return status
            $status = $this->mapStatus($claim);

            // Calculate dates
            $claimDate = isset($claim['claimDate'])
                ? Carbon::createFromTimestampMs($claim['claimDate'])
                : null;

            $lastModifiedDate = isset($claim['lastModifiedDate'])
                ? Carbon::createFromTimestampMs($claim['lastModifiedDate'])
                : null;

            // Get first claim item for main reason
            $firstItem = $claim['items'][0] ?? null;
            $firstClaimItem = $firstItem['claimItems'][0] ?? null;

            // Determine if this is approved/rejected
            $approvedAt = null;
            $rejectedAt = null;
            $completedAt = null;

            if ($firstClaimItem) {
                $resolved = $firstClaimItem['resolved'] ?? false;
                $acceptedBySeller = $firstClaimItem['acceptedBySeller'] ?? null;
                $claimStatus = $firstClaimItem['claimItemStatus']['name'] ?? null;

                if ($claimStatus === 'Accepted' && $resolved) {
                    $approvedAt = $lastModifiedDate;
                    $completedAt = $lastModifiedDate;
                } elseif ($claimStatus === 'Cancelled') {
                    $rejectedAt = $lastModifiedDate;
                }
            }

            // Create or update return
            $return = OrderReturn::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'external_return_id' => $claim['id'],
                ],
                [
                    'channel' => $this->getChannel(),
                    'status' => $status,
                    'requested_at' => $claimDate,
                    'approved_at' => $approvedAt,
                    'completed_at' => $completedAt,
                    'rejected_at' => $rejectedAt,
                    'last_modified_date' => $lastModifiedDate,
                    'reason_code' => $firstClaimItem['customerClaimItemReason']['code'] ?? null,
                    'reason_name' => $firstClaimItem['customerClaimItemReason']['name'] ?? null,
                    'customer_note' => $firstClaimItem['customerNote'] ?? null,
                    'internal_note' => $firstClaimItem['note'] ?? null,
                    'return_shipping_carrier' => $claim['cargoProviderName'] ?? null,
                    'return_tracking_number' => $claim['cargoTrackingNumber'] ?? null,
                    'return_tracking_url' => $claim['cargoTrackingLink'] ?? null,
                    'order_shipment_package_id' => $claim['orderShipmentPackageId'] ?? null,
                    'order_outbound_package_id' => $claim['orderOutboundPackageId'] ?? null,
                    'currency' => $order->currency,
                    'platform_data' => $claim,
                ]
            );

            // Sync return items
            $this->syncReturnItems($return, $claim['items'] ?? [], $order);

            // Update order return status
            $return->refresh();
            $order->refresh();

            $totalItemsInOrder = $order->items->sum('quantity');
            $totalItemsReturned = $order->returns()
                ->whereIn('status', [\App\Enums\Order\ReturnStatus::Approved, \App\Enums\Order\ReturnStatus::Completed])
                ->get()
                ->flatMap->items
                ->sum('quantity');

            if ($totalItemsReturned === 0) {
                $returnStatus = 'none';
                $orderStatus = null;
            } elseif ($totalItemsReturned >= $totalItemsInOrder) {
                $returnStatus = 'full';
                $orderStatus = \App\Enums\Order\OrderStatus::REFUNDED;
            } else {
                $returnStatus = 'partial';
                $orderStatus = \App\Enums\Order\OrderStatus::PARTIALLY_REFUNDED;
            }

            $updateData = ['return_status' => $returnStatus];
            if ($orderStatus !== null) {
                $updateData['order_status'] = $orderStatus;
            }

            $order->update($updateData);

            return $return->fresh('items');
        });
    }

    /**
     * Sync return items from claim
     */
    protected function syncReturnItems(OrderReturn $return, array $items, Order $order): void
    {
        foreach ($items as $item) {
            $orderLine = $item['orderLine'] ?? [];
            $claimItems = $item['claimItems'] ?? [];

            foreach ($claimItems as $claimItem) {
                // Find matching order item by barcode or merchant SKU
                $orderItem = $order->items()
                    ->whereHas('productVariant', function ($query) use ($orderLine) {
                        $query->where('barcode', $orderLine['barcode'] ?? '')
                            ->orWhere('sku', $orderLine['merchantSku'] ?? '');
                    })
                    ->first();

                // Create return item
                ReturnItem::updateOrCreate(
                    [
                        'return_id' => $return->id,
                        'external_item_id' => $claimItem['id'],
                    ],
                    [
                        'order_item_id' => $orderItem?->id,
                        'quantity' => 1, // Trendyol claims are typically 1 item at a time
                        'reason_code' => $claimItem['customerClaimItemReason']['code'] ?? null,
                        'reason_name' => $claimItem['customerClaimItemReason']['name'] ?? null,
                        'note' => $claimItem['note'] ?? null,
                        'received_condition' => $this->mapCondition($claimItem),
                        'inspection_note' => $claimItem['note'] ?? null,
                        'refund_amount' => $orderLine['price'] ?? 0,
                        'platform_data' => $claimItem,
                    ]
                );
            }
        }
    }

    /**
     * Map Trendyol claim status to our ReturnStatus
     */
    protected function mapStatus(array $claim): ReturnStatus
    {
        $firstItem = $claim['items'][0] ?? null;
        $firstClaimItem = $firstItem['claimItems'][0] ?? null;

        if (! $firstClaimItem) {
            return ReturnStatus::Requested;
        }

        $resolved = $firstClaimItem['resolved'] ?? false;
        $acceptedBySeller = $firstClaimItem['acceptedBySeller'] ?? null;
        $claimStatus = $firstClaimItem['claimItemStatus']['name'] ?? null;

        // If cancelled, mark as cancelled
        if ($claimStatus === 'Cancelled') {
            return ReturnStatus::Cancelled;
        }

        // If accepted and resolved, mark as completed
        if ($claimStatus === 'Accepted' && $resolved) {
            return ReturnStatus::Completed;
        }

        // If accepted but not resolved, mark as approved
        if ($acceptedBySeller === true) {
            return ReturnStatus::Approved;
        }

        // Default to pending review
        return ReturnStatus::PendingReview;
    }

    /**
     * Map condition (if available in future Trendyol data)
     */
    protected function mapCondition(array $claimItem): ?string
    {
        // Trendyol doesn't provide condition in current API
        // Default to good if accepted
        $claimStatus = $claimItem['claimItemStatus']['name'] ?? null;

        if ($claimStatus === 'Accepted') {
            return 'good';
        }

        return null;
    }
}
