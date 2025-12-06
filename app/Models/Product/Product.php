<?php

namespace App\Models\Product;

use App\Models\Platform\PlatformMapping;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'model_code',
        'gtin',
        'title',
        'description',
        'vendor',
        'product_type',
        'status',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(PlatformMapping::class, 'entity');
    }

    public function variantOptions(): BelongsToMany
    {
        return $this->belongsToMany(VariantOption::class, 'product_variant_options')
            ->withPivot('position')
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('/images/no-image.png')
            ->useFallbackPath(public_path('/images/no-image.png'));
    }

    public function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->title,
        );
    }
}
