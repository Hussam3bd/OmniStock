<?php

use App\Models\Integration\Integration;
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

test('syncStock fetches trendyol prices by variant barcode and sends correct payload', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'SYNC-TEST-001',
        'barcode' => '8680000000001',
        'price' => 5000,
        'inventory_quantity' => 25,
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
            'barcode' => "868000000000{$i}",
            'price' => 5000,
            'inventory_quantity' => $i * 10,
        ]);

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

test('syncStock skips variants not found on trendyol', function () {
    $variantOnTrendyol = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'ON-TRENDYOL',
        'barcode' => '8680000000099',
        'price' => 5000,
        'inventory_quantity' => 10,
    ]);

    $variantNotOnTrendyol = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'NOT-ON-TRENDYOL',
        'barcode' => '9999999999999',
        'price' => 5000,
        'inventory_quantity' => 20,
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products?barcode=8680000000099' => Http::response([
            'content' => [
                ['barcode' => '8680000000099', 'salePrice' => 50.00, 'listPrice' => 60.00],
            ],
        ], 200),
        'https://apigw.trendyol.com/integration/product/sellers/12345/products?barcode=9999999999999' => Http::response([
            'content' => [],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-skip',
        ], 200),
    ]);

    $result = $this->adapter->syncStock(collect([$variantOnTrendyol, $variantNotOnTrendyol]));

    expect($result['synced'])->toBe(1)
        ->and($result['skipped'])->toBe(1);
});

test('syncStock skips variants without barcode', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'NO-BARCODE-TEST',
        'barcode' => '',
        'price' => 5000,
        'inventory_quantity' => 15,
    ]);

    $result = $this->adapter->syncStock(collect([$variant]));

    expect($result['success'])->toBeTrue()
        ->and($result['synced'])->toBe(0)
        ->and($result['skipped'])->toBe(1)
        ->and($result['batchId'])->toBeNull();

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), 'price-and-inventory');
    });
});

test('syncStock returns error gracefully when price-and-inventory api fails', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'API-FAIL-TEST',
        'barcode' => '8680000000077',
        'price' => 5000,
        'inventory_quantity' => 5,
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

    $result = $this->adapter->syncStock(collect([$variant]));

    expect($result['success'])->toBeFalse()
        ->and($result['errors'])->not->toBeEmpty();
});

test('syncStock uses inventory_quantity not stock_quantity', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'INV-QTY-TEST',
        'barcode' => '8680000000088',
        'price' => 5000,
        'inventory_quantity' => 42,
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

    $this->adapter->syncStock(collect([$variant]));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'price-and-inventory')) {
            return true;
        }

        $items = $request->data()['items'] ?? [];

        return count($items) === 1 && $items[0]['quantity'] === 42;
    });
});

test('syncStock sends 0 instead of negative inventory', function () {
    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'NEG-STOCK-TEST',
        'barcode' => '8680000000044',
        'price' => 5000,
        'inventory_quantity' => -3,
    ]);

    Http::fake([
        'https://apigw.trendyol.com/integration/product/sellers/12345/products*' => Http::response([
            'content' => [
                ['barcode' => '8680000000044', 'salePrice' => 50.00, 'listPrice' => 60.00],
            ],
        ], 200),
        'https://api.trendyol.com/sapigw/suppliers/12345/products/price-and-inventory' => Http::response([
            'batchRequestId' => 'batch-neg',
        ], 200),
    ]);

    $this->adapter->syncStock(collect([$variant]));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'price-and-inventory')) {
            return true;
        }

        $items = $request->data()['items'] ?? [];

        return count($items) === 1 && $items[0]['quantity'] === 0;
    });
});
