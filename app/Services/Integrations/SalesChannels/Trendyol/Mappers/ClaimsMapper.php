<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Mappers;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Order\ReturnItem;
use App\Services\Integrations\Concerns\BaseReturnsMapper;
use App\Services\Shipping\ShippingCostService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClaimsMapper extends BaseReturnsMapper
{
    public function __construct(
        protected ShippingCostService $shippingCostService
    ) {}

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

            // Calculate return shipping costs
            $returnShippingCosts = $this->calculateReturnShippingCosts($claim, $order);

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
                    'return_shipping_desi' => $claim['cargoDeci'] ?? $order->shipping_desi,
                    'carrier' => $returnShippingCosts['carrier'],
                    'return_shipping_cost_excluding_vat' => $returnShippingCosts['return_shipping_cost_excluding_vat'],
                    'return_shipping_vat_rate' => $returnShippingCosts['return_shipping_vat_rate'],
                    'return_shipping_vat_amount' => $returnShippingCosts['return_shipping_vat_amount'],
                    'return_shipping_rate_id' => $returnShippingCosts['return_shipping_rate_id'],
                    'order_shipment_package_id' => $claim['orderShipmentPackageId'] ?? null,
                    'order_outbound_package_id' => $claim['orderOutboundPackageId'] ?? null,
                    // Store original order's shipping cost for comparison
                    'original_shipping_cost' => $order->shipping_amount?->getAmount() ?? 0,
                    'currency' => $order->currency,
                    'platform_data' => $claim,
                ]
            );

            // Sync return items
            $this->syncReturnItems($return, $claim['items'] ?? [], $order);

            // Calculate total refund amount from return items
            $return->refresh();
            $totalRefundAmount = $return->items->sum(fn ($item) => $item->refund_amount?->getAmount() ?? 0);
            $return->update(['total_refund_amount' => $totalRefundAmount]);

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

                // Calculate refund amount from order item (accounts for discounts)
                $refundAmount = $orderItem?->unit_price?->getAmount() ?? 0;

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
                        'refund_amount' => $refundAmount,
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

    /**
     * Calculate return shipping costs from Trendyol claim data
     */
    protected function calculateReturnShippingCosts(array $claim, Order $order): array
    {
        $carrierName = $claim['cargoProviderName'] ?? null;
        $desi = $claim['cargoDeci'] ?? $order->shipping_desi;

        $shippingData = [
            'carrier' => null,
            'return_shipping_cost_excluding_vat' => null,
            'return_shipping_vat_rate' => 20.00,
            'return_shipping_vat_amount' => null,
            'return_shipping_rate_id' => null,
        ];

        // Only calculate if we have both carrier name and desi
        if (! $carrierName || ! $desi) {
            // Fallback: use original order shipping costs if available
            if ($order->carrier && $order->shipping_desi) {
                $shippingData['carrier'] = $order->carrier;
                $shippingData['return_shipping_cost_excluding_vat'] = $order->shipping_cost_excluding_vat?->getAmount() ?? 0;
                $shippingData['return_shipping_vat_rate'] = $order->shipping_vat_rate ?? 20.00;
                $shippingData['return_shipping_vat_amount'] = $order->shipping_vat_amount?->getAmount() ?? 0;
                $shippingData['return_shipping_rate_id'] = $order->shipping_rate_id;
            }

            return $shippingData;
        }

        // Parse carrier name to enum
        $carrier = $this->shippingCostService->parseCarrier($carrierName);

        if (! $carrier) {
            activity()
                ->withProperties([
                    'carrier_name' => $carrierName,
                    'claim_id' => $claim['id'] ?? null,
                ])
                ->log('trendyol_return_carrier_not_recognized');

            // Fallback to order's carrier/costs
            if ($order->carrier && $order->shipping_desi) {
                $shippingData['return_shipping_cost_excluding_vat'] = $order->shipping_cost_excluding_vat?->getAmount() ?? 0;
                $shippingData['return_shipping_vat_rate'] = $order->shipping_vat_rate ?? 20.00;
                $shippingData['return_shipping_vat_amount'] = $order->shipping_vat_amount?->getAmount() ?? 0;
                $shippingData['return_shipping_rate_id'] = $order->shipping_rate_id;
            }

            return $shippingData;
        }

        $shippingData['carrier'] = $carrier;

        // Calculate shipping cost
        $costCalculation = $this->shippingCostService->calculateCost($carrier, (float) $desi);

        if ($costCalculation) {
            $shippingData['return_shipping_cost_excluding_vat'] = $costCalculation['cost_excluding_vat'];
            $shippingData['return_shipping_vat_rate'] = $costCalculation['vat_rate'];
            $shippingData['return_shipping_vat_amount'] = $costCalculation['vat_amount'];
            $shippingData['return_shipping_rate_id'] = $costCalculation['rate_id'];
        }

        return $shippingData;
    }
}
