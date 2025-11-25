<?php

namespace App\Models\Product;

use App\Models\Inventory\InventoryMovement;
use App\Models\Order\OrderItem;
use App\Models\Platform\PlatformMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'title',
        'option1',
        'option2',
        'option3',
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
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
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
}
