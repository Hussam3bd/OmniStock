<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\TransactionType;
use App\Models\Concerns\HasCurrencyCode;
use App\Models\Currency;
use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasCurrencyCode;

    protected $fillable = [
        'name',
        'type',
        'currency_id',
        'currency_code',
        'balance',
        'description',
    ];

    protected $with = ['currency'];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'balance' => MoneyIntegerCast::class.':currency_code',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Recalculate account balance from all transactions
     * Includes all transactions (transfers affect account balance)
     * Uses single query + collection filtering for better performance
     */
    public function recalculateBalance(): void
    {
        // Fetch all transactions once
        $transactions = $this->transactions()
            ->get(['type', 'amount']);

        // Calculate sums using collection methods (extract Money amount as integer)
        // Include ALL transactions - transfers affect account balance
        $income = $transactions
            ->where('type', TransactionType::INCOME)
            ->sum(fn ($t) => $t->amount->getAmount());

        $expenses = $transactions
            ->where('type', TransactionType::EXPENSE)
            ->sum(fn ($t) => $t->amount->getAmount());

        $capital = $transactions
            ->where('type', TransactionType::CAPITAL)
            ->sum(fn ($t) => $t->amount->getAmount());

        // Balance = Income - Expenses + Capital
        $balance = $income - $expenses + $capital;

        $this->update(['balance' => $balance]);
    }
}
