<?php

namespace App\Models\Product;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use App\Models\Inventory\LocationInventory;
use App\Models\Order\OrderItem;
use App\Models\Platform\PlatformMapping;
use Cknow\Money\Casts\MoneyIntegerCast;
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
            'price' => MoneyIntegerCast::class,
            'cost_price' => MoneyIntegerCast::class,
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
            ->withPivot('id')
            ->orderByPivot('id')
            ->withTimestamps();
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_inventory')
            ->withPivot('quantity')
            ->withTimestamps();
    }

    public function locationInventories(): HasMany
    {
        return $this->hasMany(LocationInventory::class);
    }

    public function channelAvailability(): HasMany
    {
        return $this->hasMany(ProductChannelAvailability::class);
    }

    /**
     * Check if variant is available on a specific channel.
     */
    public function isAvailableOnChannel(string|\App\Enums\Order\OrderChannel $channel): bool
    {
        if ($channel instanceof \App\Enums\Order\OrderChannel) {
            $channel = $channel->value;
        }

        return $this->channelAvailability()
            ->where('channel', $channel)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Enable variant on a channel.
     */
    public function enableOnChannel(string|\App\Enums\Order\OrderChannel $channel, ?array $settings = null): void
    {
        if ($channel instanceof \App\Enums\Order\OrderChannel) {
            $channel = $channel->value;
        }

        $this->channelAvailability()->updateOrCreate(
            ['channel' => $channel],
            [
                'is_enabled' => true,
                'channel_settings' => $settings,
            ]
        );
    }

    /**
     * Disable variant on a channel.
     */
    public function disableOnChannel(string|\App\Enums\Order\OrderChannel $channel): void
    {
        if ($channel instanceof \App\Enums\Order\OrderChannel) {
            $channel = $channel->value;
        }

        $this->channelAvailability()
            ->where('channel', $channel)
            ->update(['is_enabled' => false]);
    }

    /**
     * Get enabled channels for this variant.
     */
    public function getEnabledChannels(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->channelAvailability()
            ->where('is_enabled', true)
            ->get();
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

        return $value ? $value->getTranslation('value', app()->getLocale()) : __('No value');
    }

    /**
     * Get total available quantity across all locations.
     */
    public function totalAvailableQuantity(): int
    {
        return $this->locations()->sum('location_inventory.quantity');
    }

    /**
     * Update the inventory_quantity column based on location inventory.
     * This syncs the variant's inventory_quantity with the sum of all location quantities.
     */
    public function syncInventoryQuantity(): void
    {
        $this->update([
            'inventory_quantity' => $this->totalAvailableQuantity(),
        ]);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
            ->useFallbackUrl('/images/no-image.png')
            ->useFallbackPath(public_path('/images/no-image.png'));
    }
}
