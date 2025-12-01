<?php

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\TrendyolAdapter;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('can authenticate with trendyol api', function () {
    Http::fake([
        'https://api.trendyol.com/sapigw/suppliers/*/orders' => Http::response(
            ['content' => []],
            200
        ),
    ]);

    $integration = Integration::factory()->create([
        'type' => 'sales_channel',
        'provider' => 'trendyol',
        'settings' => [
            'api_key' => 'test_api_key',
            'api_secret' => 'test_api_secret',
            'supplier_id' => '2738',
        ],
    ]);

    $adapter = new TrendyolAdapter($integration);

    expect($adapter->authenticate())->toBeTrue();
});

test('can fetch orders from trendyol', function () {
    $mockOrders = [
        'content' => [
            [
                'id' => 123456,
                'orderNumber' => 'TY-123456',
                'status' => 'CREATED',
                'orderDate' => now()->timestamp * 1000,
                'customerId' => 'CUST-001',
                'lines' => [
                    [
                        'id' => 1,
                        'barcode' => '8680000000486',
                        'quantity' => 2,
                        'price' => 100.00,
                    ],
                ],
                'shipmentAddress' => [
                    'fullName' => 'Test User',
                    'phone' => '5555555555',
                    'city' => 'Istanbul',
                ],
            ],
        ],
    ];

    Http::fake([
        'https://api.trendyol.com/sapigw/suppliers/*/orders*' => Http::response($mockOrders, 200),
    ]);

    $integration = Integration::factory()->create([
        'type' => 'sales_channel',
        'provider' => 'trendyol',
        'settings' => [
            'api_key' => 'test_api_key',
            'api_secret' => 'test_api_secret',
            'supplier_id' => '2738',
        ],
    ]);

    $adapter = new TrendyolAdapter($integration);
    $orders = $adapter->fetchOrders();

    expect($orders)->toHaveCount(1)
        ->and($orders->first()['orderNumber'])->toBe('TY-123456');
});
