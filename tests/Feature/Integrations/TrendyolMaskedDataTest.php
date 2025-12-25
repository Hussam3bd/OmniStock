<?php

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create default currency
    Currency::create([
        'code' => 'TRY',
        'name' => 'Turkish Lira',
        'symbol' => '₺',
        'exchange_rate' => 1.0,
        'is_default' => true,
        'is_active' => true,
    ]);

    // Create a Trendyol integration
    $this->integration = Integration::create([
        'name' => 'Test Trendyol',
        'type' => IntegrationType::SALES_CHANNEL,
        'provider' => IntegrationProvider::TRENDYOL,
        'is_active' => true,
        'settings' => [
            'api_key' => 'test_api_key',
            'api_secret' => 'test_secret',
            'seller_id' => '12345',
        ],
    ]);

    $this->mapper = app(OrderMapper::class);
});

test('creates placeholder customer when receiving masked data first (Awaiting status)', function () {
    $maskedPayload = [
        'id' => 3472321512,
        'orderNumber' => '10802199008',
        'status' => 'Awaiting',
        'customerId' => 12345678,
        'customerFirstName' => '***',
        'customerLastName' => '***',
        'customerEmail' => '***',
        'currencyCode' => 'TRY',
        'grossAmount' => 100.00,
        'totalPrice' => 100.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => '***',
            'lastName' => '***',
            'phone' => '***',
            'fullAddress' => '***',
        ],
        'lines' => [],
    ];

    $order = $this->mapper->mapOrder($maskedPayload, $this->integration);

    expect($order)->toBeInstanceOf(Order::class)
        ->and($order->customer)->toBeInstanceOf(Customer::class)
        ->and($order->customer->first_name)->toBe('Trendyol')
        ->and($order->customer->last_name)->toBe('Customer')
        ->and($order->customer->notes)->toBe('Customer data pending from Trendyol')
        ->and($order->customer->email)->toBeNull();
});

test('updates customer with real data when second webhook arrives (Created status)', function () {
    // First webhook with masked data
    $maskedPayload = [
        'id' => 3472321512,
        'orderNumber' => '10802199008',
        'status' => 'Awaiting',
        'customerId' => 12345678,
        'customerFirstName' => '***',
        'customerLastName' => '***',
        'customerEmail' => '***',
        'currencyCode' => 'TRY',
        'grossAmount' => 100.00,
        'totalPrice' => 100.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => '***',
            'lastName' => '***',
            'phone' => '***',
            'fullAddress' => '***',
        ],
        'lines' => [],
    ];

    $order = $this->mapper->mapOrder($maskedPayload, $this->integration);
    $customerId = $order->customer->id;

    // Second webhook with real data
    $realPayload = [
        'id' => 3472321512,
        'orderNumber' => '10802199008',
        'status' => 'Created',
        'customerId' => 12345678,
        'customerFirstName' => 'Nida',
        'customerLastName' => 'Kaya',
        'customerEmail' => 'pf+e9vxknbl@trendyolmail.com',
        'currencyCode' => 'TRY',
        'grossAmount' => 100.00,
        'totalPrice' => 100.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => 'Nida',
            'lastName' => 'Kaya',
            'phone' => '5551234567',
            'fullAddress' => 'Test Address 123',
            'city' => 'Istanbul',
            'district' => 'Kadikoy',
        ],
        'lines' => [],
    ];

    $updatedOrder = $this->mapper->mapOrder($realPayload, $this->integration);

    expect($updatedOrder->id)->toBe($order->id)
        ->and($updatedOrder->customer->id)->toBe($customerId)
        ->and($updatedOrder->customer->first_name)->toBe('Nida')
        ->and($updatedOrder->customer->last_name)->toBe('Kaya')
        ->and($updatedOrder->customer->email)->toBe('pf+e9vxknbl@trendyolmail.com')
        ->and($updatedOrder->customer->phone)->toBe('+905551234567') // Phone is normalized with +90
        ->and($updatedOrder->customer->notes)->toBeNull();
});

test('creates customer with real data when Created status comes first', function () {
    $realPayload = [
        'id' => 3472565057,
        'orderNumber' => '10802489712',
        'status' => 'Created',
        'customerId' => 87654321,
        'customerFirstName' => 'Deniz',
        'customerLastName' => 'Yilmaz',
        'customerEmail' => 'pf+6pnja5pm@trendyolmail.com',
        'currencyCode' => 'TRY',
        'grossAmount' => 150.00,
        'totalPrice' => 150.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => 'Deniz',
            'lastName' => 'Yilmaz',
            'phone' => '5559876543',
            'fullAddress' => 'Real Address 456',
            'city' => 'Ankara',
            'district' => 'Cankaya',
        ],
        'lines' => [],
    ];

    $order = $this->mapper->mapOrder($realPayload, $this->integration);

    expect($order->customer->first_name)->toBe('Deniz')
        ->and($order->customer->last_name)->toBe('Yilmaz')
        ->and($order->customer->email)->toBe('pf+6pnja5pm@trendyolmail.com')
        ->and($order->customer->phone)->toBe('+905559876543') // Phone is normalized with +90
        ->and($order->customer->notes)->not->toBe('Customer data pending from Trendyol');
});

test('does not overwrite real data with masked data if Awaiting webhook arrives late', function () {
    // First webhook with real data
    $realPayload = [
        'id' => 3472565057,
        'orderNumber' => '10802489712',
        'status' => 'Created',
        'customerId' => 87654321,
        'customerFirstName' => 'Deniz',
        'customerLastName' => 'Yilmaz',
        'customerEmail' => 'pf+6pnja5pm@trendyolmail.com',
        'currencyCode' => 'TRY',
        'grossAmount' => 150.00,
        'totalPrice' => 150.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => 'Deniz',
            'lastName' => 'Yilmaz',
            'phone' => '5559876543',
            'fullAddress' => 'Real Address 456',
        ],
        'lines' => [],
    ];

    $order = $this->mapper->mapOrder($realPayload, $this->integration);

    // Second webhook with masked data (late Awaiting status)
    $maskedPayload = [
        'id' => 3472565057,
        'orderNumber' => '10802489712',
        'status' => 'Awaiting',
        'customerId' => 87654321,
        'customerFirstName' => '***',
        'customerLastName' => '***',
        'customerEmail' => '***',
        'currencyCode' => 'TRY',
        'grossAmount' => 150.00,
        'totalPrice' => 150.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => '***',
            'lastName' => '***',
            'phone' => '***',
            'fullAddress' => '***',
        ],
        'lines' => [],
    ];

    $updatedOrder = $this->mapper->mapOrder($maskedPayload, $this->integration);

    // Customer data should NOT be overwritten with masked data
    expect($updatedOrder->customer->first_name)->toBe('Deniz')
        ->and($updatedOrder->customer->last_name)->toBe('Yilmaz')
        ->and($updatedOrder->customer->email)->toBe('pf+6pnja5pm@trendyolmail.com')
        ->and($updatedOrder->customer->phone)->toBe('+905559876543'); // Phone is normalized with +90
});

test('creates addresses when real data arrives after masked data', function () {
    // First webhook with masked data
    // Note: Addresses are still created with masked data initially
    $maskedPayload = [
        'id' => 3473807437,
        'orderNumber' => '10804060014',
        'status' => 'Awaiting',
        'customerId' => 99999999,
        'customerFirstName' => '***',
        'customerLastName' => '***',
        'customerEmail' => '***',
        'currencyCode' => 'TRY',
        'grossAmount' => 200.00,
        'totalPrice' => 200.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'phone' => '***',
            'fullAddress' => '***',
        ],
        'lines' => [],
    ];

    $order = $this->mapper->mapOrder($maskedPayload, $this->integration);

    $initialShippingAddressId = $order->shipping_address_id;
    $initialBillingAddressId = $order->billing_address_id;

    // Second webhook with real data including addresses
    $realPayload = [
        'id' => 3473807437,
        'orderNumber' => '10804060014',
        'status' => 'Created',
        'customerId' => 99999999,
        'customerFirstName' => 'Seval',
        'customerLastName' => 'Demir',
        'customerEmail' => 'pf+qqkwav82@trendyolmail.com',
        'currencyCode' => 'TRY',
        'grossAmount' => 200.00,
        'totalPrice' => 200.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => 'Seval',
            'lastName' => 'Demir',
            'phone' => '5551112233',
            'fullAddress' => 'Complete Address 789',
            'city' => 'Izmir',
            'district' => 'Konak',
        ],
        'invoiceAddress' => [
            'firstName' => 'Seval',
            'lastName' => 'Demir',
            'phone' => '5551112233',
            'fullAddress' => 'Billing Address 789',
            'city' => 'Izmir',
            'district' => 'Konak',
        ],
        'lines' => [],
    ];

    $updatedOrder = $this->mapper->mapOrder($realPayload, $this->integration);

    // Reload order to get fresh address data
    $updatedOrder = $updatedOrder->fresh(['shippingAddress', 'billingAddress']);

    // Addresses should be created/updated with real data
    expect($updatedOrder->shipping_address_id)->not->toBeNull()
        ->and($updatedOrder->billing_address_id)->not->toBeNull()
        ->and($updatedOrder->shippingAddress)->not->toBeNull()
        ->and($updatedOrder->billingAddress)->not->toBeNull()
        // Verify addresses were updated with real data (not masked anymore)
        ->and($updatedOrder->shippingAddress->address_line1)->toBe('Complete Address 789')
        ->and($updatedOrder->billingAddress->address_line1)->toBe('Billing Address 789')
        ->and($updatedOrder->shippingAddress->first_name)->toBe('Seval')
        ->and($updatedOrder->billingAddress->first_name)->toBe('Seval')
        ->and($updatedOrder->shippingAddress->phone)->toBe('+905551112233')
        ->and($updatedOrder->billingAddress->phone)->toBe('+905551112233');
});

test('prevents duplicate customers when masked data customer gets real data', function () {
    // First order with MASKED data - creates placeholder customer
    $maskedPayload = [
        'id' => 3500000001,
        'orderNumber' => '10900000001',
        'status' => 'Awaiting',
        'customerId' => 88888888,
        'customerFirstName' => '***',
        'customerLastName' => '***',
        'customerEmail' => '***',
        'currencyCode' => 'TRY',
        'grossAmount' => 100.00,
        'totalPrice' => 100.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => '***',
            'lastName' => '***',
            'phone' => '***',
            'fullAddress' => '***',
        ],
        'lines' => [],
    ];

    $firstOrder = $this->mapper->mapOrder($maskedPayload, $this->integration);
    $firstCustomerId = $firstOrder->customer_id;

    // Second order with SAME Trendyol customer ID but now with REAL data
    $realPayload = [
        'id' => 3500000002,
        'orderNumber' => '10900000002',
        'status' => 'Created',
        'customerId' => 88888888, // Same Trendyol ID
        'customerFirstName' => 'Ali',
        'customerLastName' => 'Yılmaz',
        'customerEmail' => 'ali.yilmaz@example.com',
        'currencyCode' => 'TRY',
        'grossAmount' => 200.00,
        'totalPrice' => 200.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => 'Ali',
            'lastName' => 'Yılmaz',
            'phone' => '5557654321',
            'fullAddress' => 'Test Address 2',
        ],
        'lines' => [],
    ];

    $secondOrder = $this->mapper->mapOrder($realPayload, $this->integration);

    // Should reuse the same customer and update it with real data
    expect($secondOrder->customer_id)->toBe($firstCustomerId)
        ->and(\App\Models\Customer\Customer::where('email', 'ali.yilmaz@example.com')->count())->toBe(1);

    // Customer should now have real data
    $customer = \App\Models\Customer\Customer::find($firstCustomerId);
    expect($customer->first_name)->toBe('Ali')
        ->and($customer->last_name)->toBe('Yılmaz')
        ->and($customer->email)->toBe('ali.yilmaz@example.com');

    // Should have platform mapping created
    $mapping = \App\Models\Platform\PlatformMapping::where('entity_type', \App\Models\Customer\Customer::class)
        ->where('entity_id', $firstCustomerId)
        ->where('platform', \App\Enums\Order\OrderChannel::TRENDYOL->value)
        ->first();

    expect($mapping)->not->toBeNull()
        ->and($mapping->platform_id)->toBe('88888888');
});

test('platform mapping is updated only with real data', function () {
    // First webhook with masked data
    $maskedPayload = [
        'id' => 3488571893,
        'orderNumber' => '10820615816',
        'status' => 'Awaiting',
        'customerId' => 11111111,
        'customerFirstName' => '***',
        'customerLastName' => '***',
        'customerEmail' => '***',
        'currencyCode' => 'TRY',
        'grossAmount' => 75.00,
        'totalPrice' => 75.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'phone' => '***',
            'fullAddress' => '***',
        ],
        'lines' => [],
    ];

    $order = $this->mapper->mapOrder($maskedPayload, $this->integration);

    $customerMapping = PlatformMapping::where('entity_type', Customer::class)
        ->where('entity_id', $order->customer->id)
        ->where('platform', OrderChannel::TRENDYOL->value)
        ->first();

    // Platform data should not be stored when data is masked (our fix)
    expect($customerMapping)->toBeInstanceOf(PlatformMapping::class)
        ->and($customerMapping->platform_data['shipment_address'] ?? null)->toBeNull();

    // Second webhook with real data
    $realPayload = [
        'id' => 3488571893,
        'orderNumber' => '10820615816',
        'status' => 'Created',
        'customerId' => 11111111,
        'customerFirstName' => 'Ahmet',
        'customerLastName' => 'Yildirim',
        'customerEmail' => 'pf+test123@trendyolmail.com',
        'currencyCode' => 'TRY',
        'grossAmount' => 75.00,
        'totalPrice' => 75.00,
        'orderDate' => now()->timestamp * 1000,
        'shipmentAddress' => [
            'firstName' => 'Ahmet',
            'lastName' => 'Yildirim',
            'phone' => '5554445566',
            'fullAddress' => 'Real Address Information',
            'city' => 'Bursa',
        ],
        'lines' => [],
    ];

    $updatedOrder = $this->mapper->mapOrder($realPayload, $this->integration);

    $updatedMapping = PlatformMapping::where('entity_type', Customer::class)
        ->where('entity_id', $updatedOrder->customer->id)
        ->where('platform', OrderChannel::TRENDYOL->value)
        ->first();

    // Platform data should now contain real address data
    expect($updatedMapping->platform_data['shipment_address']['fullAddress'] ?? null)->toBe('Real Address Information');
});
