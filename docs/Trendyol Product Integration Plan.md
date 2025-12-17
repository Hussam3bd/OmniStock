# Trendyol Product Integration - Implementation Plan

## 📋 API Overview

**Base URL:**
- Stage: `https://stageapigw.trendyol.com`
- Production: `https://apigw.trendyol.com`

**Authentication:** HTTP Basic Auth (username + password stored in env)

**Main Endpoint:** `POST /integration/product/sellers/{sellerId}/products`

---

## Required Product Data Mapping

From the Postman collection, each product requires:

| **Trendyol Field** | **Our System** | **Notes** |
|---|---|---|
| `barcode` | ProductVariant.barcode | **Required** - Unique identifier |
| `title` | Product.title | **Required** - Product name |
| `productMainId` | Product.model_code or custom | **Required** - Groups variants |
| `brandId` | Trendyol brand mapping | Need to fetch from Trendyol API |
| `categoryId` | Trendyol category mapping | Need to map our categories |
| `quantity` | ProductVariant stock | Current available stock |
| `stockCode` | ProductVariant.sku | **Required** - Our SKU |
| `dimensionalWeight` | Product weight calculation | Needs formula |
| `description` | Product.description | HTML allowed |
| `currencyType` | "TRY" | Always TRY for Turkey |
| `listPrice` | ProductVariant.price | Original price |
| `salePrice` | ProductVariant.sale_price | Selling price |
| `vatRate` | Tax rate (18% typical) | From product or config |
| `cargoCompanyId` | Trendyol cargo company | Need to map |
| `images[]` | ProductVariant.images | Array of image URLs |
| `attributes[]` | Category-specific | Dynamic based on category |

---

## Implementation Architecture

### Phase 1: Foundation (Data Models & Services)

```
app/Services/Trendyol/
├── TrendyolApiClient.php        # HTTP client wrapper with auth
├── TrendyolProductService.php   # Product CRUD operations
├── TrendyolCategoryService.php  # Category & attribute fetching
├── TrendyolBrandService.php     # Brand list fetching
└── TrendyolMapperService.php    # Map our data → Trendyol format
```

### Phase 2: Database Schema

```sql
-- Store Trendyol-specific mappings
trendyol_category_mappings (
    id,
    our_category_id,
    trendyol_category_id,
    trendyol_category_name,
    created_at,
    updated_at
)

trendyol_brand_mappings (
    id,
    our_brand,
    trendyol_brand_id,
    trendyol_brand_name,
    created_at,
    updated_at
)

trendyol_product_mappings (
    id,
    product_variant_id,
    trendyol_barcode,
    batch_request_id,
    sync_status, -- pending, processing, success, failed
    error_message,
    last_synced_at,
    created_at,
    updated_at
)

trendyol_category_attributes (
    id,
    category_id,
    attribute_id,
    attribute_name,
    is_required,
    value_type, -- text, select, numeric
    possible_values, -- JSON for select types
    created_at,
    updated_at
)
```

### Phase 3: Jobs & Queues

```
app/Jobs/Trendyol/
├── SyncProductToTrendyol.php      # Single product sync
├── BulkSyncProductsToTrendyol.php # Batch products
├── CheckBatchRequestStatus.php    # Poll batch status
└── SyncProductPriceAndStock.php   # Update existing products
```

### Phase 4: Filament UI

```
app/Filament/Resources/Product/
├── Actions/
│   ├── SyncToTrendyolAction.php        # Button on product page
│   ├── BulkSyncToTrendyolAction.php    # Bulk action
│   └── ConfigureTrendyolMappingAction.php # Map categories/brands
└── Pages/
    └── TrendyolSyncStatus.php          # Monitor sync status
```

---

## Detailed Implementation Steps

### Step 1: Create Trendyol API Client

```php
<?php

namespace App\Services\Trendyol;

use Illuminate\Support\Facades\Http;

class TrendyolApiClient
{
    protected function getBaseUrl(): string
    {
        return config('trendyol.api_url');
    }

    protected function getAuthCredentials(): array
    {
        return [
            config('trendyol.credentials.username'),
            config('trendyol.credentials.password'),
        ];
    }

    public function post(string $endpoint, array $data): array
    {
        $response = Http::withBasicAuth(...$this->getAuthCredentials())
            ->post($this->getBaseUrl() . $endpoint, $data);

        return $response->json();
    }

    public function get(string $endpoint, array $params = []): array
    {
        $response = Http::withBasicAuth(...$this->getAuthCredentials())
            ->get($this->getBaseUrl() . $endpoint, $params);

        return $response->json();
    }

    public function put(string $endpoint, array $data): array
    {
        $response = Http::withBasicAuth(...$this->getAuthCredentials())
            ->put($this->getBaseUrl() . $endpoint, $data);

        return $response->json();
    }

    public function delete(string $endpoint, array $data): array
    {
        $response = Http::withBasicAuth(...$this->getAuthCredentials())
            ->delete($this->getBaseUrl() . $endpoint, $data);

        return $response->json();
    }
}
```

### Step 2: Fetch & Store Categories

```php
// GET /integration/product/product-categories
// Store in database for mapping
Command: php artisan trendyol:sync-categories
```

### Step 3: Fetch & Store Brands

```php
// GET /integration/product/brands
// Store in database for mapping
Command: php artisan trendyol:sync-brands
```

### Step 4: Fetch Category Attributes

```php
// GET /integration/product/product-categories/{categoryId}/attributes
// For each category, get required attributes
Command: php artisan trendyol:sync-category-attributes {categoryId}
```

### Step 5: Build Product Mapper

```php
<?php

namespace App\Services\Trendyol;

use App\Models\Product\ProductVariant;

class TrendyolMapperService
{
    public function mapProductVariantToTrendyol(ProductVariant $variant): array
    {
        return [
            'barcode' => $variant->barcode,
            'title' => $variant->product->title,
            'productMainId' => $variant->product->model_code ?? $variant->product->id,
            'brandId' => $this->getTrendyolBrandId($variant->product->brand),
            'categoryId' => $this->getTrendyolCategoryId($variant->product->category),
            'quantity' => $this->getAvailableStock($variant),
            'stockCode' => $variant->sku,
            'dimensionalWeight' => $this->calculateDimensionalWeight($variant),
            'description' => $variant->product->description,
            'currencyType' => 'TRY',
            'listPrice' => $variant->price->divide(100)->getAmount(),
            'salePrice' => ($variant->sale_price ?? $variant->price)->divide(100)->getAmount(),
            'vatRate' => config('trendyol.defaults.vat_rate', 18),
            'cargoCompanyId' => config('trendyol.defaults.cargo_company_id'),
            'images' => $this->mapImages($variant),
            'attributes' => $this->mapAttributes($variant),
        ];
    }

    protected function getTrendyolBrandId($brand): int
    {
        // Look up in trendyol_brand_mappings table
    }

    protected function getTrendyolCategoryId($category): int
    {
        // Look up in trendyol_category_mappings table
    }

    protected function getAvailableStock(ProductVariant $variant): int
    {
        // Calculate available stock from inventory
    }

    protected function calculateDimensionalWeight(ProductVariant $variant): float
    {
        // Formula: (Length × Width × Height) / 5000
    }

    protected function mapImages(ProductVariant $variant): array
    {
        // Return array of publicly accessible image URLs
    }

    protected function mapAttributes(ProductVariant $variant): array
    {
        // Map to Trendyol category-specific attributes
    }
}
```

### Step 6: Create Sync Job

```php
<?php

namespace App\Jobs\Trendyol;

use App\Models\Product\ProductVariant;
use App\Services\Trendyol\TrendyolMapperService;
use App\Services\Trendyol\TrendyolProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncProductToTrendyol implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ProductVariant $variant
    ) {}

    public function handle(
        TrendyolProductService $service,
        TrendyolMapperService $mapper
    ): void {
        $mapped = $mapper->mapProductVariantToTrendyol($this->variant);

        $response = $service->createProducts(['items' => [$mapped]]);

        // Store batch request ID
        TrendyolProductMapping::create([
            'product_variant_id' => $this->variant->id,
            'trendyol_barcode' => $mapped['barcode'],
            'batch_request_id' => $response['batchRequestId'],
            'sync_status' => 'pending',
        ]);

        // Dispatch job to check status after 30 seconds
        CheckBatchRequestStatus::dispatch($response['batchRequestId'])
            ->delay(now()->addSeconds(30));
    }
}
```

### Step 7: Check Batch Status

```php
<?php

namespace App\Jobs\Trendyol;

use App\Services\Trendyol\TrendyolProductService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckBatchRequestStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $batchRequestId
    ) {}

    public function handle(TrendyolProductService $service): void
    {
        $status = $service->getBatchRequestStatus($this->batchRequestId);

        // Update mapping status
        TrendyolProductMapping::where('batch_request_id', $this->batchRequestId)
            ->update([
                'sync_status' => $status['status'], // 'success', 'failed', 'processing'
                'error_message' => $status['errors'] ?? null,
                'last_synced_at' => now(),
            ]);

        // Retry if still processing
        if ($status['status'] === 'processing') {
            self::dispatch($this->batchRequestId)->delay(now()->addSeconds(30));
        }
    }
}
```

### Step 8: Filament Integration

```php
// Add button to product resource
ViewAction::make()
    ->extraActions([
        Action::make('syncToTrendyol')
            ->label('Sync to Trendyol')
            ->icon('heroicon-o-arrow-up-tray')
            ->requiresConfirmation()
            ->modalDescription('This will create/update the product on Trendyol marketplace.')
            ->action(function ($record) {
                SyncProductToTrendyol::dispatch($record);

                Notification::make()
                    ->title('Product queued for Trendyol sync')
                    ->body('Check sync status in a few moments.')
                    ->success()
                    ->send();
            }),
    ]),
```

---

## Configuration Requirements

### .env

```env
TRENDYOL_API_URL=https://stageapigw.trendyol.com
TRENDYOL_SELLER_ID=2738
TRENDYOL_API_USERNAME=xxxxx
TRENDYOL_API_PASSWORD=xxxxx
TRENDYOL_DEFAULT_CARGO_COMPANY_ID=10
TRENDYOL_DEFAULT_VAT_RATE=18
```

### config/trendyol.php

```php
<?php

return [
    'api_url' => env('TRENDYOL_API_URL', 'https://apigw.trendyol.com'),
    'seller_id' => env('TRENDYOL_SELLER_ID'),

    'credentials' => [
        'username' => env('TRENDYOL_API_USERNAME'),
        'password' => env('TRENDYOL_API_PASSWORD'),
    ],

    'defaults' => [
        'cargo_company_id' => env('TRENDYOL_DEFAULT_CARGO_COMPANY_ID', 10),
        'vat_rate' => env('TRENDYOL_DEFAULT_VAT_RATE', 18),
        'currency' => 'TRY',
    ],

    'batch_check_interval' => 30, // seconds
    'batch_check_max_retries' => 20, // max 10 minutes
];
```

---

## Key Challenges & Solutions

| **Challenge** | **Solution** |
|---|---|
| **Category Mapping** | Build UI to map our categories → Trendyol categories manually |
| **Brand Mapping** | Fetch Trendyol brand list, allow search/mapping in UI |
| **Dynamic Attributes** | Fetch attributes per category, store, and allow user input |
| **Image URLs** | Images must be publicly accessible URLs (use CDN) |
| **Batch Processing** | Use async jobs + polling to check status |
| **Error Handling** | Store errors in database, show in Filament UI |
| **Stock Sync** | Separate job for price/stock updates (different endpoint) |
| **Variant Grouping** | Use `productMainId` to group variants of same product |

---

## Recommended Implementation Order

1. ✅ Create migrations for Trendyol mapping tables
2. ✅ Build TrendyolApiClient service
3. ✅ Create commands to fetch categories, brands, attributes
4. ✅ Build category/brand mapping UI in Filament
5. ✅ Implement TrendyolMapperService
6. ✅ Create SyncProductToTrendyol job
7. ✅ Create CheckBatchRequestStatus job
8. ✅ Add Filament actions for manual sync
9. ✅ Add bulk sync action
10. ✅ Create sync status page in Filament
11. ✅ Add automated stock/price sync (cron job)
12. ✅ Testing with stage environment
13. ✅ Production deployment

---

## Estimated Effort

- **Phase 1-2 (Foundation + DB):** ~4-6 hours
- **Phase 3 (Jobs):** ~3-4 hours
- **Phase 4 (Filament UI):** ~4-6 hours
- **Testing & Refinement:** ~4-6 hours

**Total:** ~15-22 hours of development

---

## API Endpoints Reference

### Product Integration

1. **Get Brand List**
   - `GET /integration/product/brands`
   - No auth required for list

2. **Get Category List**
   - `GET /integration/product/product-categories`
   - Returns hierarchical category structure

3. **Get Category Attributes**
   - `GET /integration/product/product-categories/{categoryId}/attributes`
   - Returns required and optional attributes for category

4. **Create Products**
   - `POST /integration/product/sellers/{sellerId}/products`
   - Returns `batchRequestId`

5. **Check Batch Status**
   - `GET /integration/product/sellers/{sellerId}/products/batch-requests/{batchRequestId}`
   - Returns processing status

6. **Update Product**
   - `PUT /integration/product/sellers/{sellerId}/products`
   - Same structure as create

7. **Update Stock & Price**
   - `POST /integration/inventory/sellers/{sellerId}/products/price-and-inventory`
   - Faster endpoint for stock/price only

8. **Delete Product**
   - `DELETE /integration/product/sellers/{sellerId}/products`
   - Body: `{ "items": [{ "barcode": "xxx" }] }`

9. **Filter Products**
   - `GET /integration/product/sellers/{sellerId}/products`
   - Query existing products

---

## Notes

- All monetary values should be in major units (not cents) for Trendyol
- Images must be HTTPS URLs and publicly accessible
- Barcode must be unique across all Trendyol
- Product sync is asynchronous - always check batch status
- Stock updates can happen immediately via separate endpoint
- Category attributes are dynamic - must be fetched per category
- VAT rate is typically 18% in Turkey but can vary by product type

---

## Next Steps

When ready to implement:
1. Review this plan
2. Set up `.env` credentials (get from Trendyol partner panel)
3. Start with Phase 1 (create migrations and services)
4. Test with stage environment first
5. Move to production after successful testing
