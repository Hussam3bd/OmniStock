<?php

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ReturnRequestMapper;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create customer
    $this->customer = Customer::create([
        'email' => 'test@example.com',
        'first_name' => 'Test',
        'last_name' => 'Customer',
    ]);

    // Create Shopify integration
    $this->integration = Integration::factory()->create([
        'type' => IntegrationType::SALES_CHANNEL,
        'provider' => IntegrationProvider::SHOPIFY,
        'is_active' => true,
        'settings' => [
            'shop_domain' => 'test-shop.myshopify.com',
            'access_token' => 'test_token',
        ],
    ]);

    // Create a product with variant
    $this->product = Product::create([
        'title' => 'Test Product',
        'name' => 'Test Product',
        'slug' => 'test-product',
        'status' => 'active',
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'TEST-SKU-001',
        'barcode' => 'TEST-BARCODE-001',
        'name' => 'Test Variant',
        'price' => 10000,
        'stock_quantity' => 100,
    ]);

    // Create platform mapping for variant
    PlatformMapping::create([
        'platform' => OrderChannel::SHOPIFY->value,
        'platform_id' => '12345678',
        'entity_type' => ProductVariant::class,
        'entity_id' => $this->variant->id,
    ]);

    // Create order
    $this->order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'currency' => 'TRY',
        'order_date' => now(),
    ]);

    // Create platform mapping for order
    PlatformMapping::create([
        'platform' => OrderChannel::SHOPIFY->value,
        'platform_id' => '987654321',
        'entity_type' => Order::class,
        'entity_id' => $this->order->id,
    ]);

    // Create order item
    $this->order->items()->create([
        'product_variant_id' => $this->variant->id,
        'quantity' => 2,
        'unit_price' => 10000,
        'total_price' => 20000,
        'product_name' => 'Test Product',
        'variant_name' => 'Test Variant',
    ]);

    $this->mapper = app(ReturnRequestMapper::class);
});

test('creates return with tracking information', function () {
    $shopifyReturn = [
        'id' => 'gid://shopify/Return/123456',
        'name' => '#1001.1',
        'status' => 'OPEN',
        'createdAt' => '2025-12-07T10:00:00Z',
        'requestApprovedAt' => '2025-12-07T11:00:00Z',
        'closedAt' => null,
        'totalQuantity' => 2,
        'order' => [
            'id' => 'gid://shopify/Order/987654321',
            'legacyResourceId' => '987654321',
        ],
        'returnLineItems' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReturnLineItem/111',
                        'quantity' => 2,
                        'returnReason' => 'SIZE_TOO_SMALL',
                        'customerNote' => 'Too small for me',
                        'fulfillmentLineItem' => [
                            'lineItem' => [
                                'id' => 'gid://shopify/LineItem/222',
                                'sku' => 'TEST-SKU',
                                'name' => 'Test Product',
                                'variant' => [
                                    'id' => 'gid://shopify/ProductVariant/12345678',
                                    'legacyResourceId' => '12345678',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'reverseFulfillmentOrders' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReverseFulfillmentOrder/333',
                        'status' => 'COMPLETE',
                        'reverseDeliveries' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ReverseDelivery/444',
                                        'deliverable' => [
                                            'tracking' => [
                                                'number' => '1234567890',
                                                'url' => 'https://tracking.example.com/1234567890',
                                                'carrierName' => 'UPS',
                                            ],
                                            'label' => [
                                                'publicFileUrl' => 'https://example.com/label.pdf',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'lineItems' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ReverseFulfillmentOrderLineItem/555',
                                        'totalQuantity' => 2,
                                        'fulfillmentLineItem' => [
                                            'lineItem' => [
                                                'id' => 'gid://shopify/LineItem/222',
                                                'variant' => [
                                                    'id' => 'gid://shopify/ProductVariant/12345678',
                                                    'legacyResourceId' => '12345678',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $return = $this->mapper->mapReturn($shopifyReturn);

    expect($return)->not->toBeNull()
        ->and($return->order_id)->toBe($this->order->id)
        ->and($return->external_return_id)->toBe('123456')
        ->and($return->status)->toBe(ReturnStatus::InTransit)
        ->and($return->return_tracking_number)->toBe('1234567890')
        ->and($return->return_tracking_url)->toBe('https://tracking.example.com/1234567890')
        ->and($return->label_generated_at)->not->toBeNull()
        ->and($return->shipped_at)->not->toBeNull()
        ->and($return->items)->toHaveCount(1)
        ->and($return->items->first()->quantity)->toBe(2);
});

test('creates return with label but no tracking yet', function () {
    $shopifyReturn = [
        'id' => 'gid://shopify/Return/123457',
        'name' => '#1001.2',
        'status' => 'OPEN',
        'createdAt' => '2025-12-07T10:00:00Z',
        'requestApprovedAt' => '2025-12-07T11:00:00Z',
        'closedAt' => null,
        'totalQuantity' => 2,
        'order' => [
            'id' => 'gid://shopify/Order/987654321',
            'legacyResourceId' => '987654321',
        ],
        'returnLineItems' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReturnLineItem/111',
                        'quantity' => 2,
                        'returnReason' => 'SIZE_TOO_SMALL',
                        'fulfillmentLineItem' => [
                            'lineItem' => [
                                'id' => 'gid://shopify/LineItem/222',
                                'sku' => 'TEST-SKU',
                                'name' => 'Test Product',
                                'variant' => [
                                    'id' => 'gid://shopify/ProductVariant/12345678',
                                    'legacyResourceId' => '12345678',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'reverseFulfillmentOrders' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReverseFulfillmentOrder/333',
                        'status' => 'COMPLETE',
                        'reverseDeliveries' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ReverseDelivery/444',
                                        'deliverable' => [
                                            'tracking' => [
                                                'number' => null,
                                                'url' => null,
                                                'carrierName' => null,
                                            ],
                                            'label' => [
                                                'publicFileUrl' => 'https://example.com/label.pdf',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'lineItems' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ReverseFulfillmentOrderLineItem/555',
                                        'totalQuantity' => 2,
                                        'fulfillmentLineItem' => [
                                            'lineItem' => [
                                                'id' => 'gid://shopify/LineItem/222',
                                                'variant' => [
                                                    'id' => 'gid://shopify/ProductVariant/12345678',
                                                    'legacyResourceId' => '12345678',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $return = $this->mapper->mapReturn($shopifyReturn);

    expect($return)->not->toBeNull()
        ->and($return->status)->toBe(ReturnStatus::LabelGenerated)
        ->and($return->return_tracking_number)->toBeNull()
        ->and($return->return_tracking_url)->toBeNull()
        ->and($return->label_generated_at)->not->toBeNull()
        ->and($return->shipped_at)->toBeNull();
});

test('updates existing return with tracking information', function () {
    // Create initial return without tracking
    $shopifyReturnWithoutTracking = [
        'id' => 'gid://shopify/Return/123458',
        'name' => '#1001.3',
        'status' => 'OPEN',
        'createdAt' => '2025-12-07T10:00:00Z',
        'requestApprovedAt' => '2025-12-07T11:00:00Z',
        'closedAt' => null,
        'totalQuantity' => 2,
        'order' => [
            'id' => 'gid://shopify/Order/987654321',
            'legacyResourceId' => '987654321',
        ],
        'returnLineItems' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReturnLineItem/111',
                        'quantity' => 2,
                        'returnReason' => 'SIZE_TOO_SMALL',
                        'fulfillmentLineItem' => [
                            'lineItem' => [
                                'id' => 'gid://shopify/LineItem/222',
                                'sku' => 'TEST-SKU',
                                'name' => 'Test Product',
                                'variant' => [
                                    'id' => 'gid://shopify/ProductVariant/12345678',
                                    'legacyResourceId' => '12345678',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'reverseFulfillmentOrders' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReverseFulfillmentOrder/333',
                        'status' => 'COMPLETE',
                        'reverseDeliveries' => [
                            'edges' => [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/ReverseDelivery/444',
                                        'deliverable' => [
                                            'tracking' => [
                                                'number' => null,
                                                'url' => null,
                                                'carrierName' => null,
                                            ],
                                            'label' => [
                                                'publicFileUrl' => 'https://example.com/label.pdf',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'lineItems' => [
                            'edges' => [],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $return = $this->mapper->mapReturn($shopifyReturnWithoutTracking);

    expect($return->return_tracking_number)->toBeNull()
        ->and($return->status)->toBe(ReturnStatus::LabelGenerated);

    // Update with tracking info
    $shopifyReturnWithTracking = $shopifyReturnWithoutTracking;
    $shopifyReturnWithTracking['reverseFulfillmentOrders']['edges'][0]['node']['reverseDeliveries']['edges'][0]['node']['deliverable']['tracking'] = [
        'number' => '9876543210',
        'url' => 'https://tracking.example.com/9876543210',
        'carrierName' => 'FedEx',
    ];

    $updatedReturn = $this->mapper->mapReturn($shopifyReturnWithTracking);

    expect($updatedReturn->id)->toBe($return->id)
        ->and($updatedReturn->return_tracking_number)->toBe('9876543210')
        ->and($updatedReturn->return_tracking_url)->toBe('https://tracking.example.com/9876543210')
        ->and($updatedReturn->status)->toBe(ReturnStatus::InTransit)
        ->and($updatedReturn->shipped_at)->not->toBeNull();
});

test('creates return without reverse deliveries', function () {
    $shopifyReturn = [
        'id' => 'gid://shopify/Return/123459',
        'name' => '#1001.4',
        'status' => 'REQUESTED',
        'createdAt' => '2025-12-07T10:00:00Z',
        'requestApprovedAt' => null,
        'closedAt' => null,
        'totalQuantity' => 2,
        'order' => [
            'id' => 'gid://shopify/Order/987654321',
            'legacyResourceId' => '987654321',
        ],
        'returnLineItems' => [
            'edges' => [
                [
                    'node' => [
                        'id' => 'gid://shopify/ReturnLineItem/111',
                        'quantity' => 2,
                        'returnReason' => 'UNWANTED',
                        'fulfillmentLineItem' => [
                            'lineItem' => [
                                'id' => 'gid://shopify/LineItem/222',
                                'sku' => 'TEST-SKU',
                                'name' => 'Test Product',
                                'variant' => [
                                    'id' => 'gid://shopify/ProductVariant/12345678',
                                    'legacyResourceId' => '12345678',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
        'reverseFulfillmentOrders' => [
            'edges' => [],
        ],
    ];

    $return = $this->mapper->mapReturn($shopifyReturn);

    expect($return)->not->toBeNull()
        ->and($return->status)->toBe(ReturnStatus::Requested)
        ->and($return->return_tracking_number)->toBeNull()
        ->and($return->return_tracking_url)->toBeNull()
        ->and($return->label_generated_at)->toBeNull()
        ->and($return->shipped_at)->toBeNull();
});
