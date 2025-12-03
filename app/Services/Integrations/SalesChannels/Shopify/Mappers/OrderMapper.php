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
use App\Services\Address\AddressService;
use App\Services\Integrations\Concerns\BaseOrderMapper;
use App\Services\Product\AttributeMappingService;
use App\Services\Shipping\ShippingCostSyncService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderMapper extends BaseOrderMapper
{
    public function __construct(
        protected AttributeMappingService $attributeMappingService,
        protected AddressService $addressService,
        protected ShippingCostSyncService $shippingCostSyncService
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

            $this->syncOrderItems($order, $shopifyOrder['line_items'] ?? [], $shopifyOrder['refunds'] ?? []);

            // Recalculate totals
            $order->refresh();

            // Auto-detect carrier from shipping line if available
            if (! $order->shipping_carrier && isset($shopifyOrder['shipping_lines'][0]['code'])) {
                $carrierCode = $shopifyOrder['shipping_lines'][0]['code'];
                $carrier = \App\Enums\Shipping\ShippingCarrier::fromString($carrierCode);
                if ($carrier) {
                    $order->update(['shipping_carrier' => $carrier->value]);
                }
            }

            // Sync shipping costs from BasitKargo if order has tracking number
            if ($order->shipping_tracking_number && ! $order->shipping_carrier) {
                try {
                    $this->shippingCostSyncService->syncShippingCostFromBasitKargo($order);
                } catch (\Exception $e) {
                    // Log but don't fail the order sync
                    activity()
                        ->performedOn($order)
                        ->withProperties(['error' => $e->getMessage()])
                        ->log('shopify_shipping_cost_sync_failed');
                }
            }

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
        // Use current_subtotal_price and current_total_price to account for order edits/refunds
        $subtotal = $this->convertToMinorUnits((float) ($shopifyOrder['current_subtotal_price'] ?? $shopifyOrder['subtotal_price'] ?? 0), $currency);
        $taxAmount = $this->convertToMinorUnits((float) ($shopifyOrder['current_total_tax'] ?? $shopifyOrder['total_tax'] ?? 0), $currency);
        $shippingAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? $shopifyOrder['shipping_lines'][0]['price'] ?? 0), $currency);
        $discountAmount = $this->convertToMinorUnits((float) ($shopifyOrder['current_total_discounts'] ?? $shopifyOrder['total_discounts'] ?? 0), $currency);
        $totalAmount = $this->convertToMinorUnits((float) ($shopifyOrder['current_total_price'] ?? $shopifyOrder['total_price'] ?? 0), $currency);

        $orderStatus = $this->mapOrderStatus($shopifyOrder);
        $paymentStatus = $this->mapPaymentStatus($shopifyOrder);
        $fulfillmentStatus = $this->mapFulfillmentStatus($shopifyOrder);

        // Extract payment information
        $paymentInfo = $this->extractPaymentInformation($shopifyOrder);

        // Extract shipping information
        $fulfillments = $shopifyOrder['fulfillments'] ?? [];
        $firstFulfillment = $fulfillments[0] ?? null;

        $shippedAt = $firstFulfillment && isset($firstFulfillment['created_at'])
            ? Carbon::parse($firstFulfillment['created_at'])
            : null;

        $deliveredAt = $firstFulfillment && isset($firstFulfillment['updated_at']) && ($firstFulfillment['status'] ?? null) === 'success'
            ? Carbon::parse($firstFulfillment['updated_at'])
            : null;

        // Create addresses
        $shippingAddressId = null;
        $billingAddressId = null;

        $hasShippingAddress = ! empty($shopifyOrder['shipping_address']);
        $hasBillingAddress = ! empty($shopifyOrder['billing_address']);

        // Check if both addresses exist and are the same
        if ($hasShippingAddress && $hasBillingAddress) {
            $addressesAreSame = $this->addressService->isSameAddress(
                $shopifyOrder['shipping_address'],
                $shopifyOrder['billing_address']
            );

            if ($addressesAreSame) {
                // Create only one address for both shipping and billing
                $address = $this->addressService->createOrUpdateAddress(
                    $customer,
                    $shopifyOrder['shipping_address'],
                    'shipping'
                );
                $shippingAddressId = $address->id;
                $billingAddressId = $address->id;
            } else {
                // Create separate addresses
                $shippingAddress = $this->addressService->createOrUpdateAddress(
                    $customer,
                    $shopifyOrder['shipping_address'],
                    'shipping'
                );
                $shippingAddressId = $shippingAddress->id;

                $billingAddress = $this->addressService->createOrUpdateAddress(
                    $customer,
                    $shopifyOrder['billing_address'],
                    'billing'
                );
                $billingAddressId = $billingAddress->id;
            }
        } elseif ($hasShippingAddress) {
            // Only shipping address exists
            $shippingAddress = $this->addressService->createOrUpdateAddress(
                $customer,
                $shopifyOrder['shipping_address'],
                'shipping'
            );
            $shippingAddressId = $shippingAddress->id;
        } elseif ($hasBillingAddress) {
            // Only billing address exists
            $billingAddress = $this->addressService->createOrUpdateAddress(
                $customer,
                $shopifyOrder['billing_address'],
                'billing'
            );
            $billingAddressId = $billingAddress->id;
        }

        $order = Order::create([
            'customer_id' => $customer->id,
            'shipping_address_id' => $shippingAddressId,
            'billing_address_id' => $billingAddressId,
            'channel' => $this->getChannel(),
            'order_number' => $shopifyOrder['order_number'] ?? $shopifyOrder['name'] ?? null,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentInfo['method'],
            'payment_gateway' => $paymentInfo['gateway'],
            'payment_transaction_id' => $paymentInfo['transaction_id'],
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
        // Use current_subtotal_price and current_total_price to account for order edits/refunds
        $subtotal = $this->convertToMinorUnits((float) ($shopifyOrder['current_subtotal_price'] ?? $shopifyOrder['subtotal_price'] ?? 0), $currency);
        $taxAmount = $this->convertToMinorUnits((float) ($shopifyOrder['current_total_tax'] ?? $shopifyOrder['total_tax'] ?? 0), $currency);
        $shippingAmount = $this->convertToMinorUnits((float) ($shopifyOrder['total_shipping_price_set']['shop_money']['amount'] ?? $shopifyOrder['shipping_lines'][0]['price'] ?? 0), $currency);
        $discountAmount = $this->convertToMinorUnits((float) ($shopifyOrder['current_total_discounts'] ?? $shopifyOrder['total_discounts'] ?? 0), $currency);
        $totalAmount = $this->convertToMinorUnits((float) ($shopifyOrder['current_total_price'] ?? $shopifyOrder['total_price'] ?? 0), $currency);

        $newOrderStatus = $this->mapOrderStatus($shopifyOrder);
        $newPaymentStatus = $this->mapPaymentStatus($shopifyOrder);
        $newFulfillmentStatus = $this->mapFulfillmentStatus($shopifyOrder);

        // Extract payment information
        $paymentInfo = $this->extractPaymentInformation($shopifyOrder);

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
            'payment_method' => $paymentInfo['method'],
            'payment_gateway' => $paymentInfo['gateway'],
            'payment_transaction_id' => $paymentInfo['transaction_id'],
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

    protected function syncOrderItems(Order $order, array $lineItems, array $refunds = []): void
    {
        // Get list of cancelled line item IDs (restock_type = 'cancel')
        $cancelledLineItemIds = $this->getCancelledLineItemIds($refunds);

        foreach ($lineItems as $lineItem) {
            $lineItemId = $lineItem['id'] ?? null;

            // Skip line items that have been cancelled (order edits)
            if ($lineItemId && in_array($lineItemId, $cancelledLineItemIds)) {
                // Delete the item if it exists
                $variant = $this->findVariantByShopifyVariantId($lineItem['variant_id'] ?? null);
                if ($variant) {
                    $order->items()->where('product_variant_id', $variant->id)->delete();
                }

                continue;
            }
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

            $unitPrice = $this->convertToMinorUnits((float) ($lineItem['price'] ?? 0), $order->currency);
            $quantity = (int) ($lineItem['quantity'] ?? 1);
            $discountAmount = $this->convertToMinorUnits((float) ($lineItem['total_discount'] ?? 0), $order->currency);

            // Total price = (unit price * quantity) - discount
            $totalPrice = ($unitPrice * $quantity) - $discountAmount;

            // Extract tax information from line item
            $taxRate = 0;
            $taxAmount = 0;

            if (! empty($lineItem['tax_lines'])) {
                // Get the first tax line (usually only one per line item)
                $taxLine = $lineItem['tax_lines'][0];
                $taxRate = ((float) ($taxLine['rate'] ?? 0)) * 100; // Convert to percentage (0.1 -> 10)
                $taxAmount = $this->convertToMinorUnits((float) ($taxLine['price'] ?? 0), $order->currency);
            }

            $order->items()->updateOrCreate(
                [
                    'product_variant_id' => $variant->id,
                ],
                [
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'discount_amount' => $discountAmount,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'commission_amount' => 0,
                    'commission_rate' => 0,
                ]
            );
        }
    }

    protected function getCancelledLineItemIds(array $refunds): array
    {
        $cancelledIds = [];

        foreach ($refunds as $refund) {
            foreach ($refund['refund_line_items'] ?? [] as $refundLineItem) {
                // restock_type = 'cancel' means item was removed from order (not returned)
                if (($refundLineItem['restock_type'] ?? null) === 'cancel') {
                    $cancelledIds[] = $refundLineItem['line_item_id'];
                }
            }
        }

        return $cancelledIds;
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

    protected function extractPaymentInformation(array $shopifyOrder): array
    {
        // Get payment gateway names from Shopify
        $gatewayNames = $shopifyOrder['payment_gateway_names'] ?? [];
        $gateway = ! empty($gatewayNames) ? strtolower($gatewayNames[0]) : null;

        // Try to get transaction ID from payment details
        $transactionId = null;
        if (isset($shopifyOrder['transactions']) && is_array($shopifyOrder['transactions'])) {
            foreach ($shopifyOrder['transactions'] as $transaction) {
                if (($transaction['status'] ?? '') === 'success') {
                    // Try authorization first, then payment_id, then transaction id
                    $transactionId = $transaction['authorization']
                        ?? $transaction['payment_id']
                        ?? $transaction['id']
                        ?? null;
                    if ($transactionId) {
                        break;
                    }
                }
            }
        }

        // Determine payment method based on gateway
        $method = $this->determinePaymentMethod($gateway);

        return [
            'method' => $method,
            'gateway' => $gateway,
            'transaction_id' => $transactionId ? (string) $transactionId : null,
        ];
    }

    protected function determinePaymentMethod(?string $gateway): ?string
    {
        if (! $gateway) {
            return null;
        }

        // Map common gateways to payment methods
        // Check specific payment gateways first before generic keywords
        return match (true) {
            str_contains($gateway, 'cash') || str_contains($gateway, 'cod') => 'cod',
            str_contains($gateway, 'iyzico') => 'online',
            str_contains($gateway, 'stripe') => 'online',
            str_contains($gateway, 'paypal') => 'online',
            str_contains($gateway, 'credit') || str_contains($gateway, 'card') || str_contains($gateway, 'kart') => 'online',
            str_contains($gateway, 'bank') || str_contains($gateway, 'wire') || str_contains($gateway, 'transfer') || str_contains($gateway, 'eft') || str_contains($gateway, 'havale') => 'bank_transfer',
            default => 'online',
        };
    }
}
