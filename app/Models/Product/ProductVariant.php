<?php

namespace App\Models\Product;

use App\Models\Inventory\InventoryMovement;
use App\Models\Order\OrderItem;
use App\Models\Platform\PlatformMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class ProductVariant extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'title',
        'price',
        'cost_price',
        'inventory_quantity',
        'weight',
        'weight_unit',
        'requires_shipping',
        'taxable',
    ];

    protected function casts(): array
    {
        return [
            'price' => \Cknow\Money\Casts\MoneyIntegerCast::class,
            'cost_price' => \Cknow\Money\Casts\MoneyIntegerCast::class,
            'weight' => 'decimal:2',
            'requires_shipping' => 'boolean',
            'taxable' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(PlatformMapping::class, 'entity');
    }

    public function optionValues(): BelongsToMany
    {
        return $this->belongsToMany(VariantOptionValue::class, 'product_variant_option_values')
            ->withTimestamps();
    }

    /**
     * Get the option value for a specific variant option.
     * This is used for table grouping.
     */
    public function getOptionValueForOption(int $variantOptionId): ?VariantOptionValue
    {
        return $this->optionValues
            ->where('variant_option_id', $variantOptionId)
            ->first();
    }

    /**
     * Get the grouping key for a specific variant option.
     */
    public function getOptionKeyForGroup(int $variantOptionId): string
    {
        $value = $this->getOptionValueForOption($variantOptionId);

        return $value ? (string) $value->id : 'none';
    }

    /**
     * Get the grouping title for a specific variant option.
     */
    public function getOptionTitleForGroup(int $variantOptionId): string
    {
        $value = $this->getOptionValueForOption($variantOptionId);

        return $value ? __($value->value) : __('No value');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('/images/no-image.png')
            ->useFallbackPath(public_path('/images/no-image.png'));
    }
}
