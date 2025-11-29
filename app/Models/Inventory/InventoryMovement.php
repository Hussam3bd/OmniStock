<?php

namespace App\Models\Inventory;

use App\Models\Order\Order;
use App\Models\Product\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'product_variant_id',
        'order_id',
        'purchase_order_item_id',
        'type',
        'quantity',
        'quantity_before',
        'quantity_after',
        'reference',
    ];

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Purchase\PurchaseOrderItem::class);
    }
}
