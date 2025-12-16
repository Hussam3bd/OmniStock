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
}
