<?php

namespace App\Helpers;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Cknow\Money\Money;

class CurrencyHelper
{
    /**
     * Get the default currency
     */
    public static function getDefaultCurrency(): ?Currency
    {
        return Currency::getDefault();
    }

    /**
     * Get the default currency code
     */
    public static function getDefaultCurrencyCode(): string
    {
        return static::getDefaultCurrency()?->code ?? 'TRY';
    }

    /**
     * Convert amount from one currency to another
     */
    public static function convert(
        float $amount,
        string|int|Currency $fromCurrency,
        string|int|Currency $toCurrency,
        ?\DateTimeInterface $date = null
    ): ?float {
        $from = static::resolveCurrency($fromCurrency);
        $to = static::resolveCurrency($toCurrency);

        if (! $from || ! $to) {
            return null;
        }

        $rate = ExchangeRate::getRateWithFallback($from->id, $to->id, $date);

        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }

    /**
     * Convert amount to default currency
     */
    public static function convertToDefault(
        float $amount,
        string|int|Currency $fromCurrency,
        ?\DateTimeInterface $date = null
    ): ?float {
        $defaultCurrency = static::getDefaultCurrency();

        if (! $defaultCurrency) {
            return null;
        }

        return static::convert($amount, $fromCurrency, $defaultCurrency, $date);
    }

    /**
     * Convert amount from default currency
     */
    public static function convertFromDefault(
        float $amount,
        string|int|Currency $toCurrency,
        ?\DateTimeInterface $date = null
    ): ?float {
        $defaultCurrency = static::getDefaultCurrency();

        if (! $defaultCurrency) {
            return null;
        }

        return static::convert($amount, $defaultCurrency, $toCurrency, $date);
    }

    /**
     * Format amount with currency symbol
     */
    public static function format(
        float $amount,
        string|int|Currency $currency,
        ?int $decimals = null
    ): string {
        $curr = static::resolveCurrency($currency);

        if (! $curr) {
            return number_format($amount, 2);
        }

        $decimals = $decimals ?? $curr->decimal_places;

        return $curr->symbol.' '.number_format($amount, $decimals);
    }

    /**
     * Format amount in default currency
     */
    public static function formatDefault(float $amount, ?int $decimals = null): string
    {
        $defaultCurrency = static::getDefaultCurrency();

        if (! $defaultCurrency) {
            return number_format($amount, 2);
        }

        return static::format($amount, $defaultCurrency, $decimals);
    }

    /**
     * Format amount with currency code
     */
    public static function formatWithCode(
        float $amount,
        string|int|Currency $currency,
        ?int $decimals = null
    ): string {
        $curr = static::resolveCurrency($currency);

        if (! $curr) {
            return number_format($amount, 2);
        }

        $decimals = $decimals ?? $curr->decimal_places;

        return number_format($amount, $decimals).' '.$curr->code;
    }

    /**
     * Format amount with dual currency display (original + default)
     */
    public static function formatDual(
        float $amount,
        string|int|Currency $currency,
        ?\DateTimeInterface $date = null,
        ?int $decimals = null
    ): string {
        $curr = static::resolveCurrency($currency);
        $defaultCurrency = static::getDefaultCurrency();

        if (! $curr) {
            return number_format($amount, 2);
        }

        $decimals = $decimals ?? $curr->decimal_places;
        $display = static::formatWithCode($amount, $curr, $decimals);

        // If not default currency, add conversion
        if ($defaultCurrency && $curr->id !== $defaultCurrency->id) {
            $converted = static::convert($amount, $curr, $defaultCurrency, $date);

            if ($converted !== null) {
                $display .= ' ≈ '.static::formatWithCode($converted, $defaultCurrency);
            }
        }

        return $display;
    }

    /**
     * Get exchange rate between two currencies
     */
    public static function getRate(
        string|int|Currency $fromCurrency,
        string|int|Currency $toCurrency,
        ?\DateTimeInterface $date = null
    ): ?float {
        $from = static::resolveCurrency($fromCurrency);
        $to = static::resolveCurrency($toCurrency);

        if (! $from || ! $to) {
            return null;
        }

        return ExchangeRate::getRateWithFallback($from->id, $to->id, $date);
    }

    /**
     * Create Money object from amount and currency
     */
    public static function money(
        float $amount,
        string|int|Currency $currency
    ): ?Money {
        $curr = static::resolveCurrency($currency);

        if (! $curr) {
            return null;
        }

        return Money::parse($amount, $curr->code);
    }

    /**
     * Resolve currency from various input types
     */
    protected static function resolveCurrency(string|int|Currency $currency): ?Currency
    {
        if ($currency instanceof Currency) {
            return $currency;
        }

        if (is_int($currency)) {
            return Currency::find($currency);
        }

        return Currency::where('code', $currency)->first();
    }
}
