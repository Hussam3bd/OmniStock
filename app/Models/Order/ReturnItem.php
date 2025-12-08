<?php

namespace App\Models\Order;

use App\Enums\Order\ReturnReason;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    protected $fillable = [
        'return_id',
        'order_item_id',
        'quantity',
        'return_reason',
        'reason_name',
        'note',
        'received_condition',
        'inspection_note',
        'refund_amount',
        'external_item_id',
        'platform_data',
    ];

    protected function casts(): array
    {
        return [
            'return_reason' => ReturnReason::class,
            'refund_amount' => MoneyIntegerCast::class,
            'platform_data' => 'array',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function isGoodCondition(): bool
    {
        return $this->received_condition === 'good';
    }

    public function isDamaged(): bool
    {
        return in_array($this->received_condition, ['damaged', 'defective']);
    }
}
