<?php

namespace App\Models\Order;

use App\Models\Product\ProductVariant;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
