<?php

namespace App\Models\Purchase;

use App\Models\Product\ProductVariant;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_variant_id',
        'quantity_ordered',
        'quantity_received',
        'unit_cost',
        'tax_rate',
        'subtotal',
        'tax_amount',
        'total',
    ];

    protected $attributes = [
        'quantity_received' => 0,
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'quantity_received' => 'integer',
            'unit_cost' => MoneyIntegerCast::class,
            'tax_rate' => 'decimal:2',
            'subtotal' => MoneyIntegerCast::class,
            'tax_amount' => MoneyIntegerCast::class,
            'total' => MoneyIntegerCast::class,
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(\App\Models\Inventory\InventoryMovement::class);
    }
}
