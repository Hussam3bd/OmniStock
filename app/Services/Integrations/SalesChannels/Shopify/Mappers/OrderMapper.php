<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Mappers;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\Concerns\BaseOrderMapper;
use App\Services\Product\AttributeMappingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderMapper extends BaseOrderMapper
{
    public function __construct(
        protected AttributeMappingService $attributeMappingService
    ) {}

    protected function getChannel(): OrderChannel
    {
        return OrderChannel::SHOPIFY;
    }

    public function mapOrder(array $shopifyOrder): Order
    {
        return DB::transaction(function () use ($shopifyOrder) {
            $customer = $this->findOrCreateCustomerFromShopify($shopifyOrder);

            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $shopifyOrder['id'])
                ->where('entity_type', Order::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $order = $existingMapping->entity;
                $this->updateOrder($order, $shopifyOrder);
            } else {
                // Clean up orphaned mapping if entity is missing
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $order = $this->createOrder($customer, $shopifyOrder);
            }

            $this->syncOrderItems($order, $shopifyOrder['line_items'] ?? []);

            // Recalculate totals
            $order->refresh();

            return $order->fresh('items', 'customer');
        });
    }

    protected function findOrCreateCustomerFromShopify(array $shopifyOrder): Customer
    {
        $shopifyCustomer = $shopifyOrder['customer'] ?? [];
        $shippingAddress = $shopifyOrder['shipping_address'] ?? [];
        $billingAddress = $shopifyOrder['billing_address'] ?? [];

        $customerData = [
            'first_name' => $shopifyCustomer['first_name'] ?? $shippingAddress['first_name'] ?? '',
            'last_name' => $shopifyCustomer['last_name'] ?? $shippingAddress['last_name'] ?? '',
            'email' => $shopifyCustomer['email'] ?? $shopifyOrder['email'] ?? null,
            'phone' => $shopifyCustomer['phone'] ?? $shippingAddress['phone'] ?? null,
            'address_line1' => $shippingAddress['address1'] ?? null,
            'address_line2' => $shippingAddress['address2'] ?? null,
            'city' => $shippingAddress['city'] ?? null,
            'state' => $shippingAddress['province'] ?? null,
            'postal_code' => $shippingAddress['zip'] ?? null,
            'country' => $shippingAddress['country_code'] ?? $shippingAddress['country'] ?? null,
            'notes' => $shopifyOrder['note'] ?? null,
        ];

        $externalCustomerId = ! empty($shopifyCustomer['id'])
            ? (string) $shopifyCustomer['id']
            : null;

        $customer = $this->findOrCreateCustomer($customerData, $externalCustomerId);

        // Update platform data if customer was found/created with external ID
        if ($externalCustomerId) {
            $customer->platformMappings()
                ->where('platform', $this->getChannel()->value)
                ->where('platform_id', $externalCustomerId)
                ->update([
                    'platform_data' => [
                        'shopify_customer' => $shopifyCustomer,
                        'billing_address' => $billingAddress,
                        'shipping_address' => $shippingAddress,
                    ],
                ]);
        }

        return $customer;
    }

    protected function createOrder(Customer $customer, array $shopifyOrder): Order
    {
        $currency = $shopifyOrder['currency'] ?? 'USD';
        $subtotal = $this->convertToMinorUnits((float) ($shopifyOrder['subtotal_price'] ?? 0), $currency);
        $taxAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_tax'] ?? 0), $currency);
        $shippingAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? $shopifyOrder['shipping_lines'][0]['price'] ?? 0), $currency);
        $discountAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_discounts'] ?? 0), $currency);
        $totalAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_price'] ?? 0), $currency);

        $orderStatus = $this->mapOrderStatus($shopifyOrder);
        $paymentStatus = $this->mapPaymentStatus($shopifyOrder);
        $fulfillmentStatus = $this->mapFulfillmentStatus($shopifyOrder);

        // Extract shipping information
        $fulfillments = $shopifyOrder['fulfillments'] ?? [];
        $firstFulfillment = $fulfillments[0] ?? null;

        $shippedAt = $firstFulfillment && isset($firstFulfillment['created_at'])
            ? Carbon::parse($firstFulfillment['created_at'])
            : null;

        $deliveredAt = $firstFulfillment && isset($firstFulfillment['updated_at']) && ($firstFulfillment['status'] ?? null) === 'success'
            ? Carbon::parse($firstFulfillment['updated_at'])
            : null;

        $order = Order::create([
            'customer_id' => $customer->id,
            'channel' => $this->getChannel(),
            'order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? null,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'shipping_amount' => $shippingAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'total_commission' => 0,
            'currency' => $currency,
            'invoice_number' => null,
            'invoice_date' => null,
            'invoice_url' => null,
            'notes' => $shopifyOrder['note'] ?? null,
            'order_date' => isset($shopifyOrder['created_at'])
                ? Carbon::parse($shopifyOrder['created_at'])
                : now(),
            'shipping_carrier' => $firstFulfillment['tracking_company'] ?? null,
            'shipping_tracking_number' => $firstFulfillment['tracking_number'] ?? null,
            'shipping_tracking_url' => $firstFulfillment['tracking_url'] ?? null,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
        ]);

        PlatformMapping::updateOrCreate(
            [
                'platform' => $this->getChannel()->value,
                'entity_type' => Order::class,
                'entity_id' => $order->id,
            ],
            [
                'platform_id' => (string) $shopifyOrder['id'],
                'platform_data' => $shopifyOrder,
                'last_synced_at' => now(),
            ]
        );

        return $order;
    }

    protected function updateOrder(Order $order, array $shopifyOrder): void
    {
        $currency = $shopifyOrder['currency'] ?? 'USD';
        $subtotal = $this->convertToMinorUnits((float) ($shopifyOrder['subtotal_price'] ?? 0), $currency);
        $taxAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_tax'] ?? 0), $currency);
        $shippingAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? $shopifyOrder['shipping_lines'][0]['price'] ?? 0), $currency);
        $discountAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_discounts'] ?? 0), $currency);
        $totalAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_price'] ?? 0), $currency);

        $newOrderStatus = $this->mapOrderStatus($shopifyOrder);
        $newPaymentStatus = $this->mapPaymentStatus($shopifyOrder);
        $newFulfillmentStatus = $this->mapFulfillmentStatus($shopifyOrder);

        // Track status changes
        $statusChanges = [];
        if ($order->order_status !== $newOrderStatus) {
            $statusChanges['order_status'] = [
                'from' => $order->order_status->value,
                'to' => $newOrderStatus->value,
            ];
        }
        if ($order->payment_status !== $newPaymentStatus) {
            $statusChanges['payment_status'] = [
                'from' => $order->payment_status->value,
                'to' => $newPaymentStatus->value,
            ];
        }
        if ($order->fulfillment_status !== $newFulfillmentStatus) {
            $statusChanges['fulfillment_status'] = [
                'from' => $order->fulfillment_status->value,
                'to' => $newFulfillmentStatus->value,
            ];
        }

        // Extract shipping information
        $fulfillments = $shopifyOrder['fulfillments'] ?? [];
        $firstFulfillment = $fulfillments[0] ?? null;

        $shippedAt = $firstFulfillment && isset($firstFulfillment['created_at'])
            ? Carbon::parse($firstFulfillment['created_at'])
            : null;

        $deliveredAt = $firstFulfillment && isset($firstFulfillment['updated_at']) && ($firstFulfillment['status'] ?? null) === 'success'
            ? Carbon::parse($firstFulfillment['updated_at'])
            : null;

        $order->update([
            'order_status' => $newOrderStatus,
            'payment_status' => $newPaymentStatus,
            'fulfillment_status' => $newFulfillmentStatus,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'shipping_amount' => $shippingAmount,
            'discount_amount' => $discountAmount,
            'total_amount' => $totalAmount,
            'shipping_carrier' => $firstFulfillment['tracking_company'] ?? $order->shipping_carrier,
            'shipping_tracking_number' => $firstFulfillment['tracking_number'] ?? $order->shipping_tracking_number,
            'shipping_tracking_url' => $firstFulfillment['tracking_url'] ?? $order->shipping_tracking_url,
            'shipped_at' => $shippedAt ?? $order->shipped_at,
            'delivered_at' => $deliveredAt ?? $order->delivered_at,
        ]);

        // Update platform mapping
        $order->platformMappings()
            ->where('platform', $this->getChannel()->value)
            ->update([
                'platform_data' => $shopifyOrder,
                'last_synced_at' => now(),
            ]);

        // Log status changes
        if (! empty($statusChanges)) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'shopify_status' => [
                        'financial_status' => $shopifyOrder['financial_status'] ?? null,
                        'fulfillment_status' => $shopifyOrder['fulfillment_status'] ?? null,
                    ],
                    'status_changes' => $statusChanges,
                    'order_number' => $order->order_number,
                ])
                ->log('shopify_order_status_updated');
        }
    }

    protected function syncOrderItems(Order $order, array $lineItems): void
    {
        foreach ($lineItems as $lineItem) {
            $variant = $this->findVariantByShopifyVariantId($lineItem['variant_id'] ?? null);

            if (! $variant) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'line_item' => $lineItem,
                        'shopify_variant_id' => $lineItem['variant_id'] ?? null,
                    ])
                    ->log('shopify_variant_not_found');

                continue;
            }

            $price = $this->convertToMinorUnits((float) ($lineItem['price'] ?? 0), $order->currency);
            $quantity = (int) ($lineItem['quantity'] ?? 1);

            $order->items()->updateOrCreate(
                [
                    'product_variant_id' => $variant->id,
                ],
                [
                    'quantity' => $quantity,
                    'price' => $price,
                    'discount_amount' => $this->convertToMinorUnits((float) ($lineItem['total_discount'] ?? 0), $order->currency),
                    'tax_amount' => 0,
                    'commission_amount' => 0,
                    'commission_rate' => 0,
                ]
            );
        }
    }

    protected function findVariantByShopifyVariantId(?string $shopifyVariantId): ?ProductVariant
    {
        if (! $shopifyVariantId) {
            return null;
        }

        $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
            ->where('platform_id', (string) $shopifyVariantId)
            ->where('entity_type', ProductVariant::class)
            ->first();

        return $mapping?->entity;
    }

    protected function mapOrderStatus(array $shopifyOrder): OrderStatus
    {
        $financialStatus = strtoupper($shopifyOrder['financial_status'] ?? '');
        $fulfillmentStatus = strtoupper($shopifyOrder['fulfillment_status'] ?? '');
        $cancelledAt = $shopifyOrder['cancelled_at'] ?? null;

        if ($cancelledAt) {
            return OrderStatus::CANCELLED;
        }

        if ($fulfillmentStatus === 'FULFILLED') {
            return OrderStatus::COMPLETED;
        }

        if (in_array($financialStatus, ['PAID', 'PARTIALLY_PAID'])) {
            return OrderStatus::PROCESSING;
        }

        return OrderStatus::PENDING;
    }

    protected function mapPaymentStatus(array $shopifyOrder): PaymentStatus
    {
        $financialStatus = strtoupper($shopifyOrder['financial_status'] ?? '');

        return match ($financialStatus) {
            'PAID' => PaymentStatus::PAID,
            'PARTIALLY_PAID' => PaymentStatus::PARTIALLY_PAID,
            'REFUNDED' => PaymentStatus::REFUNDED,
            'PARTIALLY_REFUNDED' => PaymentStatus::PARTIALLY_REFUNDED,
            'VOIDED' => PaymentStatus::FAILED,
            'PENDING', 'AUTHORIZED' => PaymentStatus::PENDING,
            default => PaymentStatus::PENDING,
        };
    }

    protected function mapFulfillmentStatus(array $shopifyOrder): FulfillmentStatus
    {
        $fulfillmentStatus = strtoupper($shopifyOrder['fulfillment_status'] ?? '');
        $cancelledAt = $shopifyOrder['cancelled_at'] ?? null;

        if ($cancelledAt) {
            return FulfillmentStatus::CANCELLED;
        }

        return match ($fulfillmentStatus) {
            'FULFILLED' => FulfillmentStatus::FULFILLED,
            'PARTIAL' => FulfillmentStatus::PARTIALLY_FULFILLED,
            'RESTOCKED' => FulfillmentStatus::RETURNED,
            default => FulfillmentStatus::UNFULFILLED,
        };
    }
}
