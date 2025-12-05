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
    public function mapProduct(array $trendyolProduct, bool $syncImages = false, bool $syncInventory = false): Product
    {
        // Extract product-level data
        $productMainId = $trendyolProduct['productMainId'] ?? null;
        $productTitle = $trendyolProduct['title'] ?? 'Unknown Product';
        $brand = $trendyolProduct['brand'] ?? null;
        $categoryName = $trendyolProduct['categoryName'] ?? null;

        // Find or create product (with minimal transaction)
        $product = DB::transaction(function () use ($productMainId, $productTitle, $brand, $trendyolProduct) {
            return $this->findOrCreateProductByMainId($productMainId, $productTitle, $brand, $trendyolProduct);
        });

        // Sync images outside transaction (can take time, doesn't need locks)
        if ($syncImages && isset($trendyolProduct['images']) && $product->getMedia('images')->isEmpty()) {
            $this->syncProductImages($product, $trendyolProduct['images']);
        }

        // Sync variant with its own transaction to reduce lock time
        DB::transaction(function () use ($product, $trendyolProduct, $syncInventory) {
            $this->syncVariant($product, $trendyolProduct, $syncInventory);
        });

        return $product->fresh('variants');
    }

    /**
     * Find or create a product by productMainId.
     */
    protected function findOrCreateProductByMainId(
        ?string $productMainId,
        string $title,
        ?string $brand,
        array $item
    ): Product {
        // Try to find by model_code (productMainId) first
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

        // Try to match by barcode/SKU (cross-platform matching)
        $barcode = $item['barcode'] ?? null;
        $sku = $item['stockCode'] ?? $item['merchantSku'] ?? null;

        if ($barcode) {
            $existingVariant = ProductVariant::where('barcode', $barcode)->first();
            if ($existingVariant) {
                return $existingVariant->product;
            }
        }

        if ($sku) {
            $existingVariant = ProductVariant::where('sku', $sku)->first();
            if ($existingVariant) {
                return $existingVariant->product;
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

        // Create or update platform mapping for new product
        if ($productMainId) {
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'entity_type' => Product::class,
                    'platform_id' => (string) $productMainId,
                ],
                [
                    'entity_id' => $product->id,
                    'platform_data' => ['title' => $title, 'brand' => $brand],
                    'last_synced_at' => now(),
                ]
            );
        }

        return $product;
    }

    /**
     * Sync a single variant.
     */
    protected function syncVariant(Product $product, array $item, bool $syncInventory = false): void
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
            // Don't sync cost_price from Trendyol - use Shopify cost instead
            'requires_shipping' => true,
            'taxable' => true,
        ];

        if ($variant) {
            // Updating existing variant: only sync inventory if enabled
            if ($syncInventory) {
                $variantData['inventory_quantity'] = $item['quantity'] ?? 0;
            }
            $variant->update($variantData);
        } else {
            // Creating new variant: set inventory to 0 by default, or platform value if syncing
            $variantData['inventory_quantity'] = $syncInventory
                ? ($item['quantity'] ?? 0)
                : 0;
            $variant = ProductVariant::create($variantData);
        }

        // Create or update platform mapping if barcode exists
        if ($barcode) {
            // Delete any existing mapping for this entity on this platform (with different platform_id)
            // This prevents unique constraint violations when a variant is matched to different platform variants
            PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('entity_type', ProductVariant::class)
                ->where('entity_id', $variant->id)
                ->where('platform_id', '!=', (string) $barcode)
                ->delete();

            // Now safely create or update the mapping
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'entity_type' => ProductVariant::class,
                    'platform_id' => (string) $barcode,
                ],
                [
                    'entity_id' => $variant->id,
                    'platform_data' => $item,
                    'last_synced_at' => now(),
                ]
            );
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
