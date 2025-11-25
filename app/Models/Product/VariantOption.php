<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariantOption extends Model
{
    protected $fillable = [
        'name',
        'position',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(VariantOptionValue::class)->orderBy('position');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_variant_options')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function getTranslatedNameAttribute(): string
    {
        return __($this->name);
    }
}
