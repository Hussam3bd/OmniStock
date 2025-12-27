<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Mappers;

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
use App\Services\Shipping\ShippingCostService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderMapper extends BaseOrderMapper
{
    public function __construct(
        protected AttributeMappingService $attributeMappingService,
        protected AddressService $addressService,
        protected ShippingCostService $shippingCostService
    ) {}

    protected function getChannel(): OrderChannel
    {
        return OrderChannel::TRENDYOL;
    }

    public function mapOrder(array $trendyolPackage, ?\App\Models\Integration\Integration $integration = null): Order
    {
        return DB::transaction(function () use ($trendyolPackage, $integration) {
            $customer = $this->findOrCreateCustomerFromTrendyol($trendyolPackage);

            // Update customer with real data if available (for both new and existing orders)
            if (! $this->isCustomerDataMasked($trendyolPackage)) {
                $this->updateCustomerFromTrendyolData($customer, $trendyolPackage);
            }

            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $trendyolPackage['id'])
                ->where('entity_type', Order::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $order = $existingMapping->entity;
                $this->updateOrder($order, $trendyolPackage, $integration);
            } else {
                // Clean up orphaned mapping if entity is missing
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $order = $this->createOrder($customer, $trendyolPackage, $integration);
            }

            $this->syncOrderItems($order, $trendyolPackage['lines'] ?? []);

            // Calculate and store total product cost
            $this->calculateTotalProductCost($order);

            // Calculate and update total commission
            $totalCommission = $order->items->sum(function ($item) {
                return $item->commission_amount->getAmount();
            });

            $order->update(['total_commission' => $totalCommission]);

            return $order->fresh('items', 'customer');
        });
    }

    protected function findOrCreateCustomerFromTrendyol(array $trendyolPackage): Customer
    {
        $shipmentAddress = $trendyolPackage['shipmentAddress'] ?? [];
        $invoiceAddress = $trendyolPackage['invoiceAddress'] ?? [];

        // Check if customer data is masked (Trendyol sends *** for Awaiting status)
        $isMasked = $this->isCustomerDataMasked($trendyolPackage);

        $customerData = [
            'first_name' => $trendyolPackage['customerFirstName'] ?? $shipmentAddress['firstName'] ?? '',
            'last_name' => $trendyolPackage['customerLastName'] ?? $shipmentAddress['lastName'] ?? '',
            'email' => $trendyolPackage['customerEmail'] ?? null,
            'phone' => $shipmentAddress['phone'] ?? null,
            'notes' => null,
        ];

        $externalCustomerId = ! empty($trendyolPackage['customerId'])
            ? (string) $trendyolPackage['customerId']
            : null;

        // If data is masked, try to find existing customer by external ID only
        if ($isMasked && $externalCustomerId) {
            $existingMapping = \App\Models\Platform\PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $externalCustomerId)
                ->where('entity_type', Customer::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                // Return existing customer without updating with masked data
                return $existingMapping->entity;
            }

            // If no existing customer found with masked data, create placeholder
            $customerData = [
                'first_name' => 'Trendyol',
                'last_name' => 'Customer',
                'email' => null,
                'phone' => null,
                'notes' => 'Customer data pending from Trendyol',
            ];
        }

        // If we have real data with email, check for existing customer by email first
        // This prevents duplicates when a customer was created with masked data
        if (! $isMasked && ! empty($customerData['email']) && $customerData['email'] !== '***') {
            $existingCustomerByEmail = Customer::where('email', $customerData['email'])->first();

            if ($existingCustomerByEmail && $externalCustomerId) {
                // Check if this customer already has a Trendyol platform mapping
                $existingTrendyolMapping = $existingCustomerByEmail->platformMappings()
                    ->where('platform', $this->getChannel()->value)
                    ->first();

                // Only reuse customer if they don't have a Trendyol mapping yet
                // OR if they have the same Trendyol customer ID
                if (! $existingTrendyolMapping || $existingTrendyolMapping->platform_id === $externalCustomerId) {
                    // Link customer to Trendyol ID if not already linked
                    if (! $existingTrendyolMapping) {
                        // Check if this Trendyol ID is already assigned to another customer globally
                        $globalMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                            ->where('platform_id', $externalCustomerId)
                            ->where('entity_type', Customer::class)
                            ->first();

                        if ($globalMapping && $globalMapping->entity_id !== $existingCustomerByEmail->id) {
                            // Move the mapping from the old customer to this one
                            $globalMapping->update([
                                'entity_id' => $existingCustomerByEmail->id,
                                'platform_data' => [
                                    'invoice_address' => $invoiceAddress,
                                    'shipment_address' => $shipmentAddress,
                                ],
                            ]);
                        } else {
                            // Create new mapping
                            $existingCustomerByEmail->platformMappings()->create([
                                'platform' => $this->getChannel()->value,
                                'platform_id' => $externalCustomerId,
                                'entity_type' => Customer::class,
                                'platform_data' => [
                                    'invoice_address' => $invoiceAddress,
                                    'shipment_address' => $shipmentAddress,
                                ],
                            ]);
                        }
                    }

                    return $existingCustomerByEmail;
                }
                // If customer has a different Trendyol ID, create a new customer
                // (they are different customers in Trendyol's system)
            }
        }

        $customer = $this->findOrCreateCustomer($customerData, $externalCustomerId);

        // Create or update platform mapping
        if ($externalCustomerId) {
            $customer->platformMappings()->updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'platform_id' => $externalCustomerId,
                    'entity_type' => Customer::class,
                ],
                [
                    'platform_data' => $isMasked ? [] : [
                        'invoice_address' => $invoiceAddress,
                        'shipment_address' => $shipmentAddress,
                    ],
                ]
            );
        }

        return $customer;
    }

    protected function createOrder(Customer $customer, array $trendyolPackage, ?\App\Models\Integration\Integration $integration = null): Order
    {
        $currency = $trendyolPackage['currencyCode'] ?? 'TRY';
        $currencyFields = $this->resolveCurrencyFields($currency);

        $grossAmount = $this->convertToMinorUnits($trendyolPackage['grossAmount'] ?? 0, $currency);
        $totalDiscount = $this->convertToMinorUnits($trendyolPackage['totalDiscount'] ?? 0, $currency);
        $totalPrice = $this->convertToMinorUnits($trendyolPackage['totalPrice'] ?? 0, $currency);

        $orderStatus = $this->mapOrderStatus($trendyolPackage['status'] ?? '');
        $paymentStatus = $this->mapPaymentStatus($trendyolPackage);
        $fulfillmentStatus = $this->mapFulfillmentStatus($trendyolPackage);

        // Extract shipping information
        $shippedAt = isset($trendyolPackage['originShipmentDate'])
            ? Carbon::createFromTimestampMs($trendyolPackage['originShipmentDate'])
            : null;

        $estimatedDeliveryStart = isset($trendyolPackage['estimatedDeliveryStartDate'])
            ? Carbon::createFromTimestampMs($trendyolPackage['estimatedDeliveryStartDate'])
            : null;

        $estimatedDeliveryEnd = isset($trendyolPackage['estimatedDeliveryEndDate'])
            ? Carbon::createFromTimestampMs($trendyolPackage['estimatedDeliveryEndDate'])
            : null;

        // Check if delivered
        $deliveredAt = null;
        if (isset($trendyolPackage['status']) && strtoupper($trendyolPackage['status']) === 'DELIVERED') {
            $deliveredAt = $shippedAt; // Approximate, exact delivery date not in API
        }

        // Calculate shipping costs
        $shippingCosts = $this->calculateShippingCosts($trendyolPackage);

        $order = Order::create([
            'customer_id' => $customer->id,
            'shipping_address_id' => null,
            'billing_address_id' => null,
            'channel' => $this->getChannel(),
            'integration_id' => $integration?->id,
            'order_number' => $trendyolPackage['orderNumber'] ?? null,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'subtotal' => $grossAmount,
            'tax_amount' => 0,
            'shipping_amount' => 0, // Trendyol: free shipping to customer
            'discount_amount' => $totalDiscount,
            'total_amount' => $totalPrice,
            'total_commission' => 0, // Will be calculated from items
            'currency' => $currency,
            'currency_id' => $currencyFields['currency_id'],
            'exchange_rate' => $currencyFields['exchange_rate'],
            'invoice_number' => null,
            'invoice_date' => null,
            'invoice_url' => $trendyolPackage['invoiceLink'] ?? null,
            'notes' => null,
            'order_date' => isset($trendyolPackage['orderDate'])
                ? Carbon::createFromTimestampMs($trendyolPackage['orderDate'])
                : now(),
            'shipping_carrier' => $shippingCosts['carrier'] ?? \App\Enums\Shipping\ShippingCarrier::fromString($trendyolPackage['cargoProviderName'] ?? '')?->value,
            'shipping_desi' => $trendyolPackage['cargoDeci'] ?? null,
            'shipping_cost_excluding_vat' => $shippingCosts['shipping_cost_excluding_vat'],
            'shipping_vat_rate' => $shippingCosts['shipping_vat_rate'],
            'shipping_vat_amount' => $shippingCosts['shipping_vat_amount'],
            'shipping_rate_id' => $shippingCosts['shipping_rate_id'],
            'shipping_tracking_number' => $trendyolPackage['cargoTrackingNumber'] ?? null,
            'shipping_tracking_url' => $trendyolPackage['cargoTrackingLink'] ?? null,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
            'estimated_delivery_start' => $estimatedDeliveryStart,
            'estimated_delivery_end' => $estimatedDeliveryEnd,
        ]);

        PlatformMapping::updateOrCreate(
            [
                'platform' => $this->getChannel()->value,
                'entity_type' => Order::class,
                'entity_id' => $order->id,
            ],
            [
                'platform_id' => (string) $trendyolPackage['id'],
                'platform_data' => $trendyolPackage,
                'last_synced_at' => now(),
            ]
        );

        // Create addresses for both customer and order using unified method
        $mappedShippingAddress = ! empty($trendyolPackage['shipmentAddress'])
            ? $this->mapTrendyolAddress($trendyolPackage['shipmentAddress'])
            : null;

        $mappedBillingAddress = ! empty($trendyolPackage['invoiceAddress'])
            ? $this->mapTrendyolAddress($trendyolPackage['invoiceAddress'])
            : null;

        $addressIds = $this->addressService->createOrderAndCustomerAddresses(
            $order,
            $customer,
            $mappedShippingAddress,
            $mappedBillingAddress
        );

        // Update order with address IDs
        $order->update($addressIds);

        return $order;
    }

    protected function updateOrder(Order $order, array $trendyolPackage, ?\App\Models\Integration\Integration $integration = null): void
    {
        // Check if customer data is masked
        $isMasked = $this->isCustomerDataMasked($trendyolPackage);

        // If data is not masked, update customer information
        if (! $isMasked) {
            $this->updateCustomerFromTrendyolData($order->customer, $trendyolPackage);
            $this->updateAddressesFromTrendyolData($order, $trendyolPackage);
        }

        $currency = $trendyolPackage['currencyCode'] ?? 'TRY';

        // Only recalculate currency fields if currency changed (rare case)
        $currencyChanged = $order->currency !== $currency;
        if ($currencyChanged) {
            $currencyFields = $this->resolveCurrencyFields($currency);
        }

        $grossAmount = $this->convertToMinorUnits($trendyolPackage['grossAmount'] ?? 0, $currency);
        $totalDiscount = $this->convertToMinorUnits($trendyolPackage['totalDiscount'] ?? 0, $currency);
        $totalPrice = $this->convertToMinorUnits($trendyolPackage['totalPrice'] ?? 0, $currency);

        // Update integration_id if provided and not already set
        if ($integration && ! $order->integration_id) {
            $order->update(['integration_id' => $integration->id]);
        }

        $newOrderStatus = $this->mapOrderStatus($trendyolPackage['status'] ?? '');
        $newPaymentStatus = $this->mapPaymentStatus($trendyolPackage);
        $newFulfillmentStatus = $this->mapFulfillmentStatus($trendyolPackage);

        // Check if order has returns - if so, preserve current order/payment status
        // Returns take precedence over Trendyol delivery status
        $hasReturns = $order->returns()->exists();

        // Track status changes (only if not preserving due to returns)
        $statusChanges = [];
        if (! $hasReturns && $order->order_status !== $newOrderStatus) {
            $statusChanges['order_status'] = [
                'from' => $order->order_status->value,
                'to' => $newOrderStatus->value,
            ];
        }
        if (! $hasReturns && $order->payment_status !== $newPaymentStatus) {
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
        $shippedAt = isset($trendyolPackage['originShipmentDate'])
            ? Carbon::createFromTimestampMs($trendyolPackage['originShipmentDate'])
            : null;

        $estimatedDeliveryStart = isset($trendyolPackage['estimatedDeliveryStartDate'])
            ? Carbon::createFromTimestampMs($trendyolPackage['estimatedDeliveryStartDate'])
            : null;

        $estimatedDeliveryEnd = isset($trendyolPackage['estimatedDeliveryEndDate'])
            ? Carbon::createFromTimestampMs($trendyolPackage['estimatedDeliveryEndDate'])
            : null;

        // Check if delivered
        $deliveredAt = null;
        if (isset($trendyolPackage['status']) && strtoupper($trendyolPackage['status']) === 'DELIVERED') {
            $deliveredAt = $shippedAt; // Approximate, exact delivery date not in API
        }

        // Calculate shipping costs
        $shippingCosts = $this->calculateShippingCosts($trendyolPackage);

        $updateData = [
            // Only update order/payment status if order has no returns
            // Returns manage their own order/payment status lifecycle
            'order_status' => $hasReturns ? $order->order_status : $newOrderStatus,
            'payment_status' => $hasReturns ? $order->payment_status : $newPaymentStatus,
            'fulfillment_status' => $newFulfillmentStatus,
            'subtotal' => $grossAmount,
            'discount_amount' => $totalDiscount,
            'total_amount' => $totalPrice,
            'invoice_url' => $trendyolPackage['invoiceLink'] ?? $order->invoice_url,
            'shipping_carrier' => $shippingCosts['carrier'] ?? \App\Enums\Shipping\ShippingCarrier::fromString($trendyolPackage['cargoProviderName'] ?? '')?->value,
            'shipping_desi' => $trendyolPackage['cargoDeci'] ?? null,
            'shipping_amount' => 0, // Trendyol: free shipping to customer
            'shipping_cost_excluding_vat' => $shippingCosts['shipping_cost_excluding_vat'],
            'shipping_vat_rate' => $shippingCosts['shipping_vat_rate'],
            'shipping_vat_amount' => $shippingCosts['shipping_vat_amount'],
            'shipping_rate_id' => $shippingCosts['shipping_rate_id'],
            'shipping_tracking_number' => $trendyolPackage['cargoTrackingNumber'] ?? null,
            'shipping_tracking_url' => $trendyolPackage['cargoTrackingLink'] ?? null,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
            'estimated_delivery_start' => $estimatedDeliveryStart,
            'estimated_delivery_end' => $estimatedDeliveryEnd,
        ];

        // Only update currency fields if currency changed
        if ($currencyChanged) {
            $updateData['currency'] = $currency;
            $updateData['currency_id'] = $currencyFields['currency_id'];
            $updateData['exchange_rate'] = $currencyFields['exchange_rate'];
        }

        $order->update($updateData);

        // Recalculate return status after sync (returns may have been processed locally)
        // This ensures order_status/return_status reflect local return state
        $this->recalculateOrderReturnStatus($order);

        $order->platformMappings()
            ->where('platform', $this->getChannel()->value)
            ->update([
                'platform_data' => $trendyolPackage,
                'last_synced_at' => now(),
            ]);

        // Log status changes
        if (! empty($statusChanges)) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'trendyol_status' => $trendyolPackage['status'] ?? null,
                    'status_changes' => $statusChanges,
                    'order_number' => $order->order_number,
                ])
                ->log('trendyol_order_status_updated');
        }
    }

    protected function syncOrderItems(Order $order, array $lines): void
    {
        $existingItemIds = [];

        foreach ($lines as $line) {
            $variant = $this->findProductVariant($line);

            if (! $variant) {
                continue;
            }

            $currency = $line['currencyCode'] ?? 'TRY';
            $unitPrice = $this->convertToMinorUnits($line['price'] ?? 0, $currency);
            $quantity = $line['quantity'] ?? 1;
            $discount = $this->convertToMinorUnits($line['discount'] ?? 0, $currency);
            $taxRate = $line['vatBaseAmount'] ?? 0;
            $commissionRate = $line['commission'] ?? 0; // Commission is a percentage rate

            // Calculate tax amount based on tax rate
            $taxAmount = (int) round(($unitPrice * $quantity - $discount) * ($taxRate / 100));

            // Total price is unit price * quantity
            $totalPrice = $unitPrice * $quantity;

            // Calculate commission amount from rate
            // Commission is calculated on the unit price
            $commissionAmount = (int) round($unitPrice * $quantity * ($commissionRate / 100));

            $itemData = [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'unit_cost' => $variant->cost_price?->getAmount() ?? 0,
                'total_price' => $totalPrice,
                'discount_amount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'commission_amount' => $commissionAmount,
                'commission_rate' => round($commissionRate, 2),
            ];

            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', (string) $line['id'])
                ->where('entity_type', 'App\Models\Order\OrderItem')
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $item = $existingMapping->entity;
                $item->update($itemData);
                $existingItemIds[] = $item->id;
            } else {
                // Clean up orphaned mapping if entity is missing
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $item = $order->items()->create([
                    'product_variant_id' => $variant->id,
                    ...$itemData,
                ]);

                PlatformMapping::updateOrCreate(
                    [
                        'platform' => $this->getChannel()->value,
                        'entity_type' => 'App\Models\Order\OrderItem',
                        'entity_id' => $item->id,
                    ],
                    [
                        'platform_id' => (string) $line['id'],
                        'platform_data' => $line,
                        'last_synced_at' => now(),
                    ]
                );

                $existingItemIds[] = $item->id;
            }
        }

        $order->items()->whereNotIn('id', $existingItemIds)->delete();
    }

    /**
     * Calculate and update total product cost (COGS) for the order
     */
    protected function calculateTotalProductCost(Order $order): void
    {
        $totalCost = $order->items()
            ->get()
            ->sum(function ($item) {
                return ($item->unit_cost?->getAmount() ?? 0) * $item->quantity;
            });

        $order->update([
            'total_product_cost' => $totalCost,
        ]);
    }

    /**
     * Recalculate order return status based on local return data
     * This ensures that local returns override Trendyol's order status
     */
    protected function recalculateOrderReturnStatus(Order $order): void
    {
        $action = app(\App\Actions\Order\UpdateOrderReturnStatusAction::class);
        $action->execute($order, forceRecalculate: true);
    }

    protected function findProductVariant(array $line): ?ProductVariant
    {
        $barcode = $line['barcode'] ?? null;

        if ($barcode) {
            $variant = ProductVariant::where('barcode', $barcode)->first();
            if ($variant) {
                return $variant;
            }
        }

        $merchantSku = $line['merchantSku'] ?? null;
        if ($merchantSku) {
            $variant = ProductVariant::where('sku', $merchantSku)->first();
            if ($variant) {
                return $variant;
            }
        }

        $sku = $line['sku'] ?? null;
        if ($sku) {
            $variant = ProductVariant::where('barcode', $sku)->first();
            if ($variant) {
                return $variant;
            }
        }

        $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
            ->where('platform_id', (string) ($line['productCode'] ?? ''))
            ->where('entity_type', ProductVariant::class)
            ->first();

        if ($mapping?->entity) {
            return $mapping->entity;
        }

        return $this->createProductFromLine($line);
    }

    protected function createProductFromLine(array $line): ProductVariant
    {
        $productName = $line['productName'] ?? 'Unknown Product';
        $productCode = $line['productCode'] ?? null;
        $barcode = $line['barcode'] ?? $line['sku'] ?? null;
        $merchantSku = $line['merchantSku'] ?? null;
        $price = $this->convertToMinorUnits($line['price'] ?? 0, $line['currencyCode'] ?? 'TRY');

        // Extract model code and clean product name
        $modelCode = $this->extractModelCode($productName);
        $cleanProductName = $this->cleanProductName($productName, $line);

        // Find or create product by model code
        $product = $this->findOrCreateProduct($modelCode, $cleanProductName);

        if ($productCode) {
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'entity_type' => \App\Models\Product\Product::class,
                    'entity_id' => $product->id,
                ],
                [
                    'platform_id' => (string) $productCode,
                    'platform_data' => $line,
                    'last_synced_at' => now(),
                ]
            );
        }

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $merchantSku ?? $barcode ?? 'SKU-'.$product->id,
            'barcode' => $barcode,
            'title' => $productName,
            'price' => $price,
            'inventory_quantity' => 0,
            'requires_shipping' => true,
            'taxable' => true,
        ]);

        if ($productCode) {
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'entity_type' => ProductVariant::class,
                    'entity_id' => $variant->id,
                ],
                [
                    'platform_id' => (string) $productCode,
                    'platform_data' => $line,
                    'last_synced_at' => now(),
                ]
            );
        }

        // Map variant attributes (color, size, etc.)
        $this->mapVariantAttributes($variant, $line);

        activity()
            ->performedOn($variant)
            ->withProperties([
                'platform' => $this->getChannel()->value,
                'product_code' => $productCode,
                'line_data' => $line,
            ])
            ->log('product_created_from_external_order');

        return $variant;
    }

    /**
     * Map Trendyol line item attributes to variant options.
     */
    protected function mapVariantAttributes(ProductVariant $variant, array $line): void
    {
        $attributes = [];

        // Extract common Trendyol attributes
        if (isset($line['productColor'])) {
            $attributes['productColor'] = $line['productColor'];
        }

        if (isset($line['productSize'])) {
            $attributes['productSize'] = $line['productSize'];
        }

        // Map attributes to variant options
        if (! empty($attributes)) {
            $this->attributeMappingService->mapAttributesToVariant(
                $variant,
                $attributes,
                $this->getChannel()->value
            );
        }
    }

    protected function mapOrderStatus(string $trendyolStatus): OrderStatus
    {
        return match (strtoupper($trendyolStatus)) {
            'CREATED', 'AWAITING', 'VERIFIED' => OrderStatus::PENDING,
            'PICKING', 'PICKED' => OrderStatus::PROCESSING,
            'INVOICED', 'SHIPPED', 'AT_COLLECTION_POINT' => OrderStatus::COMPLETED,
            'DELIVERED' => OrderStatus::COMPLETED,
            'CANCELLED', 'CANCEL_PENDING', 'UNSUPPLIED' => OrderStatus::CANCELLED,
            'RETURNED', 'UNPACKED', 'UNDELIVERED' => OrderStatus::CANCELLED,
            default => OrderStatus::PENDING,
        };
    }

    protected function mapPaymentStatus(array $trendyolPackage): PaymentStatus
    {
        if (isset($trendyolPackage['invoiceLink']) && ! empty($trendyolPackage['invoiceLink'])) {
            return PaymentStatus::PAID;
        }

        $status = strtoupper($trendyolPackage['status'] ?? '');

        return match ($status) {
            'INVOICED', 'SHIPPED', 'DELIVERED', 'AT_COLLECTION_POINT' => PaymentStatus::PAID,
            'CANCELLED', 'RETURNED', 'UNSUPPLIED' => PaymentStatus::REFUNDED,
            'UNDELIVERED' => PaymentStatus::REFUNDED,
            default => PaymentStatus::PENDING,
        };
    }

    protected function mapFulfillmentStatus(array $trendyolPackage): FulfillmentStatus
    {
        // Prioritize shipmentPackageStatus as it's more accurate for fulfillment
        $packageStatus = $trendyolPackage['shipmentPackageStatus'] ?? null;
        $orderStatus = $trendyolPackage['status'] ?? '';

        // Use package status if available, otherwise fall back to order status
        $status = $packageStatus ?? $orderStatus;

        return match (strtoupper($status)) {
            'CREATED', 'AWAITING', 'VERIFIED' => FulfillmentStatus::UNFULFILLED,
            'PICKING', 'PICKED' => FulfillmentStatus::AWAITING_SHIPMENT,
            'INVOICED', 'READYTOSHIP' => FulfillmentStatus::AWAITING_SHIPMENT,
            'SHIPPED', 'AT_COLLECTION_POINT' => FulfillmentStatus::IN_TRANSIT,
            'DELIVERED' => FulfillmentStatus::DELIVERED,
            'CANCELLED', 'CANCEL_PENDING', 'RETURNED', 'UNPACKED', 'UNDELIVERED', 'UNSUPPLIED' => FulfillmentStatus::CANCELLED,
            default => FulfillmentStatus::UNFULFILLED,
        };
    }

    protected function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [
            'first_name' => $parts[0] ?? '',
            'last_name' => $parts[1] ?? '',
        ];
    }

    /**
     * Extract model code from Trendyol product name.
     * Patterns:
     * 1. Your SKU: "REV-0011", "REV-000109-TOK-BOR"
     * 2. Trendyol code: "1234567890123, Size"
     */
    protected function extractModelCode(string $productName): ?string
    {
        // Priority 1: Match your SKU pattern (REV-XXXX or similar)
        // Pattern: Word-Number format (e.g., REV-0011, REV-000109-TOK-BOR)
        if (preg_match('/\b([A-Z]{2,}-[\dA-Z-]+)\b/i', $productName, $matches)) {
            return $matches[1];
        }

        // Priority 2: Match 13-digit Trendyol code before comma and size
        if (preg_match('/(\d{13}),?\s*\d+/', $productName, $matches)) {
            return $matches[1];
        }

        // Priority 3: Fallback to any long number sequence (10+ digits)
        if (preg_match('/(\d{10,})/', $productName, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Clean product name by removing variant-specific info.
     */
    protected function cleanProductName(string $productName, array $line): string
    {
        $cleaned = $productName;

        // Remove SKU patterns (e.g., "REV-0011", "REV-000109-TOK-BOR")
        $cleaned = preg_replace('/\b[A-Z]{2,}-[\dA-Z-]+\b/i', '', $cleaned);

        // Remove model code and size (e.g., "8983156785379, 37")
        $cleaned = preg_replace('/\d{10,},?\s*\d+/', '', $cleaned);

        // Remove color name if present in line data
        if (isset($line['productColor'])) {
            $color = preg_quote($line['productColor'], '/');
            $cleaned = preg_replace('/\b'.$color.'\b/iu', '', $cleaned);
        }

        // Remove size if present in line data
        if (isset($line['productSize'])) {
            $size = preg_quote($line['productSize'], '/');
            $cleaned = preg_replace('/\b'.$size.'\b/iu', '', $cleaned);
        }

        // Clean up extra spaces and commas
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = preg_replace('/,\s*,/', ',', $cleaned);
        $cleaned = trim($cleaned, " ,\t\n\r\0\x0B");

        return $cleaned ?: $productName;
    }

    /**
     * Find or create a product by model code.
     */
    protected function findOrCreateProduct(
        ?string $modelCode,
        string $productName
    ): \App\Models\Product\Product {
        // Try to find by model code
        if ($modelCode) {
            $product = \App\Models\Product\Product::where('model_code', $modelCode)->first();

            if ($product) {
                return $product;
            }
        }

        // Create new product
        return \App\Models\Product\Product::create([
            'model_code' => $modelCode,
            'title' => $productName,
            'description' => sprintf('Imported from %s', ucfirst($this->getChannel()->value)),
            'vendor' => ucfirst($this->getChannel()->value),
            'product_type' => 'Imported',
            'status' => 'active',
        ]);
    }

    /**
     * Map Trendyol address format to AddressService format
     */
    protected function mapTrendyolAddress(array $trendyolAddress): array
    {
        return [
            'first_name' => $trendyolAddress['firstName'] ?? null,
            'last_name' => $trendyolAddress['lastName'] ?? null,
            'company' => $trendyolAddress['company'] ?? null,
            'phone' => $trendyolAddress['phone'] ?? null,
            'address1' => $trendyolAddress['address1'] ?? $trendyolAddress['fullAddress'] ?? null,
            'address2' => $trendyolAddress['address2'] ?? null,
            'city' => $trendyolAddress['city'] ?? null,
            'province' => $trendyolAddress['cityCode'] ?? $trendyolAddress['city'] ?? null,
            'district' => $trendyolAddress['district'] ?? $trendyolAddress['districtName'] ?? null,
            'neighborhood' => $trendyolAddress['neighborhood'] ?? null,
            'zip' => $trendyolAddress['postalCode'] ?? null,
            'country' => $trendyolAddress['country'] ?? null,
            'country_code' => $trendyolAddress['countryCode'] ?? 'TR',
            'tax_number' => $trendyolAddress['taxNumber'] ?? null,
            'tax_office' => $trendyolAddress['taxOffice'] ?? null,
            'identity_number' => $trendyolAddress['identityNumber'] ?? null,
        ];
    }

    /**
     * Calculate shipping costs from Trendyol package data
     */
    protected function calculateShippingCosts(array $trendyolPackage): array
    {
        $carrierName = $trendyolPackage['cargoProviderName'] ?? null;
        $desi = $trendyolPackage['cargoDeci'] ?? null;

        $shippingData = [
            'carrier' => null,
            'shipping_cost_excluding_vat' => null,
            'shipping_vat_rate' => 20.00,
            'shipping_vat_amount' => null,
            'shipping_rate_id' => null,
        ];

        // Only calculate if we have both carrier name and desi
        if (! $carrierName || ! $desi) {
            return $shippingData;
        }

        // Parse carrier name to enum
        $carrier = $this->shippingCostService->parseCarrier($carrierName);

        if (! $carrier) {
            activity()
                ->withProperties([
                    'carrier_name' => $carrierName,
                    'order_number' => $trendyolPackage['orderNumber'] ?? null,
                ])
                ->log('trendyol_carrier_not_recognized');

            return $shippingData;
        }

        $shippingData['carrier'] = $carrier;

        // Calculate shipping cost
        $costCalculation = $this->shippingCostService->calculateCost($carrier, (float) $desi);

        if ($costCalculation) {
            $shippingData['shipping_cost_excluding_vat'] = $costCalculation['cost_excluding_vat'];
            $shippingData['shipping_vat_rate'] = $costCalculation['vat_rate'];
            $shippingData['shipping_vat_amount'] = $costCalculation['vat_amount'];
            $shippingData['shipping_rate_id'] = $costCalculation['rate_id'];
        }

        return $shippingData;
    }

    /**
     * Check if Trendyol customer data is masked (***).
     * Trendyol masks customer data when status is "Awaiting".
     */
    protected function isCustomerDataMasked(array $trendyolPackage): bool
    {
        $firstName = $trendyolPackage['customerFirstName'] ?? '';
        $lastName = $trendyolPackage['customerLastName'] ?? '';
        $email = $trendyolPackage['customerEmail'] ?? '';
        $phone = $trendyolPackage['shipmentAddress']['phone'] ?? '';
        $address = $trendyolPackage['shipmentAddress']['fullAddress'] ?? '';

        // Check if any critical field is masked
        return $firstName === '***'
            || $lastName === '***'
            || $email === '***'
            || $phone === '***'
            || $address === '***';
    }

    /**
     * Update customer data when real (non-masked) data arrives.
     */
    protected function updateCustomerFromTrendyolData(Customer $customer, array $trendyolPackage): void
    {
        $shipmentAddress = $trendyolPackage['shipmentAddress'] ?? [];
        $invoiceAddress = $trendyolPackage['invoiceAddress'] ?? [];

        $updateData = [];

        // Only update if current data looks like placeholder or old masked data
        $needsUpdate = $customer->first_name === 'Trendyol'
            || $customer->last_name === 'Customer'
            || $customer->first_name === '***'
            || $customer->last_name === '***'
            || $customer->email === '***'
            || $customer->notes === 'Customer data pending from Trendyol'
            || empty($customer->email);

        if ($needsUpdate) {
            $firstName = $trendyolPackage['customerFirstName'] ?? $shipmentAddress['firstName'] ?? null;
            $lastName = $trendyolPackage['customerLastName'] ?? $shipmentAddress['lastName'] ?? null;
            $email = $trendyolPackage['customerEmail'] ?? null;
            $phone = $shipmentAddress['phone'] ?? null;

            if ($firstName) {
                $updateData['first_name'] = $firstName;
            }
            if ($lastName) {
                $updateData['last_name'] = $lastName;
            }
            if ($email) {
                $updateData['email'] = $email;
            }
            if ($phone) {
                $updateData['phone'] = $phone;
            }

            // Clear the pending note if it exists
            if ($customer->notes === 'Customer data pending from Trendyol') {
                $updateData['notes'] = null;
            }

            if (! empty($updateData)) {
                $customer->update($updateData);

                activity()
                    ->performedOn($customer)
                    ->withProperties([
                        'updated_fields' => array_keys($updateData),
                        'source' => 'trendyol_webhook_update',
                    ])
                    ->log('customer_updated_from_trendyol');
            }
        }

        // Create or update platform mapping with real data
        $externalCustomerId = ! empty($trendyolPackage['customerId'])
            ? (string) $trendyolPackage['customerId']
            : null;

        if ($externalCustomerId) {
            // Check if this platform mapping already exists globally (on any customer)
            $existingMapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('platform_id', $externalCustomerId)
                ->where('entity_type', Customer::class)
                ->first();

            // If mapping exists on a different customer, it's a duplicate customer situation
            // We should NOT create a duplicate mapping, just update the existing one
            if ($existingMapping && $existingMapping->entity_id !== $customer->id) {
                // Update the mapping to point to the current customer
                // This handles the case where we found a better customer record (by email)
                $existingMapping->update([
                    'entity_id' => $customer->id,
                    'platform_data' => [
                        'invoice_address' => $invoiceAddress,
                        'shipment_address' => $shipmentAddress,
                    ],
                ]);

                activity()
                    ->performedOn($customer)
                    ->withProperties([
                        'platform' => $this->getChannel()->value,
                        'platform_id' => $externalCustomerId,
                        'old_customer_id' => $existingMapping->entity_id,
                        'action' => 'moved_platform_mapping',
                    ])
                    ->log('customer_platform_mapping_moved');
            } else {
                // Normal case: create or update the mapping
                $customer->platformMappings()->updateOrCreate(
                    [
                        'platform' => $this->getChannel()->value,
                        'platform_id' => $externalCustomerId,
                        'entity_type' => Customer::class,
                    ],
                    [
                        'platform_data' => [
                            'invoice_address' => $invoiceAddress,
                            'shipment_address' => $shipmentAddress,
                        ],
                    ]
                );

                activity()
                    ->performedOn($customer)
                    ->withProperties([
                        'platform' => $this->getChannel()->value,
                        'platform_id' => $externalCustomerId,
                    ])
                    ->log('customer_platform_mapping_created');
            }
        }
    }

    /**
     * Update order addresses when real (non-masked) data arrives.
     */
    protected function updateAddressesFromTrendyolData(Order $order, array $trendyolPackage): void
    {
        $shipmentAddress = $trendyolPackage['shipmentAddress'] ?? [];
        $invoiceAddress = $trendyolPackage['invoiceAddress'] ?? [];

        if (empty($shipmentAddress)) {
            return;
        }

        // Check if addresses exist but might have masked data
        $shippingNeedsUpdate = false;
        $billingNeedsUpdate = false;

        if ($order->shipping_address_id) {
            $shippingAddress = $order->shippingAddress;
            // Check if address looks masked or incomplete
            if ($shippingAddress && ($shippingAddress->address1 === '***' || empty($shippingAddress->address1))) {
                $shippingNeedsUpdate = true;
            }
        }

        if ($order->billing_address_id && ! empty($invoiceAddress)) {
            $billingAddress = $order->billingAddress;
            if ($billingAddress && ($billingAddress->address1 === '***' || empty($billingAddress->address1))) {
                $billingNeedsUpdate = true;
            }
        }

        // Create addresses if missing, or update if they were masked
        if (! $order->shipping_address_id || ! $order->billing_address_id || $shippingNeedsUpdate || $billingNeedsUpdate) {
            $mappedShippingAddress = $this->mapTrendyolAddress($shipmentAddress);
            $mappedBillingAddress = ! empty($invoiceAddress)
                ? $this->mapTrendyolAddress($invoiceAddress)
                : null;

            // Check if both addresses are the same
            $addressesAreSame = $mappedBillingAddress && $this->addressService->isSameAddress($mappedShippingAddress, $mappedBillingAddress);

            if (($shippingNeedsUpdate || $billingNeedsUpdate) && $order->shipping_address_id) {
                // Delete old address snapshots
                if ($shippingNeedsUpdate || $billingNeedsUpdate) {
                    // If they're the same address, delete both (they might point to same record)
                    if ($order->shipping_address_id === $order->billing_address_id) {
                        $order->shippingAddress?->delete();
                    } else {
                        if ($shippingNeedsUpdate) {
                            $order->shippingAddress?->delete();
                        }
                        if ($billingNeedsUpdate) {
                            $order->billingAddress?->delete();
                        }
                    }
                }

                if ($addressesAreSame) {
                    // Create one address for both shipping and billing
                    $addressSnapshot = $this->addressService->createOrderAddressSnapshot(
                        $order,
                        $mappedShippingAddress,
                        'both'
                    );
                    $order->update([
                        'shipping_address_id' => $addressSnapshot->id,
                        'billing_address_id' => $addressSnapshot->id,
                    ]);

                    // Update customer's address
                    $this->addressService->createOrUpdateAddress(
                        $order->customer,
                        $mappedShippingAddress,
                        'both'
                    );
                } else {
                    // Create separate addresses
                    if ($shippingNeedsUpdate || ! $order->shipping_address_id) {
                        $shippingSnapshot = $this->addressService->createOrderAddressSnapshot(
                            $order,
                            $mappedShippingAddress,
                            'shipping'
                        );
                        $order->update(['shipping_address_id' => $shippingSnapshot->id]);

                        $this->addressService->createOrUpdateAddress(
                            $order->customer,
                            $mappedShippingAddress,
                            'shipping'
                        );
                    }

                    if (($billingNeedsUpdate || ! $order->billing_address_id) && $mappedBillingAddress) {
                        $billingSnapshot = $this->addressService->createOrderAddressSnapshot(
                            $order,
                            $mappedBillingAddress,
                            'billing'
                        );
                        $order->update(['billing_address_id' => $billingSnapshot->id]);

                        $this->addressService->createOrUpdateAddress(
                            $order->customer,
                            $mappedBillingAddress,
                            'billing'
                        );
                    }
                }

                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'source' => 'trendyol_webhook_update',
                        'addresses_updated' => true,
                        'addresses_are_same' => $addressesAreSame,
                    ])
                    ->log('order_addresses_updated_from_trendyol');
            } else {
                // Create new addresses
                $addressIds = $this->addressService->createOrderAndCustomerAddresses(
                    $order,
                    $order->customer,
                    $mappedShippingAddress,
                    $mappedBillingAddress
                );

                // Update order with address IDs
                $order->update($addressIds);

                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'source' => 'trendyol_webhook_update',
                        'addresses_created' => true,
                    ])
                    ->log('order_addresses_created_from_trendyol');
            }
        }
    }
}
