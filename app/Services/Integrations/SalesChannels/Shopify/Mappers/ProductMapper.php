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
    public function mapProduct(array $shopifyProduct): Product
    {
        return DB::transaction(function () use ($shopifyProduct) {
            // Extract product-level data
            $productId = $shopifyProduct['id'] ?? null;
            $productTitle = $shopifyProduct['title'] ?? 'Unknown Product';
            $vendor = $shopifyProduct['vendor'] ?? null;
            $productType = $shopifyProduct['product_type'] ?? null;

            // Find or create product using Shopify product ID
            $product = $this->findOrCreateProductById($productId, $productTitle, $vendor, $productType, $shopifyProduct);

            // Sync images
            if (isset($shopifyProduct['images']) && ! empty($shopifyProduct['images'])) {
                $this->syncProductImages($product, $shopifyProduct['images']);
            }

            // Sync variants
            if (isset($shopifyProduct['variants']) && ! empty($shopifyProduct['variants'])) {
                foreach ($shopifyProduct['variants'] as $shopifyVariant) {
                    $this->syncVariant($product, $shopifyVariant, $shopifyProduct);
                }
            }

            return $product->fresh('variants');
        });
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

        // Try to match by SKU or title
        $matchedProduct = $this->findMatchingProduct($shopifyProduct);

        if ($matchedProduct) {
            // Create platform mapping for matched product
            PlatformMapping::create([
                'platform' => $this->getChannel()->value,
                'entity_type' => Product::class,
                'entity_id' => $matchedProduct->id,
                'platform_id' => (string) $productId,
                'platform_data' => $shopifyProduct,
                'last_synced_at' => now(),
            ]);

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

        // Create platform mapping for new product
        if ($productId) {
            PlatformMapping::create([
                'platform' => $this->getChannel()->value,
                'entity_type' => Product::class,
                'entity_id' => $product->id,
                'platform_id' => (string) $productId,
                'platform_data' => $shopifyProduct,
                'last_synced_at' => now(),
            ]);
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
     * Find matching product by SKU or title.
     */
    protected function findMatchingProduct(array $shopifyProduct): ?Product
    {
        // Try to match by first variant SKU
        $variants = $shopifyProduct['variants'] ?? [];

        foreach ($variants as $variant) {
            $sku = $variant['sku'] ?? null;

            if ($sku) {
                $existingVariant = ProductVariant::where('sku', $sku)->first();

                if ($existingVariant) {
                    return $existingVariant->product;
                }
            }

            $barcode = $variant['barcode'] ?? null;

            if ($barcode) {
                $existingVariant = ProductVariant::where('barcode', $barcode)->first();

                if ($existingVariant) {
                    return $existingVariant->product;
                }
            }
        }

        // Try to match by title (fuzzy matching)
        $title = $shopifyProduct['title'] ?? null;

        if ($title) {
            return Product::where('title', 'like', '%'.$title.'%')->first();
        }

        return null;
    }

    /**
     * Sync a single variant.
     */
    protected function syncVariant(Product $product, array $shopifyVariant, array $shopifyProduct): void
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

        // If not found by mapping, try to match by SKU or barcode
        if (! $variant && $sku) {
            $variant = ProductVariant::where('sku', $sku)->first();
        }

        if (! $variant && $barcode) {
            $variant = ProductVariant::where('barcode', $barcode)->first();
        }

        $price = $this->convertToMinorUnits((float) ($shopifyVariant['price'] ?? 0));
        $compareAtPrice = isset($shopifyVariant['compare_at_price'])
            ? $this->convertToMinorUnits((float) $shopifyVariant['compare_at_price'])
            : null;

        $variantData = [
            'product_id' => $product->id,
            'sku' => $sku ?? 'SKU-'.time(),
            'barcode' => $barcode,
            'title' => $shopifyVariant['title'] ?? $product->title,
            'price' => $price,
            'compare_at_price' => $compareAtPrice,
            'cost_price' => null, // Shopify doesn't provide cost in basic API
            'inventory_quantity' => $shopifyVariant['inventory_quantity'] ?? 0,
            'weight' => $shopifyVariant['weight'] ?? null,
            'weight_unit' => $shopifyVariant['weight_unit'] ?? null,
            'requires_shipping' => $shopifyVariant['requires_shipping'] ?? true,
            'taxable' => $shopifyVariant['taxable'] ?? true,
        ];

        if ($variant) {
            $variant->update($variantData);
        } else {
            $variant = ProductVariant::create($variantData);
        }

        // Create or update platform mapping
        if ($variantId) {
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
