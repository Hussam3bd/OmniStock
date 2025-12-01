<?php

namespace App\Services\Product;

use App\Models\Product\AttributeMapping;
use App\Models\Product\ProductVariant;
use App\Models\Product\VariantOption;
use Illuminate\Support\Collection;

class AttributeMappingService
{
    /**
     * Map platform attributes to variant options for a product variant.
     */
    public function mapAttributesToVariant(
        ProductVariant $variant,
        array $attributes,
        string $platform
    ): void {
        // Sort attributes to ensure color comes first, then size
        $sortedAttributes = $this->sortAttributesByPriority($attributes);

        $variantOptionValueIds = [];

        foreach ($sortedAttributes as $attributeName => $attributeValue) {
            if (empty($attributeValue)) {
                continue;
            }

            $variantOptionValueId = $this->processAttribute(
                $variant,
                $platform,
                $attributeName,
                $attributeValue
            );

            if ($variantOptionValueId) {
                $variantOptionValueIds[] = $variantOptionValueId;
            }
        }

        // Sync all option values at once in the correct order
        if (! empty($variantOptionValueIds)) {
            // Detach all existing and re-attach in correct order to preserve order
            $variant->optionValues()->detach();

            foreach ($variantOptionValueIds as $valueId) {
                $variant->optionValues()->attach($valueId);
            }
        }
    }

    /**
     * Sort attributes by priority (color first, then size, then others).
     */
    protected function sortAttributesByPriority(array $attributes): array
    {
        $priority = [
            'productcolor' => 1,
            'color' => 1,
            'renk' => 1,
            'productsize' => 2,
            'size' => 2,
            'beden' => 2,
        ];

        uksort($attributes, function ($a, $b) use ($priority) {
            $aNormalized = mb_strtolower(trim($a));
            $bNormalized = mb_strtolower(trim($b));

            $aPriority = $priority[$aNormalized] ?? 999;
            $bPriority = $priority[$bNormalized] ?? 999;

            return $aPriority <=> $bPriority;
        });

        return $attributes;
    }

    /**
     * Process a single attribute and link it to the variant.
     * Returns the variant option value ID.
     */
    protected function processAttribute(
        ProductVariant $variant,
        string $platform,
        string $attributeName,
        string $attributeValue
    ): ?int {
        // Get or create attribute mapping
        $attributeMapping = $this->getOrCreateAttributeMapping($platform, $attributeName);

        if (! $attributeMapping || ! $attributeMapping->is_active) {
            return null;
        }

        // Get or create value mapping with translation support
        $valueMapping = $attributeMapping->getOrCreateValueMapping($attributeValue);

        // Get or create variant option value
        $variantOptionValue = $valueMapping->getOrCreateVariantOptionValue();

        // Link to product if not already linked
        $this->ensureProductHasVariantOption(
            $variant->product,
            $attributeMapping->variant_option_id
        );

        return $variantOptionValue->id;
    }

    /**
     * Get or create an attribute mapping.
     */
    protected function getOrCreateAttributeMapping(
        string $platform,
        string $attributeName
    ): ?AttributeMapping {
        $mapping = AttributeMapping::forPlatform($platform)
            ->where('platform_attribute_name', $attributeName)
            ->first();

        if ($mapping) {
            return $mapping;
        }

        // Try to intelligently map to existing variant options
        $variantOptionId = $this->intelligentlyMapAttribute($attributeName);

        if (! $variantOptionId) {
            // Log for manual review
            activity()
                ->withProperties([
                    'platform' => $platform,
                    'attribute_name' => $attributeName,
                ])
                ->log('unmapped_attribute_detected');

            return null;
        }

        return AttributeMapping::create([
            'platform' => $platform,
            'platform_attribute_name' => $attributeName,
            'variant_option_id' => $variantOptionId,
            'is_active' => true,
        ]);
    }

    /**
     * Intelligently map a platform attribute to a variant option.
     */
    protected function intelligentlyMapAttribute(string $attributeName): ?int
    {
        $normalizedName = mb_strtolower(trim($attributeName));

        // Common mappings for different languages
        $mappings = [
            // Color variations
            'color' => 'color',
            'colour' => 'color',
            'renk' => 'color',
            'productcolor' => 'color',

            // Size variations
            'size' => 'size',
            'beden' => 'size',
            'productsize' => 'size',
        ];

        $variantOptionType = $mappings[$normalizedName] ?? null;

        if (! $variantOptionType) {
            return null;
        }

        return VariantOption::where('type', $variantOptionType)->value('id');
    }

    /**
     * Ensure a product has a specific variant option.
     */
    protected function ensureProductHasVariantOption($product, int $variantOptionId): void
    {
        $exists = $product->variantOptions()->where('variant_option_id', $variantOptionId)->exists();

        if (! $exists) {
            $maxPosition = $product->variantOptions()->max('product_variant_options.position') ?? 0;

            $product->variantOptions()->attach($variantOptionId, [
                'position' => $maxPosition + 1,
            ]);
        }
    }

    /**
     * Get all unmapped attributes for a platform.
     */
    public function getUnmappedAttributes(string $platform): Collection
    {
        // This would query activity log for unmapped_attribute_detected events
        return collect();
    }

    /**
     * Create a bulk mapping for common attributes.
     */
    public function createBulkMappings(string $platform, array $mappings): void
    {
        foreach ($mappings as $attributeName => $variantOptionId) {
            AttributeMapping::updateOrCreate(
                [
                    'platform' => $platform,
                    'platform_attribute_name' => $attributeName,
                ],
                [
                    'variant_option_id' => $variantOptionId,
                    'is_active' => true,
                ]
            );
        }
    }
}
