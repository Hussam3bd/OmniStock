<?php

use App\Enums\Order\OrderChannel;
use App\Jobs\Integration\SyncVariantStockToChannelsJob;
use App\Models\Integration\Integration;
use App\Models\Platform\PlatformMapping;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->product = Product::create(['title' => 'AutoSync Product', 'status' => 'active']);
});

function makeShopifyIntegration(bool $autoSync = true): Integration
{
    return Integration::factory()->create([
        'provider' => 'shopify',
        'is_active' => true,
        'settings' => [
            'shop_domain' => 'test-store.myshopify.com',
            'access_token' => 'fake_token',
            'shopify_location_id' => '99999',
            'auto_sync_stock' => $autoSync,
        ],
    ]);
}

function makeTrendyolIntegration(bool $autoSync = true): Integration
{
    return Integration::factory()->create([
        'provider' => 'trendyol',
        'is_active' => true,
        'settings' => [
            'api_key' => 'k',
            'api_secret' => 's',
            'supplier_id' => '12345',
            'auto_sync_stock' => $autoSync,
        ],
    ]);
}

function mapVariantToShopify(ProductVariant $variant, string $inventoryItemId = '11111'): void
{
    PlatformMapping::create([
        'platform' => OrderChannel::SHOPIFY->value,
        'entity_type' => ProductVariant::class,
        'entity_id' => $variant->id,
        'platform_id' => '47000000000000',
        'platform_data' => ['inventory_item_id' => $inventoryItemId],
    ]);
}

function mapVariantToTrendyol(ProductVariant $variant): void
{
    PlatformMapping::create([
        'platform' => OrderChannel::TRENDYOL->value,
        'entity_type' => ProductVariant::class,
        'entity_id' => $variant->id,
        'platform_id' => 'TR-'.$variant->id,
        'platform_data' => [],
    ]);
}

test('observer dispatches sync job when inventory_quantity changes', function () {
    Bus::fake();

    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'OBS-1',
        'barcode' => '8680000000010',
        'price' => 1000,
        'inventory_quantity' => 5,
    ]);

    $variant->update(['inventory_quantity' => 7]);

    Bus::assertDispatched(SyncVariantStockToChannelsJob::class, fn ($job) => $job->variantId === $variant->id);
});

test('observer does not dispatch when inventory_quantity is unchanged', function () {
    Bus::fake();

    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'OBS-2',
        'barcode' => '8680000000020',
        'price' => 1000,
        'inventory_quantity' => 5,
    ]);

    $variant->update(['sku' => 'OBS-2-RENAMED']);

    Bus::assertNotDispatched(SyncVariantStockToChannelsJob::class);
});

test('job pushes stock only to channels with auto_sync_stock enabled and a mapping', function () {
    $shopify = makeShopifyIntegration(autoSync: true);
    $trendyol = makeTrendyolIntegration(autoSync: false); // disabled

    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'JOB-1',
        'barcode' => '8680000000001',
        'price' => 1000,
        'inventory_quantity' => 12,
    ]);
    mapVariantToShopify($variant);
    mapVariantToTrendyol($variant);

    Http::fake([
        'https://test-store.myshopify.com/*' => Http::response([], 200),
        'https://api.trendyol.com/*' => Http::response([], 200),
        'https://apigw.trendyol.com/*' => Http::response(['content' => []], 200),
    ]);

    (new SyncVariantStockToChannelsJob($variant->id))->handle();

    Http::assertSent(fn ($req) => str_contains($req->url(), 'inventory_levels/set.json')
        && $req->data()['available'] === 12
        && (string) $req->data()['location_id'] === '99999');

    Http::assertNotSent(fn ($req) => str_contains($req->url(), 'price-and-inventory'));
});

test('job skips channels where the variant has no mapping', function () {
    makeShopifyIntegration(autoSync: true); // enabled, but no mapping for this variant

    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'JOB-2',
        'barcode' => '8680000000200',
        'price' => 1000,
        'inventory_quantity' => 3,
    ]);

    Http::fake();

    (new SyncVariantStockToChannelsJob($variant->id))->handle();

    Http::assertNothingSent();
});

test('job skips inactive integrations', function () {
    $integ = makeShopifyIntegration(autoSync: true);
    $integ->update(['is_active' => false]);

    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'JOB-3',
        'barcode' => '8680000000300',
        'price' => 1000,
        'inventory_quantity' => 5,
    ]);
    mapVariantToShopify($variant);

    Http::fake();

    (new SyncVariantStockToChannelsJob($variant->id))->handle();

    Http::assertNothingSent();
});

test('job pushes to both Shopify and Trendyol when both are enabled and mapped', function () {
    makeShopifyIntegration(autoSync: true);
    makeTrendyolIntegration(autoSync: true);

    $variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'JOB-4',
        'barcode' => '8680000000004',
        'price' => 1000,
        'inventory_quantity' => 9,
    ]);
    mapVariantToShopify($variant);
    mapVariantToTrendyol($variant);

    Http::fake([
        'https://test-store.myshopify.com/*' => Http::response([], 200),
        'https://apigw.trendyol.com/*' => Http::response([
            'content' => [['barcode' => '8680000000004', 'salePrice' => 100, 'listPrice' => 120]],
        ], 200),
        'https://api.trendyol.com/*' => Http::response(['batchRequestId' => 'b'], 200),
    ]);

    (new SyncVariantStockToChannelsJob($variant->id))->handle();

    Http::assertSent(fn ($req) => str_contains($req->url(), 'inventory_levels/set.json'));
    Http::assertSent(fn ($req) => str_contains($req->url(), 'price-and-inventory'));
});
