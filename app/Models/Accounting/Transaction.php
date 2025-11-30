<?php

namespace App\Models\Accounting;

use App\Models\Order\Order;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    protected $fillable = [
        'account_id',
        'order_id',
        'type',
        'category',
        'amount',
        'currency',
        'description',
        'transaction_date',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyIntegerCast::class,
            'transaction_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
