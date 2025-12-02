<?php

namespace App\Models\Order;

use App\Models\Product\ProductVariant;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id',
        'product_variant_id',
        'quantity',
        'unit_price',
        'total_price',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'commission_amount',
        'commission_rate',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => MoneyIntegerCast::class,
            'total_price' => MoneyIntegerCast::class,
            'discount_amount' => MoneyIntegerCast::class,
            'tax_amount' => MoneyIntegerCast::class,
            'commission_amount' => MoneyIntegerCast::class,
            'tax_rate' => 'decimal:2',
            'commission_rate' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(\App\Models\Platform\PlatformMapping::class, 'entity');
    }

    public function getReturnedQuantity(): int
    {
        // If returned_quantity is loaded via withSum, use it
        if (isset($this->attributes['returned_quantity'])) {
            return (int) $this->attributes['returned_quantity'];
        }

        // Otherwise, calculate it
        return $this->returnItems()->sum('quantity');
    }

    public function isFullyReturned(): bool
    {
        return $this->getReturnedQuantity() >= $this->quantity;
    }

    public function isPartiallyReturned(): bool
    {
        $returned = $this->getReturnedQuantity();

        return $returned > 0 && $returned < $this->quantity;
    }
}
