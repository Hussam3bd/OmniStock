<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValueMapping extends Model
{
    protected $fillable = [
        'attribute_mapping_id',
        'platform_value',
        'variant_option_value_id',
        'normalized_value',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
        ];
    }

    public function attributeMapping(): BelongsTo
    {
        return $this->belongsTo(AttributeMapping::class);
    }

    public function variantOptionValue(): BelongsTo
    {
        return $this->belongsTo(VariantOptionValue::class);
    }

    /**
     * Get or create a variant option value for this mapping.
     */
    public function getOrCreateVariantOptionValue(): VariantOptionValue
    {
        if ($this->variant_option_value_id) {
            return $this->variantOptionValue;
        }

        // Try to find existing value by normalized match (case-insensitive)
        $variantOptionId = $this->attributeMapping->variant_option_id;

        // Search in both English and Turkish translations (case-insensitive)
        $existingValue = VariantOptionValue::where('variant_option_id', $variantOptionId)
            ->where(function ($query) {
                $query->whereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(value, "$.en"))) = LOWER(?)', [$this->platform_value])
                    ->orWhereRaw('LOWER(JSON_UNQUOTE(JSON_EXTRACT(value, "$.tr"))) = LOWER(?)', [$this->platform_value]);
            })
            ->first();

        if ($existingValue) {
            $this->update(['variant_option_value_id' => $existingValue->id]);

            return $existingValue;
        }

        // Create new variant option value with translations
        // Determine if the value is Turkish or English and set appropriately
        $isTurkish = $this->detectTurkish($this->platform_value);

        $newValue = VariantOptionValue::create([
            'variant_option_id' => $variantOptionId,
            'value' => [
                'en' => $isTurkish ? '' : $this->platform_value,
                'tr' => $isTurkish ? $this->platform_value : '',
            ],
            'position' => VariantOptionValue::where('variant_option_id', $variantOptionId)->max('position') + 1,
        ]);

        $this->update(['variant_option_value_id' => $newValue->id]);

        return $newValue;
    }

    /**
     * Scope to verified mappings.
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope to unverified mappings.
     */
    public function scopeUnverified($query)
    {
        return $query->where('is_verified', false);
    }

    /**
     * Detect if the value is Turkish based on Turkish-specific characters.
     */
    protected function detectTurkish(string $value): bool
    {
        // Turkish-specific characters: ı, ğ, ü, ş, ö, ç, İ, Ğ, Ü, Ş, Ö, Ç
        $turkishPattern = '/[ıİğĞüÜşŞöÖçÇ]/u';

        // Also check if it matches common Turkish color/size names (case-insensitive)
        $turkishWords = [
            'Kahverengi', 'Siyah', 'Beyaz', 'Kırmızı', 'Mavi', 'Yeşil', 'Sarı', 'Turuncu',
            'Pembe', 'Mor', 'Gri', 'Lacivert', 'Bej', 'Bordo', 'Haki', 'Ekru', 'Krem',
            'Turkuaz', 'Metalik', 'Gümüş', 'Altın', 'Çok Renkli',
            'küçük', 'büyük', 'orta',
        ];

        // Case-insensitive comparison
        $normalizedValue = mb_strtolower($value);
        $normalizedTurkishWords = array_map('mb_strtolower', $turkishWords);

        return preg_match($turkishPattern, $value) || in_array($normalizedValue, $normalizedTurkishWords);
    }
}
