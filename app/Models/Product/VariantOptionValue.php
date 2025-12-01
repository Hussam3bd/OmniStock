<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Translatable\HasTranslations;

class VariantOptionValue extends Model
{
    use HasTranslations;

    protected $fillable = [
        'variant_option_id',
        'value',
        'position',
    ];

    public array $translatable = ['value'];

    public function variantOption(): BelongsTo
    {
        return $this->belongsTo(VariantOption::class);
    }

    public function productVariants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'product_variant_option_values')
            ->withTimestamps();
    }

    public function getTranslatedValueAttribute(): string
    {
        return $this->getTranslation('value', app()->getLocale());
    }
}
