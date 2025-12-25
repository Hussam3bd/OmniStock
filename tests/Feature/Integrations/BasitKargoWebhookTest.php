<?php

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Jobs\ProcessBasitKargoWebhook;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\WebhookClient\Models\WebhookCall;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create a BasitKargo integration
    $this->integration = Integration::create([
        'name' => 'Test BasitKargo',
        'type' => IntegrationType::SHIPPING_PROVIDER,
        'provider' => IntegrationProvider::BASIT_KARGO,
        'is_active' => true,
        'settings' => [
            'api_token' => 'test_api_key',
        ],
    ]);

    // Create a customer
    $this->customer = Customer::create([
        'email' => 'test@example.com',
        'first_name' => 'Test',
        'last_name' => 'Customer',
    ]);

    // Create a test order
    $this->order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-WEBHOOK-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'currency' => 'TRY',
        'order_date' => now(),
        'shipping_tracking_number' => '13251188161213',
        'shipping_aggregator_shipment_id' => 'QR3-KGO-TJD',
    ]);
});

test('processes simple webhook with DELIVERED status', function () {
    $payload = [
        'id' => 'QR3-KGO-TJD',
        'status' => 'DELIVERED',
        'barcode' => '4230273307',
        'handler' => [
            'code' => 'SURAT',
            'name' => 'Sürat Kargo',
        ],
        'handlerShipmentCode' => '13251188161213',
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    expect($this->order->fresh()->shipping_tracking_number)->toBe('13251188161213');
});

test('processes detailed webhook with COMPLETED status', function () {
    Http::fake([
        '*/v2/order/QR3-KGO-TJD' => Http::response([
            'id' => 'QR3-KGO-TJD',
            'status' => 'COMPLETED',
            'barcode' => '4230273307',
            'shipmentInfo' => [
                'lastState' => 'Teslim Edildi',
                'handlerShipmentCode' => '13251188161213',
                'handlerDesi' => 2,
            ],
            'priceInfo' => [
                'shipmentFee' => 79.96,
            ],
            'traces' => [
                [
                    'time' => '2025-12-25T15:14:45',
                    'status' => 'Teslim Edildi',
                    'location' => 'Küçükyalı',
                ],
            ],
        ], 200),
    ]);

    $payload = [
        'id' => 'QR3-KGO-TJD',
        'type' => 'OUTGOING',
        'status' => 'COMPLETED',
        'barcode' => '4230273307',
        'shipmentInfo' => [
            'handler' => [
                'code' => 'SURAT',
                'name' => 'Sürat Kargo',
            ],
            'lastState' => 'Teslim Edildi',
            'handlerShipmentCode' => '13251188161213',
            'deliveredTime' => '2025-12-25T15:15:03.233',
        ],
        'traces' => [
            [
                'time' => '2025-12-25T15:14:45',
                'status' => 'Teslim Edildi',
                'location' => 'Küçükyalı',
            ],
        ],
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/order/QR3-KGO-TJD');
    });
});

test('detects distribution center status from webhook with Kargo Devir', function () {
    Event::fake();

    Http::fake([
        '*/v2/order/GKG-MMZ-4Y4' => Http::response([
            'id' => 'GKG-MMZ-4Y4',
            'status' => 'SHIPPED',
            'barcode' => '5679285576',
            'shipmentInfo' => [
                'lastState' => 'Kargo Devir',
                'handlerShipmentCode' => '10273841481375',
                'handlerDesi' => 2,
            ],
            'priceInfo' => [
                'shipmentFee' => 79.96,
            ],
            'traces' => [
                [
                    'time' => '2025-12-24T16:06:45',
                    'status' => 'Kargo Devir',
                    'location' => 'Yaşamkent',
                    'locationDetail' => 'Yaşamkent Şubesi',
                ],
            ],
        ], 200),
    ]);

    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-DC-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'currency' => 'TRY',
        'order_date' => now(),
        'shipping_tracking_number' => '10273841481375',
        'shipping_aggregator_shipment_id' => 'GKG-MMZ-4Y4',
    ]);

    $payload = [
        'id' => 'GKG-MMZ-4Y4',
        'type' => 'OUTGOING',
        'status' => 'SHIPPED',
        'barcode' => '5679285576',
        'shipmentInfo' => [
            'handler' => [
                'code' => 'SURAT',
                'name' => 'Sürat Kargo',
            ],
            'lastState' => 'Kargo Devir',
            'handlerShipmentCode' => '10273841481375',
        ],
        'traces' => [
            [
                'time' => '2025-12-24T16:06:45',
                'status' => 'Kargo Devir',
                'location' => 'Yaşamkent',
            ],
        ],
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    expect($order->fresh()->fulfillment_status)
        ->toBe(FulfillmentStatus::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER);

    Event::assertDispatched(\App\Events\Order\OrderAwaitingCustomerPickup::class);
});

test('handles webhook when BasitKargo API returns error gracefully', function () {
    Http::fake([
        '*/v2/order/TEST-ID-123' => Http::response('Kargo bulunamadı', 500),
    ]);

    // Need to match the order by one of the identifiers
    $this->order->update(['shipping_aggregator_shipment_id' => 'TEST-ID-123']);

    $payload = [
        'id' => 'TEST-ID-123',
        'type' => 'OUTGOING',
        'status' => 'SHIPPED',
        'barcode' => '1234567890',
        'shipmentInfo' => [
            'handler' => [
                'code' => 'SURAT',
                'name' => 'Sürat Kargo',
            ],
            'lastState' => 'Kargoya Verildi',
            'handlerShipmentCode' => '9999999999',
        ],
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    // This should not throw an exception even though API fails
    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    // Verify API was called
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/order/TEST-ID-123');
    });

    expect(true)->toBeTrue(); // Job completed without throwing exception
});

test('calls correct API endpoint based on available identifier', function () {
    Http::fake([
        '*/v2/order/TEST-ID' => Http::response([
            'id' => 'TEST-ID',
            'status' => 'SHIPPED',
            'barcode' => '1234567890',
            'shipmentInfo' => [
                'lastState' => 'Shipped',
                'handlerShipmentCode' => '9999999999',
                'handlerDesi' => 2,
            ],
            'priceInfo' => [
                'shipmentFee' => 50.00,
            ],
        ], 200),
    ]);

    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-PRIORITY-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'currency' => 'TRY',
        'order_date' => now(),
        'shipping_tracking_number' => '1234567890',
    ]);

    $payload = [
        'id' => 'TEST-ID',
        'type' => 'OUTGOING',
        'status' => 'SHIPPED',
        'barcode' => '1234567890',
        'shipmentInfo' => [
            'lastState' => 'Shipped',
            'handlerShipmentCode' => '9999999999',
        ],
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    // Verify it only called the ID endpoint (highest priority)
    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/v2/order/TEST-ID');
    });

    // Verify it didn't try other endpoints
    Http::assertSentCount(1);
});

test('updates order identifiers from webhook payload', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-UPDATE-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'currency' => 'TRY',
        'order_date' => now(),
        'shipping_tracking_number' => '1111111111', // Match by barcode initially
        'shipping_aggregator_shipment_id' => null,
        'shipping_aggregator_integration_id' => null,
    ]);

    $payload = [
        'id' => 'NEW-SHIPMENT-ID',
        'status' => 'READY_TO_SHIP',
        'barcode' => '1111111111',
        'handlerShipmentCode' => '2222222222',
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    $order = $order->fresh();

    // Simple webhook doesn't update tracking number unless it's null
    // But should update shipment ID and integration ID
    expect($order->shipping_aggregator_shipment_id)->toBe('NEW-SHIPMENT-ID')
        ->and($order->shipping_aggregator_integration_id)->toBe($this->integration->id);
});

test('handles webhook with missing integration id', function () {
    $payload = [
        'id' => 'TEST-ID',
        'status' => 'SHIPPED',
        'barcode' => '1234567890',
        // Missing _basitkargo_integration_id
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    // Should log and return gracefully without throwing exception
    expect(true)->toBeTrue();
});

test('handles webhook with invalid integration id', function () {
    $payload = [
        'id' => 'TEST-ID',
        'status' => 'SHIPPED',
        'barcode' => '1234567890',
        '_basitkargo_integration_id' => 99999, // Non-existent ID
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    // Should log and return gracefully without throwing exception
    expect(true)->toBeTrue();
});

test('handles webhook when no matching order found', function () {
    $payload = [
        'id' => 'UNKNOWN-SHIPMENT',
        'status' => 'SHIPPED',
        'barcode' => '9876543210',
        'handlerShipmentCode' => '9999999999',
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    // Should log and return gracefully without throwing exception
    expect(true)->toBeTrue();
});

test('extracts handlerShipmentCode from nested shipmentInfo', function () {
    Http::fake([
        '*/v2/order/TEST-ID' => Http::response([
            'id' => 'TEST-ID',
            'status' => 'SHIPPED',
            'barcode' => '1234567890',
            'shipmentInfo' => [
                'handlerShipmentCode' => 'NESTED-CODE-123',
                'handlerDesi' => 2,
                'lastState' => 'Shipped',
            ],
            'priceInfo' => [
                'shipmentFee' => 50.00,
            ],
        ], 200),
    ]);

    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-NESTED-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 10000,
        'total_amount' => 10000,
        'currency' => 'TRY',
        'order_date' => now(),
        'shipping_tracking_number' => null, // Start with null so it can be filled
        'shipping_aggregator_shipment_id' => 'TEST-ID', // Match by ID
    ]);

    $payload = [
        'id' => 'TEST-ID',
        'status' => 'SHIPPED',
        'barcode' => '1234567890',
        // handlerShipmentCode in shipmentInfo (nested) - this is a detailed webhook
        'shipmentInfo' => [
            'handlerShipmentCode' => 'NESTED-CODE-123',
            'lastState' => 'Shipped',
        ],
        '_basitkargo_integration_id' => $this->integration->id,
    ];

    $webhookCall = WebhookCall::create([
        'name' => 'basitkargo',
        'url' => 'https://test.com/webhooks/basitkargo',
        'payload' => $payload,
    ]);

    $job = new ProcessBasitKargoWebhook($webhookCall);
    $job->handle();

    // Should extract from API response via sync service (only fills if null)
    expect($order->fresh()->shipping_tracking_number)->toBe('NESTED-CODE-123');
});
