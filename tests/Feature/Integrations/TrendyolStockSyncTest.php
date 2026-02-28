<?php

use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Platform\PlatformMapping;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->integration = Integration::factory()->create([
        'provider' => 'trendyol',
        'is_active' => true,
        'settings' => [
            'api_key' => 'test_key',
            'api_secret' => 'test_secret',
            'supplier_id' => '12345',
        ],
    ]);

    $this->product = Product::create([
        'title' => 'Test Product',
        'status' => 'active',
    ]);

    $this->adapter = new TrendyolAdapter($this->integration);
});

test('syncStock fetches trendyol prices and sends correct payload', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'SYNC-TEST-001',
        'barcode' => 'SYNC001',
        'price' => 5000,
        'inventory_quantity' => 25,
    ]);

    PlatformMapping::create([
        'platform' => OrderChannel::TRENDYOL->value,
        'platform_id' => 'ty-123',
        'entity_type' => ProductVariant::class,
        'entity_id' => $variant->id,
        'platform_data' => ['barcode' => '8680000000001'],
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([
            'content' => [
                [
                    'barcode' => '8680000000001',
                    'salePrice' => 199.99,
                    'listPrice' => 249.99,
                ],
            ],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-abc-123',
        ], 200),
    ]);

    $variant->load('platformMappings');
    $result = $this->adapter->syncStock(collect([$variant]));

    expect($result['success'])->toBeTrue()
        ->and($result['synced'])->toBe(1)
        ->and($result['skipped'])->toBe(0)
        ->and($result['batchId'])->toBe('batch-abc-123');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'price-and-inventory')) {
            return true;
        }

        $items = $request->data()['items'] ?? [];

        return count($items) === 1
            && $items[0]['barcode'] === '8680000000001'
            && $items[0]['quantity'] === 25
            && $items[0]['salePrice'] === 199.99
            && $items[0]['listPrice'] === 249.99;
    });
});

test('syncStock batches multiple variants in a single request', function () {
    $variants = collect();

    for ($i = 1; $i <= 3; $i++) {
        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => "BATCH-TEST-{$i}",
            'barcode' => "BATCH{$i}",
            'price' => 5000,
            'inventory_quantity' => $i * 10,
        ]);

        PlatformMapping::create([
            'platform' => OrderChannel::TRENDYOL->value,
            'platform_id' => "ty-batch-{$i}",
            'entity_type' => ProductVariant::class,
            'entity_id' => $variant->id,
            'platform_data' => ['barcode' => "868000000000{$i}"],
        ]);

        $variant->load('platformMappings');
        $variants->push($variant);
    }

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([
            'content' => [
                ['barcode' => '8680000000001', 'salePrice' => 100.00, 'listPrice' => 120.00],
            ],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-multi-123',
        ], 200),
    ]);

    $result = $this->adapter->syncStock($variants);

    expect($result['success'])->toBeTrue()
        ->and($result['synced'])->toBe(3)
        ->and($result['skipped'])->toBe(0);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'price-and-inventory')) {
            return true;
        }

        $items = $request->data()['items'] ?? [];

        return count($items) === 3;
    });
});

test('syncStock skips variants without trendyol mapping', function () {
    $variantWithMapping = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'WITH-MAPPING',
        'barcode' => 'WM001',
        'price' => 5000,
        'inventory_quantity' => 10,
    ]);

    PlatformMapping::create([
        'platform' => OrderChannel::TRENDYOL->value,
        'platform_id' => 'ty-mapped',
        'entity_type' => ProductVariant::class,
        'entity_id' => $variantWithMapping->id,
        'platform_data' => ['barcode' => '8680000000099'],
    ]);

    $variantWithoutMapping = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'NO-MAPPING',
        'barcode' => 'NM001',
        'price' => 5000,
        'inventory_quantity' => 20,
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([
            'content' => [
                ['barcode' => '8680000000099', 'salePrice' => 50.00, 'listPrice' => 60.00],
            ],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-skip',
        ], 200),
    ]);

    $variants = collect([$variantWithMapping, $variantWithoutMapping]);
    $variants->each->load('platformMappings');

    $result = $this->adapter->syncStock($variants);

    expect($result['synced'])->toBe(1)
        ->and($result['skipped'])->toBe(1);
});

test('syncStock falls back to platform_data prices when trendyol fetch fails', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'FALLBACK-TEST',
        'barcode' => 'FB001',
        'price' => 5000,
        'inventory_quantity' => 15,
    ]);

    PlatformMapping::create([
        'platform' => OrderChannel::TRENDYOL->value,
        'platform_id' => 'ty-fallback',
        'entity_type' => ProductVariant::class,
        'entity_id' => $variant->id,
        'platform_data' => [
            'barcode' => '8680000000055',
            'salePrice' => 89.99,
            'listPrice' => 109.99,
        ],
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([], 500),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-fallback',
        ], 200),
    ]);

    $variant->load('platformMappings');
    $result = $this->adapter->syncStock(collect([$variant]));

    expect($result['success'])->toBeTrue()
        ->and($result['synced'])->toBe(1);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'price-and-inventory')) {
            return true;
        }

        $items = $request->data()['items'] ?? [];

        return count($items) === 1
            && $items[0]['salePrice'] === 89.99
            && $items[0]['listPrice'] === 109.99;
    });
});

test('syncStock returns error gracefully when api fails', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'API-FAIL-TEST',
        'barcode' => 'AF001',
        'price' => 5000,
        'inventory_quantity' => 5,
    ]);

    PlatformMapping::create([
        'platform' => OrderChannel::TRENDYOL->value,
        'platform_id' => 'ty-fail',
        'entity_type' => ProductVariant::class,
        'entity_id' => $variant->id,
        'platform_data' => ['barcode' => '8680000000077'],
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([
            'content' => [
                ['barcode' => '8680000000077', 'salePrice' => 30.00, 'listPrice' => 40.00],
            ],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'error' => 'Internal Server Error',
        ], 500),
    ]);

    $variant->load('platformMappings');
    $result = $this->adapter->syncStock(collect([$variant]));

    expect($result['success'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

test('syncStock uses inventory_quantity not stock_quantity', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'INV-QTY-TEST',
        'barcode' => 'IQ001',
        'price' => 5000,
        'inventory_quantity' => 42,
    ]);

    PlatformMapping::create([
        'platform' => OrderChannel::TRENDYOL->value,
        'platform_id' => 'ty-inv',
        'entity_type' => ProductVariant::class,
        'entity_id' => $variant->id,
        'platform_data' => ['barcode' => '8680000000088'],
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([
            'content' => [
                ['barcode' => '8680000000088', 'salePrice' => 75.00, 'listPrice' => 90.00],
            ],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-inv',
        ], 200),
    ]);

    $variant->load('platformMappings');
    $this->adapter->syncStock(collect([$variant]));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'price-and-inventory')) {
            return true;
        }

        $items = $request->data()['items'] ?? [];

        return count($items) === 1 && $items[0]['quantity'] === 42;
    });
});

test('syncStock returns zero synced when no variants have mappings', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'NO-MAP-TEST',
        'barcode' => 'NM001',
        'price' => 5000,
        'inventory_quantity' => 10,
    ]);

    $variant->load('platformMappings');
    $result = $this->adapter->syncStock(collect([$variant]));

    expect($result['success'])->toBeTrue()
        ->and($result['synced'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['batchId'])->toBeNull();

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'price-and-inventory');
    });
});
