<?php

namespace App\Models\Accounting;

use App\Enums\Accounting\AccountType;
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
     */
    public function recalculateBalance(): void
    {
        $income = $this->transactions()
            ->where('type', 'income')
            ->whereNull('is_internal_transfer')
            ->whereNull('is_refund')
            ->sum('amount');

        $expenses = $this->transactions()
            ->where('type', 'expense')
            ->whereNull('is_internal_transfer')
            ->whereNull('is_refund')
            ->sum('amount');

        $capital = $this->transactions()
            ->where('type', 'capital')
            ->sum('amount');

        // Balance = Income - Expenses + Capital
        $balance = $income - $expenses + $capital;

        $this->update(['balance' => $balance]);
    }
}
