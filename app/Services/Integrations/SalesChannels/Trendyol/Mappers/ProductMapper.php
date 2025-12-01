<?php

namespace App\Services\Integrations\SalesChannels\Trendyol\Mappers;

use App\Enums\Order\OrderChannel;
use App\Models\Platform\PlatformMapping;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\Concerns\BaseProductMapper;
use App\Services\Product\AttributeMappingService;
use Illuminate\Support\Facades\DB;

class ProductMapper extends BaseProductMapper
{
    public function __construct(
        protected AttributeMappingService $attributeMappingService
    ) {}

    protected function getChannel(): OrderChannel
    {
        return OrderChannel::TRENDYOL;
    }

    /**
     * Map a Trendyol product to our system.
     */
    public function mapProduct(array $trendyolProduct): Product
    {
        return DB::transaction(function () use ($trendyolProduct) {
            // Extract product-level data
            $productMainId = $trendyolProduct['productMainId'] ?? null;
            $productTitle = $trendyolProduct['title'] ?? 'Unknown Product';
            $brand = $trendyolProduct['brand'] ?? null;
            $categoryName = $trendyolProduct['categoryName'] ?? null;

            // Find or create product using productMainId
            $product = $this->findOrCreateProductByMainId($productMainId, $productTitle, $brand);

            // Sync images (only once per product, not per variant)
            if (isset($trendyolProduct['images']) && $product->getMedia('images')->isEmpty()) {
                $this->syncProductImages($product, $trendyolProduct['images']);
            }

            // Sync this variant (in Products API, each response item is one variant)
            $this->syncVariant($product, $trendyolProduct);

            return $product->fresh('variants');
        });
    }

    /**
     * Find or create a product by productMainId.
     */
    protected function findOrCreateProductByMainId(
        ?string $productMainId,
        string $title,
        ?string $brand
    ): Product {
        // Try to find by model_code (productMainId)
        if ($productMainId) {
            $product = Product::where('model_code', $productMainId)->first();

            if ($product) {
                return $product;
            }
        }

        // Try to find by platform mapping
        if ($productMainId) {
            $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('entity_type', Product::class)
                ->where('platform_id', (string) $productMainId)
                ->first();

            if ($mapping && $mapping->entity) {
                return $mapping->entity;
            }
        }

        // Create new product
        $product = Product::create([
            'model_code' => $productMainId,
            'title' => $title,
            'description' => sprintf('Imported from %s', ucfirst($this->getChannel()->value)),
            'vendor' => $brand ?? ucfirst($this->getChannel()->value),
            'product_type' => 'Imported',
            'status' => 'active',
        ]);

        // Create platform mapping for new product (only once per productMainId)
        if ($productMainId) {
            PlatformMapping::create([
                'platform' => $this->getChannel()->value,
                'entity_type' => Product::class,
                'entity_id' => $product->id,
                'platform_id' => (string) $productMainId,
                'platform_data' => ['title' => $title, 'brand' => $brand],
                'last_synced_at' => now(),
            ]);
        }

        return $product;
    }

    /**
     * Sync a single variant.
     */
    protected function syncVariant(Product $product, array $item): void
    {
        $barcode = $item['barcode'] ?? null;
        $sku = $item['stockCode'] ?? $item['merchantSku'] ?? $barcode;

        // Check if variant already exists by barcode
        $variant = null;

        if ($barcode) {
            $variant = ProductVariant::where('barcode', $barcode)->first();
        }

        if (! $variant && $sku) {
            $variant = ProductVariant::where('sku', $sku)->first();
        }

        $variantData = [
            'product_id' => $product->id,
            'sku' => $sku ?? ($barcode ?? 'SKU-'.uniqid()),
            'barcode' => $barcode,
            'title' => $item['title'] ?? $product->title,
            'price' => $this->convertToMinorUnits($item['salePrice'] ?? 0),
            'cost_price' => $this->convertToMinorUnits($item['listPrice'] ?? 0),
            'inventory_quantity' => $item['quantity'] ?? 0,
            'requires_shipping' => true,
            'taxable' => true,
        ];

        if ($variant) {
            $variant->update($variantData);
        } else {
            $variant = ProductVariant::create($variantData);
        }

        // Only create/update platform mapping if it doesn't exist or needs updating
        if ($barcode) {
            $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('entity_type', ProductVariant::class)
                ->where('platform_id', (string) $barcode)
                ->first();

            if (! $mapping) {
                PlatformMapping::create([
                    'platform' => $this->getChannel()->value,
                    'entity_type' => ProductVariant::class,
                    'entity_id' => $variant->id,
                    'platform_id' => (string) $barcode,
                    'platform_data' => $item,
                    'last_synced_at' => now(),
                ]);
            } elseif ($mapping->entity_id !== $variant->id) {
                // Only update if entity_id changed
                $mapping->update([
                    'entity_id' => $variant->id,
                    'platform_data' => $item,
                    'last_synced_at' => now(),
                ]);
            }
        }

        // Enable on this channel
        $variant->enableOnChannel($this->getChannel(), [
            'barcode' => $barcode,
        ]);

        // Map variant attributes
        $this->mapVariantAttributes($variant, $item);
    }

    /**
     * Map variant attributes (color, size, etc.).
     */
    protected function mapVariantAttributes(ProductVariant $variant, array $item): void
    {
        $attributes = [];

        // Extract attributes from item
        if (isset($item['attributes'])) {
            foreach ($item['attributes'] as $attribute) {
                $attributeName = $attribute['attributeName'] ?? $attribute['name'] ?? null;
                $attributeValue = $attribute['attributeValue'] ?? $attribute['value'] ?? null;

                if ($attributeName && $attributeValue) {
                    $attributes[$attributeName] = $attributeValue;
                }
            }
        }

        // Fallback: check for direct fields
        if (isset($item['color'])) {
            $attributes['productColor'] = $item['color'];
        }

        if (isset($item['size'])) {
            $attributes['productSize'] = $item['size'];
        }

        if (! empty($attributes)) {
            $this->attributeMappingService->mapAttributesToVariant(
                $variant,
                $attributes,
                $this->getChannel()->value
            );
        }
    }

    /**
     * Sync product images from Trendyol.
     */
    protected function syncProductImages(Product $product, array $images): void
    {
        foreach ($images as $index => $image) {
            $imageUrl = $image['url'] ?? null;

            if (! $imageUrl) {
                continue;
            }

            try {
                $product->addMediaFromUrl($imageUrl)
                    ->toMediaCollection('images');
            } catch (\Exception $e) {
                // Log error but continue with other images
                activity()
                    ->performedOn($product)
                    ->withProperties([
                        'error' => $e->getMessage(),
                        'image_url' => $imageUrl,
                    ])
                    ->log('product_image_sync_failed');
            }
        }
    }
}
