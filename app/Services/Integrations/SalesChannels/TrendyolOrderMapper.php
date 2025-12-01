<?php

namespace App\Services\Integrations\SalesChannels;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use App\Models\Product\ProductVariant;
use App\Services\Product\AttributeMappingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TrendyolOrderMapper
{
    public function __construct(
        protected AttributeMappingService $attributeMappingService
    ) {}

    public function mapOrder(array $trendyolPackage, string $integrationProvider = 'trendyol'): Order
    {
        return DB::transaction(function () use ($trendyolPackage, $integrationProvider) {
            $customer = $this->findOrCreateCustomer($trendyolPackage, $integrationProvider);

            $existingMapping = PlatformMapping::where('platform', $integrationProvider)
                ->where('platform_id', (string) $trendyolPackage['id'])
                ->where('entity_type', Order::class)
                ->first();

            if ($existingMapping && $existingMapping->entity) {
                $order = $existingMapping->entity;
                $this->updateOrder($order, $trendyolPackage);
            } else {
                // Clean up orphaned mapping if entity is missing
                if ($existingMapping) {
                    $existingMapping->delete();
                }

                $order = $this->createOrder($customer, $trendyolPackage, $integrationProvider);
            }

            $this->syncOrderItems($order, $trendyolPackage['lines'] ?? [], $integrationProvider);

            // Calculate and update total commission
            $totalCommission = $order->items->sum(function ($item) {
                return $item->commission_amount->getAmount();
            });

            $order->update(['total_commission' => $totalCommission]);

            return $order->fresh('items', 'customer');
        });
    }

    protected function findOrCreateCustomer(array $trendyolPackage, string $platform): Customer
    {
        $shipmentAddress = $trendyolPackage['shipmentAddress'] ?? [];
        $invoiceAddress = $trendyolPackage['invoiceAddress'] ?? [];

        $existingMapping = PlatformMapping::where('platform', $platform)
            ->where('platform_id', (string) ($trendyolPackage['customerId'] ?? ''))
            ->where('entity_type', Customer::class)
            ->first();

        if ($existingMapping) {
            return $existingMapping->entity;
        }

        $customer = Customer::create([
            'channel' => OrderChannel::TRENDYOL,
            'first_name' => $trendyolPackage['customerFirstName'] ?? $shipmentAddress['firstName'] ?? '',
            'last_name' => $trendyolPackage['customerLastName'] ?? $shipmentAddress['lastName'] ?? '',
            'email' => $trendyolPackage['customerEmail'] ?? null,
            'phone' => $shipmentAddress['phone'] ?? null,
            'address_line1' => $shipmentAddress['address1'] ?? null,
            'address_line2' => $shipmentAddress['address2'] ?? null,
            'city' => $shipmentAddress['city'] ?? null,
            'state' => $shipmentAddress['district'] ?? null,
            'postal_code' => $shipmentAddress['postalCode'] ?? null,
            'country' => $shipmentAddress['countryCode'] ?? 'TR',
            'notes' => null,
        ]);

        if (! empty($trendyolPackage['customerId'])) {
            PlatformMapping::create([
                'platform' => $platform,
                'entity_type' => Customer::class,
                'entity_id' => $customer->id,
                'platform_id' => (string) $trendyolPackage['customerId'],
                'platform_data' => [
                    'invoice_address' => $invoiceAddress,
                    'shipment_address' => $shipmentAddress,
                ],
                'last_synced_at' => now(),
            ]);
        }

        return $customer;
    }

    protected function createOrder(Customer $customer, array $trendyolPackage, string $platform): Order
    {
        $grossAmount = $this->convertToMinorUnits($trendyolPackage['grossAmount'] ?? 0, $trendyolPackage['currencyCode'] ?? 'TRY');
        $totalDiscount = $this->convertToMinorUnits($trendyolPackage['totalDiscount'] ?? 0, $trendyolPackage['currencyCode'] ?? 'TRY');
        $totalPrice = $this->convertToMinorUnits($trendyolPackage['totalPrice'] ?? 0, $trendyolPackage['currencyCode'] ?? 'TRY');

        $orderStatus = $this->mapOrderStatus($trendyolPackage['status'] ?? '');
        $paymentStatus = $this->mapPaymentStatus($trendyolPackage);
        $fulfillmentStatus = $this->mapFulfillmentStatus($trendyolPackage['status'] ?? '');

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

        $order = Order::create([
            'customer_id' => $customer->id,
            'channel' => $platform,
            'order_number' => $trendyolPackage['orderNumber'] ?? null,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'subtotal' => $grossAmount,
            'tax_amount' => 0,
            'shipping_amount' => 0,
            'discount_amount' => $totalDiscount,
            'total_amount' => $totalPrice,
            'total_commission' => 0, // Will be calculated from items
            'currency' => $trendyolPackage['currencyCode'] ?? 'TRY',
            'invoice_number' => null,
            'invoice_date' => null,
            'invoice_url' => $trendyolPackage['invoiceLink'] ?? null,
            'notes' => null,
            'order_date' => isset($trendyolPackage['orderDate'])
                ? Carbon::createFromTimestampMs($trendyolPackage['orderDate'])
                : now(),
            'shipping_carrier' => $trendyolPackage['cargoProviderName'] ?? null,
            'shipping_desi' => $trendyolPackage['cargoDeci'] ?? null,
            'shipping_tracking_number' => $trendyolPackage['cargoTrackingNumber'] ?? null,
            'shipping_tracking_url' => $trendyolPackage['cargoTrackingLink'] ?? null,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
            'estimated_delivery_start' => $estimatedDeliveryStart,
            'estimated_delivery_end' => $estimatedDeliveryEnd,
        ]);

        PlatformMapping::updateOrCreate(
            [
                'platform' => $platform,
                'entity_type' => Order::class,
                'entity_id' => $order->id,
            ],
            [
                'platform_id' => (string) $trendyolPackage['id'],
                'platform_data' => $trendyolPackage,
                'last_synced_at' => now(),
            ]
        );

        return $order;
    }

    protected function updateOrder(Order $order, array $trendyolPackage): void
    {
        $grossAmount = $this->convertToMinorUnits($trendyolPackage['grossAmount'] ?? 0, $trendyolPackage['currencyCode'] ?? 'TRY');
        $totalDiscount = $this->convertToMinorUnits($trendyolPackage['totalDiscount'] ?? 0, $trendyolPackage['currencyCode'] ?? 'TRY');
        $totalPrice = $this->convertToMinorUnits($trendyolPackage['totalPrice'] ?? 0, $trendyolPackage['currencyCode'] ?? 'TRY');

        $orderStatus = $this->mapOrderStatus($trendyolPackage['status'] ?? '');
        $paymentStatus = $this->mapPaymentStatus($trendyolPackage);
        $fulfillmentStatus = $this->mapFulfillmentStatus($trendyolPackage['status'] ?? '');

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

        $order->update([
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'subtotal' => $grossAmount,
            'discount_amount' => $totalDiscount,
            'total_amount' => $totalPrice,
            'invoice_url' => $trendyolPackage['invoiceLink'] ?? $order->invoice_url,
            'shipping_carrier' => $trendyolPackage['cargoProviderName'] ?? null,
            'shipping_desi' => $trendyolPackage['cargoDeci'] ?? null,
            'shipping_tracking_number' => $trendyolPackage['cargoTrackingNumber'] ?? null,
            'shipping_tracking_url' => $trendyolPackage['cargoTrackingLink'] ?? null,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
            'estimated_delivery_start' => $estimatedDeliveryStart,
            'estimated_delivery_end' => $estimatedDeliveryEnd,
        ]);

        $order->platformMappings()
            ->where('platform', 'trendyol')
            ->update([
                'platform_data' => $trendyolPackage,
                'last_synced_at' => now(),
            ]);
    }

    protected function syncOrderItems(Order $order, array $lines, string $platform): void
    {
        $existingItemIds = [];

        foreach ($lines as $line) {
            $variant = $this->findProductVariant($line, $platform);

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
                'total_price' => $totalPrice,
                'discount_amount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'commission_amount' => $commissionAmount,
                'commission_rate' => round($commissionRate, 2),
            ];

            $existingMapping = PlatformMapping::where('platform', $platform)
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
                        'platform' => $platform,
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

    protected function findProductVariant(array $line, string $platform): ?ProductVariant
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

        $mapping = PlatformMapping::where('platform', $platform)
            ->where('platform_id', (string) ($line['productCode'] ?? ''))
            ->where('entity_type', ProductVariant::class)
            ->first();

        if ($mapping?->entity) {
            return $mapping->entity;
        }

        return $this->createProductFromLine($line, $platform);
    }

    protected function createProductFromLine(array $line, string $platform): ProductVariant
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
        $product = $this->findOrCreateProduct($modelCode, $cleanProductName, $platform);

        if ($productCode) {
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $platform,
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
                    'platform' => $platform,
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
        $this->mapVariantAttributes($variant, $line, $platform);

        activity()
            ->performedOn($variant)
            ->withProperties([
                'platform' => $platform,
                'product_code' => $productCode,
                'line_data' => $line,
            ])
            ->log('product_created_from_external_order');

        return $variant;
    }

    /**
     * Map Trendyol line item attributes to variant options.
     */
    protected function mapVariantAttributes(ProductVariant $variant, array $line, string $platform): void
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
                $platform
            );
        }
    }

    protected function convertToMinorUnits(float $amount, string $currency): int
    {
        return (int) round($amount * 100);
    }

    protected function mapOrderStatus(string $trendyolStatus): OrderStatus
    {
        return match (strtoupper($trendyolStatus)) {
            'CREATED', 'AWAITING' => OrderStatus::PENDING,
            'PICKING', 'PICKED' => OrderStatus::PROCESSING,
            'INVOICED', 'SHIPPED' => OrderStatus::COMPLETED,
            'DELIVERED' => OrderStatus::COMPLETED,
            'CANCELLED', 'CANCEL_PENDING' => OrderStatus::CANCELLED,
            'RETURNED', 'UNPACKED' => OrderStatus::CANCELLED,
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
            'SHIPPED', 'DELIVERED' => PaymentStatus::PAID,
            'CANCELLED', 'RETURNED' => PaymentStatus::REFUNDED,
            default => PaymentStatus::PENDING,
        };
    }

    protected function mapFulfillmentStatus(string $trendyolStatus): FulfillmentStatus
    {
        return match (strtoupper($trendyolStatus)) {
            'CREATED', 'AWAITING' => FulfillmentStatus::UNFULFILLED,
            'PICKING', 'PICKED' => FulfillmentStatus::AWAITING_SHIPMENT,
            'INVOICED' => FulfillmentStatus::AWAITING_SHIPMENT,
            'SHIPPED' => FulfillmentStatus::IN_TRANSIT,
            'DELIVERED' => FulfillmentStatus::DELIVERED,
            'CANCELLED', 'CANCEL_PENDING', 'RETURNED', 'UNPACKED' => FulfillmentStatus::CANCELLED,
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
        string $productName,
        string $platform
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
            'description' => sprintf('Imported from %s', ucfirst($platform)),
            'vendor' => ucfirst($platform),
            'product_type' => 'Imported',
            'status' => 'active',
        ]);
    }
}
