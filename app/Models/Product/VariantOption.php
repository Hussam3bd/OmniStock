<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariantOption extends Model
{
    protected $fillable = [
        'name',
        'type',
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

    /**
     * Check if this is a system variant option (color or size).
     */
    public function isSystemType(): bool
    {
        return in_array($this->type, ['color', 'size']);
    }

    /**
     * Scope to get color variant option.
     */
    public function scopeColor($query)
    {
        return $query->where('type', 'color');
    }

    /**
     * Scope to get size variant option.
     */
    public function scopeSize($query)
    {
        return $query->where('type', 'size');
    }
}
