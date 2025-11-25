<?php

namespace App\Models\Product;

use App\Models\Platform\PlatformMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Product extends Model
{
    protected $fillable = [
        'product_group_id',
        'title',
        'description',
        'vendor',
        'product_type',
        'status',
    ];

    public function productGroup(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(PlatformMapping::class, 'entity');
    }
}
