<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Order\Order;
use App\Models\Purchase\PurchaseOrder;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    protected $fillable = [
        'account_id',
        'transactionable_type',
        'transactionable_id',
        'order_id',
        'purchase_order_id',
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
            'type' => TransactionType::class,
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Get the parent transactionable model (Order or PurchaseOrder)
     */
    public function transactionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get category as enum based on transaction type
     */
    public function getCategoryEnumAttribute(): ExpenseCategory|IncomeCategory|null
    {
        if (! $this->category) {
            return null;
        }

        return match ($this->type) {
            TransactionType::INCOME => IncomeCategory::tryFrom($this->category),
            TransactionType::EXPENSE => ExpenseCategory::tryFrom($this->category),
            default => null,
        };
    }
}
