<?php

namespace App\Services\Integrations\SalesChannels\Shopify\Mappers;

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
        return OrderChannel::SHOPIFY;
    }

    /**
     * Map a Shopify product to our system.
     */
    public function mapProduct(array $shopifyProduct, bool $syncImages = false, bool $syncInventory = false): Product
    {
        // Extract product-level data
        $productId = $shopifyProduct['id'] ?? null;
        $productTitle = $shopifyProduct['title'] ?? 'Unknown Product';
        $vendor = $shopifyProduct['vendor'] ?? null;
        $productType = $shopifyProduct['product_type'] ?? null;

        // Find or create product (with minimal transaction)
        $product = DB::transaction(function () use ($productId, $productTitle, $vendor, $productType, $shopifyProduct) {
            return $this->findOrCreateProductById($productId, $productTitle, $vendor, $productType, $shopifyProduct);
        });

        // Sync images outside transaction (can take time, doesn't need locks)
        if ($syncImages && isset($shopifyProduct['images']) && ! empty($shopifyProduct['images'])) {
            $this->syncProductImages($product, $shopifyProduct['images']);
        }

        // Sync variants one by one to reduce lock time
        if (isset($shopifyProduct['variants']) && ! empty($shopifyProduct['variants'])) {
            foreach ($shopifyProduct['variants'] as $shopifyVariant) {
                DB::transaction(function () use ($product, $shopifyVariant, $shopifyProduct, $syncInventory) {
                    $this->syncVariant($product, $shopifyVariant, $shopifyProduct, $syncInventory);
                });
            }
        }

        return $product->fresh('variants');
    }

    /**
     * Find or create a product by Shopify product ID.
     */
    protected function findOrCreateProductById(
        ?string $productId,
        string $title,
        ?string $vendor,
        ?string $productType,
        array $shopifyProduct
    ): Product {
        // Try to find by platform mapping first
        if ($productId) {
            $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('entity_type', Product::class)
                ->where('platform_id', (string) $productId)
                ->first();

            if ($mapping && $mapping->entity) {
                // Update existing product
                $mapping->entity->update([
                    'title' => $title,
                    'description' => $shopifyProduct['body_html'] ?? $mapping->entity->description,
                    'vendor' => $vendor ?? $mapping->entity->vendor,
                    'product_type' => $productType ?? $mapping->entity->product_type,
                    'status' => $this->mapProductStatus($shopifyProduct),
                ]);

                // Update platform data
                $mapping->update([
                    'platform_data' => $shopifyProduct,
                    'last_synced_at' => now(),
                ]);

                return $mapping->entity;
            }
        }

        // Try to match by SKU or barcode
        $matchedProduct = $this->findMatchingProduct($shopifyProduct);

        if ($matchedProduct) {
            // Create or update platform mapping for matched product
            if ($productId) {
                PlatformMapping::updateOrCreate(
                    [
                        'platform' => $this->getChannel()->value,
                        'entity_type' => Product::class,
                        'platform_id' => (string) $productId,
                    ],
                    [
                        'entity_id' => $matchedProduct->id,
                        'platform_data' => $shopifyProduct,
                        'last_synced_at' => now(),
                    ]
                );
            }

            activity()
                ->performedOn($matchedProduct)
                ->withProperties([
                    'shopify_product_id' => $productId,
                    'title' => $title,
                ])
                ->log('shopify_product_matched');

            return $matchedProduct;
        }

        // Create new product
        $product = Product::create([
            'model_code' => $shopifyProduct['handle'] ?? null,
            'title' => $title,
            'description' => $shopifyProduct['body_html'] ?? sprintf('Imported from %s', ucfirst($this->getChannel()->value)),
            'vendor' => $vendor ?? ucfirst($this->getChannel()->value),
            'product_type' => $productType ?? 'Imported',
            'status' => $this->mapProductStatus($shopifyProduct),
        ]);

        // Create or update platform mapping for new product
        if ($productId) {
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'entity_type' => Product::class,
                    'platform_id' => (string) $productId,
                ],
                [
                    'entity_id' => $product->id,
                    'platform_data' => $shopifyProduct,
                    'last_synced_at' => now(),
                ]
            );
        }

        activity()
            ->performedOn($product)
            ->withProperties([
                'shopify_product_id' => $productId,
                'title' => $title,
            ])
            ->log('shopify_product_created');

        return $product;
    }

    /**
     * Find matching product by barcode or SKU (exact matching only).
     */
    protected function findMatchingProduct(array $shopifyProduct): ?Product
    {
        // Try to match by variant barcode first, then SKU (cross-platform matching)
        $variants = $shopifyProduct['variants'] ?? [];

        foreach ($variants as $variant) {
            $barcode = $variant['barcode'] ?? null;

            if ($barcode) {
                $existingVariant = ProductVariant::where('barcode', $barcode)->first();

                if ($existingVariant) {
                    return $existingVariant->product;
                }
            }

            $sku = $variant['sku'] ?? null;

            if ($sku) {
                $existingVariant = ProductVariant::where('sku', $sku)->first();

                if ($existingVariant) {
                    return $existingVariant->product;
                }
            }
        }

        // No fuzzy title matching - creates too many duplicates
        return null;
    }

    /**
     * Sync a single variant.
     */
    protected function syncVariant(Product $product, array $shopifyVariant, array $shopifyProduct, bool $syncInventory = false): void
    {
        $variantId = $shopifyVariant['id'] ?? null;
        $sku = $shopifyVariant['sku'] ?? null;
        $barcode = $shopifyVariant['barcode'] ?? null;

        // Try to find existing variant by platform mapping first
        $variant = null;

        if ($variantId) {
            $mapping = PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('entity_type', ProductVariant::class)
                ->where('platform_id', (string) $variantId)
                ->first();

            if ($mapping && $mapping->entity) {
                $variant = $mapping->entity;
            }
        }

        // If not found by mapping, try to match by barcode first, then SKU
        if (! $variant && $barcode) {
            $variant = ProductVariant::where('barcode', $barcode)->first();
        }

        if (! $variant && $sku) {
            $variant = ProductVariant::where('sku', $sku)->first();
        }

        $price = $this->convertToMinorUnits((float) ($shopifyVariant['price'] ?? 0));
        $compareAtPrice = isset($shopifyVariant['compare_at_price'])
            ? $this->convertToMinorUnits((float) $shopifyVariant['compare_at_price'])
            : null;

        // Extract cost from Shopify if available (some plans/apps provide this)
        $costPrice = null;
        if (isset($shopifyVariant['cost'])) {
            $costPrice = $this->convertToMinorUnits((float) $shopifyVariant['cost']);
        } elseif (isset($shopifyVariant['inventory_cost'])) {
            $costPrice = $this->convertToMinorUnits((float) $shopifyVariant['inventory_cost']);
        }

        $variantData = [
            'product_id' => $product->id,
            'sku' => $sku ?? ($barcode ?? 'SKU-'.uniqid()),
            'barcode' => $barcode,
            'title' => $shopifyVariant['title'] ?? $product->title,
            'price' => $price,
            'compare_at_price' => $compareAtPrice,
            'weight' => $shopifyVariant['weight'] ?? null,
            'weight_unit' => $shopifyVariant['weight_unit'] ?? null,
            'requires_shipping' => $shopifyVariant['requires_shipping'] ?? true,
            'taxable' => $shopifyVariant['taxable'] ?? true,
        ];

        // Only update cost_price if Shopify provides it
        if ($costPrice !== null) {
            $variantData['cost_price'] = $costPrice;
        }

        if ($variant) {
            // Updating existing variant: only sync inventory if enabled
            if ($syncInventory) {
                $variantData['inventory_quantity'] = $shopifyVariant['inventory_quantity'] ?? 0;
            }
            $variant->update($variantData);
        } else {
            // Creating new variant: set inventory to 0 by default, or platform value if syncing
            $variantData['inventory_quantity'] = $syncInventory
                ? ($shopifyVariant['inventory_quantity'] ?? 0)
                : 0;
            $variant = ProductVariant::create($variantData);
        }

        // Create or update platform mapping
        if ($variantId) {
            // Delete any existing mapping for this entity on this platform (with different platform_id)
            // This prevents unique constraint violations when a variant is matched to different platform variants
            PlatformMapping::where('platform', $this->getChannel()->value)
                ->where('entity_type', ProductVariant::class)
                ->where('entity_id', $variant->id)
                ->where('platform_id', '!=', (string) $variantId)
                ->delete();

            // Now safely create or update the mapping
            PlatformMapping::updateOrCreate(
                [
                    'platform' => $this->getChannel()->value,
                    'entity_type' => ProductVariant::class,
                    'platform_id' => (string) $variantId,
                ],
                [
                    'entity_id' => $variant->id,
                    'platform_data' => array_merge($shopifyVariant, [
                        'inventory_item_id' => $shopifyVariant['inventory_item_id'] ?? null,
                    ]),
                    'last_synced_at' => now(),
                ]
            );
        }

        // Enable on this channel
        $variant->enableOnChannel($this->getChannel(), [
            'inventory_item_id' => $shopifyVariant['inventory_item_id'] ?? null,
        ]);

        // Map variant attributes (options)
        $this->mapVariantAttributes($variant, $shopifyVariant, $shopifyProduct);
    }

    /**
     * Map variant attributes (color, size, etc.).
     */
    protected function mapVariantAttributes(ProductVariant $variant, array $shopifyVariant, array $shopifyProduct): void
    {
        $attributes = [];

        // Shopify has options like option1, option2, option3
        $options = $shopifyProduct['options'] ?? [];

        for ($i = 1; $i <= 3; $i++) {
            $optionKey = "option{$i}";
            $optionValue = $shopifyVariant[$optionKey] ?? null;

            if ($optionValue && isset($options[$i - 1])) {
                $optionName = $options[$i - 1]['name'] ?? "Option {$i}";
                $attributes[$optionName] = $optionValue;
            }
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
     * Sync product images from Shopify.
     */
    protected function syncProductImages(Product $product, array $images): void
    {
        // Clear existing images if syncing again
        $existingImages = $product->getMedia('images');

        foreach ($images as $index => $image) {
            $imageUrl = $image['src'] ?? null;

            if (! $imageUrl) {
                continue;
            }

            // Check if this image already exists
            $imageExists = $existingImages->contains(function ($media) use ($imageUrl) {
                return $media->getCustomProperty('shopify_image_id') === ($image['id'] ?? null)
                    || str_contains($media->getUrl(), basename($imageUrl));
            });

            if ($imageExists) {
                continue;
            }

            try {
                $product->addMediaFromUrl($imageUrl)
                    ->withCustomProperties([
                        'shopify_image_id' => $image['id'] ?? null,
                        'position' => $image['position'] ?? $index + 1,
                    ])
                    ->toMediaCollection('images');
            } catch (\Exception $e) {
                // Log error but continue with other images
                activity()
                    ->performedOn($product)
                    ->withProperties([
                        'error' => $e->getMessage(),
                        'image_url' => $imageUrl,
                    ])
                    ->log('shopify_product_image_sync_failed');
            }
        }
    }

    /**
     * Map Shopify product status to our product status.
     */
    protected function mapProductStatus(array $shopifyProduct): string
    {
        $status = strtolower($shopifyProduct['status'] ?? 'draft');

        return match ($status) {
            'active' => 'active',
            'archived' => 'archived',
            'draft' => 'draft',
            default => 'draft',
        };
    }
}
