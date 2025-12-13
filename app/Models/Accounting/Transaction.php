<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Currency;
use App\Models\Order\Order;
use App\Models\Purchase\PurchaseOrder;
use Cknow\Money\Casts\MoneyIntegerCast;
use Cknow\Money\Money;
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
        'currency_id',
        'exchange_rate',
        'description',
        'transaction_date',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => MoneyIntegerCast::class,
            'exchange_rate' => 'decimal:8',
            'transaction_date' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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

    /**
     * Get the transaction amount converted to account currency
     */
    public function getAmountInAccountCurrency(): Money
    {
        if (! $this->account) {
            return $this->amount;
        }

        // If same currency, no conversion needed
        if ($this->currency === $this->account->currency->code) {
            return $this->amount;
        }

        // Apply stored exchange rate
        if (! $this->exchange_rate) {
            return new Money($this->amount->getAmount(), $this->account->currency->code);
        }

        $convertedAmountInMinorUnits = $this->amount->getAmount() * $this->exchange_rate;

        return new Money((int) round($convertedAmountInMinorUnits), $this->account->currency->code);
    }

    /**
     * Get formatted transaction amount with currency
     */
    public function getFormattedAmount(): string
    {
        return $this->amount->format();
    }

    /**
     * Get formatted amount in account currency
     */
    public function getFormattedAmountInAccountCurrency(): string
    {
        return $this->getAmountInAccountCurrency()->format();
    }

    /**
     * Check if transaction involves currency conversion
     */
    public function hasCurrencyConversion(): bool
    {
        if (! $this->account) {
            return false;
        }

        return $this->currency !== $this->account->currency->code;
    }

    /**
     * Get dual currency display (original + converted)
     */
    public function getDualCurrencyDisplay(): string
    {
        if (! $this->hasCurrencyConversion()) {
            return $this->getFormattedAmount();
        }

        return $this->getFormattedAmount().' ≈ '.$this->getFormattedAmountInAccountCurrency();
    }
}
