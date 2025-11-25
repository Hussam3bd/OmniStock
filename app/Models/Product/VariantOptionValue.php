<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class VariantOptionValue extends Model
{
    protected $fillable = [
        'variant_option_id',
        'value',
        'position',
    ];

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
        return __($this->value);
    }
}
