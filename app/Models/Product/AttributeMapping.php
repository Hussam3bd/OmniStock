<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttributeMapping extends Model
{
    protected $fillable = [
        'platform',
        'platform_attribute_id',
        'platform_attribute_name',
        'variant_option_id',
        'mapping_rules',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'mapping_rules' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function variantOption(): BelongsTo
    {
        return $this->belongsTo(VariantOption::class);
    }

    public function valueMappings(): HasMany
    {
        return $this->hasMany(AttributeValueMapping::class);
    }

    /**
     * Get or create a value mapping for a platform value.
     */
    public function getOrCreateValueMapping(string $platformValue): AttributeValueMapping
    {
        return $this->valueMappings()
            ->firstOrCreate(
                ['platform_value' => $platformValue],
                ['normalized_value' => $this->normalizeValue($platformValue)]
            );
    }

    /**
     * Normalize a value for consistent matching.
     */
    protected function normalizeValue(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * Scope to active mappings.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to a specific platform.
     */
    public function scopeForPlatform($query, string $platform)
    {
        return $query->where('platform', $platform);
    }
}
