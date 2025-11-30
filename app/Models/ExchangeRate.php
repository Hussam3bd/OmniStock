<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency_id',
        'to_currency_id',
        'rate',
        'effective_date',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'effective_date' => 'date',
        ];
    }

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency_id');
    }

    /**
     * Get the latest exchange rate for a currency pair
     */
    public static function getRate(int $fromCurrencyId, int $toCurrencyId, ?\DateTimeInterface $date = null): ?float
    {
        // If same currency, rate is 1.0
        if ($fromCurrencyId === $toCurrencyId) {
            return 1.0;
        }

        $date = $date ?? now();

        $rate = static::where('from_currency_id', $fromCurrencyId)
            ->where('to_currency_id', $toCurrencyId)
            ->where('effective_date', '<=', $date)
            ->orderBy('effective_date', 'desc')
            ->first();

        return $rate?->rate;
    }

    /**
     * Get the latest exchange rate, trying reverse if direct not found
     */
    public static function getRateWithFallback(int $fromCurrencyId, int $toCurrencyId, ?\DateTimeInterface $date = null): ?float
    {
        $rate = static::getRate($fromCurrencyId, $toCurrencyId, $date);

        if ($rate !== null) {
            return $rate;
        }

        // Try reverse rate
        $reverseRate = static::getRate($toCurrencyId, $fromCurrencyId, $date);

        return $reverseRate ? (1 / $reverseRate) : null;
    }
}
