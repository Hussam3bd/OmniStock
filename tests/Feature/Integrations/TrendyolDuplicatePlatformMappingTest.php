<?php

use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create currency
    Currency::create([
        'code' => 'TRY',
        'name' => 'Turkish Lira',
        'symbol' => '₺',
        'exchange_rate' => 1.0,
        'is_default' => true,
        'is_active' => true,
    ]);

    // Create Trendyol integration
    $this->integration = Integration::factory()->create([
        'provider' => 'trendyol',
        'type' => 'sales_channel',
        'name' => 'Trendyol Store',
        'is_active' => true,
    ]);
});

test('handles duplicate platform mapping when same trendyol customer arrives in multiple webhooks', function () {
    // This is the exact production scenario that failed
    // Trendyol customer ID: 89185812
    // Two webhooks: Created (masked) → Shipped (real data)

    $trendyolCustomerId = '89185812';

    // First webhook payload - Created status (real data in production)
    $firstWebhook = [
        'id' => 3492910581,
        'shipmentPackageId' => 3492910581,
        'customerId' => 89185812,
        'customerFirstName' => 'Nargıza',
        'customerLastName' => 'Davurova',
        'customerEmail' => 'pf+6p5zrql9@trendyolmail.com',
        'shipmentAddress' => [
            'id' => 8195861782,
            'firstName' => 'Nargıza',
            'lastName' => 'Davurova',
            'phone' => null,
            'fullAddress' => 'sevimli sokak no 2/0     Sarıyer İstanbul',
            'address1' => 'sevimli sokak no 2/0',
            'district' => 'Sarıyer',
            'city' => 'İstanbul',
            'districtId' => 57,
            'cityCode' => 34,
            'neighborhoodId' => 29326,
            'neighborhood' => 'Büyükdere Mah',
        ],
        'invoiceAddress' => [
            'id' => 8195861780,
            'firstName' => 'Nargıza',
            'lastName' => 'Davurova',
            'phone' => null,
            'fullAddress' => 'sevimli sokak no 2/0     Sarıyer İstanbul',
            'address1' => 'sevimli sokak no 2/0',
            'district' => 'Sarıyer',
            'city' => 'İstanbul',
            'districtId' => 57,
            'cityCode' => 34,
            'neighborhoodId' => 29326,
            'neighborhood' => 'Büyükdere Mah',
        ],
        'orderNumber' => '10825920515',
        'status' => 'Created',
        'orderDate' => 1766834431583,
        'grossAmount' => 1350,
        'totalDiscount' => 0,
        'lines' => [
            [
                'id' => 5004134954,
                'barcode' => '869773832054',
                'quantity' => 1,
                'price' => 1350,
                'productName' => 'Altın Detaylı Toka Aksesuarlı Patent Topuklu',
                'merchantSku' => 'REV-0006-ALT-KAH-36',
            ],
        ],
    ];

    // Second webhook payload - Shipped status (same customer)
    $secondWebhook = $firstWebhook;
    $secondWebhook['status'] = 'Shipped';
    $secondWebhook['cargoTrackingNumber'] = 7270029195022402;

    $mapper = app(OrderMapper::class);

    // Process first webhook - should create customer and platform mapping
    $order1 = $mapper->mapOrder($firstWebhook, $this->integration);

    expect($order1)->not->toBeNull()
        ->and($order1->customer)->not->toBeNull()
        ->and($order1->customer->first_name)->toBe('Nargıza')
        ->and($order1->customer->last_name)->toBe('Davurova');

    $customerId1 = $order1->customer_id;

    // Verify platform mapping was created
    $mapping1 = PlatformMapping::where('platform', 'trendyol')
        ->where('platform_id', $trendyolCustomerId)
        ->where('entity_type', Customer::class)
        ->first();

    expect($mapping1)->not->toBeNull()
        ->and($mapping1->entity_id)->toBe($customerId1);

    // Process second webhook - should NOT fail with duplicate platform mapping error
    $order2 = $mapper->mapOrder($secondWebhook, $this->integration);

    expect($order2)->not->toBeNull()
        ->and($order2->customer)->not->toBeNull();

    // Should use the same customer
    expect($order2->customer_id)->toBe($customerId1);

    // Platform mapping should still exist and point to the same customer
    $mapping2 = PlatformMapping::where('platform', 'trendyol')
        ->where('platform_id', $trendyolCustomerId)
        ->where('entity_type', Customer::class)
        ->first();

    expect($mapping2)->not->toBeNull()
        ->and($mapping2->entity_id)->toBe($customerId1);

    // Should only have ONE customer for this Trendyol customer ID
    $totalCustomers = Customer::count();
    expect($totalCustomers)->toBe(1);

    // Should only have ONE platform mapping for this Trendyol customer ID
    $totalMappings = PlatformMapping::where('platform', 'trendyol')
        ->where('platform_id', $trendyolCustomerId)
        ->count();
    expect($totalMappings)->toBe(1);
});

test('moves platform mapping when finding customer by email in subsequent webhooks', function () {
    $trendyolCustomerId = '89185812';

    // Simulate scenario:
    // 1. First webhook creates Customer A with Trendyol ID
    // 2. User manually creates Customer B with same email
    // 3. Second webhook finds Customer B by email, should move mapping

    // Manually create a customer with the email (simulating existing customer)
    $existingCustomer = Customer::create([
        'first_name' => 'Nargıza',
        'last_name' => 'Davurova',
        'email' => 'pf+6p5zrql9@trendyolmail.com',
        'channel' => 'trendyol',
    ]);

    // Create another customer with the platform mapping (simulating first webhook result)
    $oldCustomer = Customer::create([
        'first_name' => 'Trendyol',
        'last_name' => 'Customer',
        'email' => null,
        'channel' => 'trendyol',
    ]);

    $oldCustomer->platformMappings()->create([
        'platform' => 'trendyol',
        'platform_id' => $trendyolCustomerId,
        'entity_type' => Customer::class,
        'platform_data' => ['test' => 'data'],
    ]);

    // Webhook arrives with real data
    $webhook = [
        'id' => 3492910581,
        'shipmentPackageId' => 3492910581,
        'customerId' => 89185812,
        'customerFirstName' => 'Nargıza',
        'customerLastName' => 'Davurova',
        'customerEmail' => 'pf+6p5zrql9@trendyolmail.com',
        'shipmentAddress' => [
            'firstName' => 'Nargıza',
            'lastName' => 'Davurova',
            'phone' => null,
            'fullAddress' => 'sevimli sokak no 2/0',
            'address1' => 'sevimli sokak no 2/0',
            'district' => 'Sarıyer',
            'city' => 'İstanbul',
            'districtId' => 57,
            'cityCode' => 34,
            'neighborhoodId' => 29326,
        ],
        'invoiceAddress' => [
            'firstName' => 'Nargıza',
            'lastName' => 'Davurova',
            'phone' => null,
            'fullAddress' => 'sevimli sokak no 2/0',
            'address1' => 'sevimli sokak no 2/0',
            'district' => 'Sarıyer',
            'city' => 'İstanbul',
            'districtId' => 57,
            'cityCode' => 34,
            'neighborhoodId' => 29326,
        ],
        'orderNumber' => '10825920515',
        'status' => 'Created',
        'orderDate' => 1766834431583,
        'grossAmount' => 1350,
        'totalDiscount' => 0,
        'lines' => [
            [
                'id' => 5004134954,
                'barcode' => '869773832054',
                'quantity' => 1,
                'price' => 1350,
                'productName' => 'Test Product',
                'merchantSku' => 'TEST-SKU',
            ],
        ],
    ];

    $mapper = app(OrderMapper::class);
    $order = $mapper->mapOrder($webhook, $this->integration);

    // Should create order for the existing customer (found by email)
    expect($order->customer_id)->toBe($existingCustomer->id);

    // Platform mapping should now point to existing customer
    $mapping = PlatformMapping::where('platform', 'trendyol')
        ->where('platform_id', $trendyolCustomerId)
        ->first();

    expect($mapping->entity_id)->toBe($existingCustomer->id);

    // Should still only have ONE mapping
    $totalMappings = PlatformMapping::where('platform', 'trendyol')
        ->where('platform_id', $trendyolCustomerId)
        ->count();
    expect($totalMappings)->toBe(1);

    // Old customer should no longer have orders (all migrated by the webhook processing logic happens later)
    // The key point is: no duplicate constraint error occurred!
});
