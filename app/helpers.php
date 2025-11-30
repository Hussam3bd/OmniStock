<?php

use App\Helpers\CurrencyHelper;
use App\Models\Currency;

if (! function_exists('currency_convert')) {
    /**
     * Convert amount from one currency to another
     */
    function currency_convert(
        float $amount,
        string|int|Currency $fromCurrency,
        string|int|Currency $toCurrency,
        ?\DateTimeInterface $date = null
    ): ?float {
        return CurrencyHelper::convert($amount, $fromCurrency, $toCurrency, $date);
    }
}

if (! function_exists('currency_to_default')) {
    /**
     * Convert amount to default currency
     */
    function currency_to_default(
        float $amount,
        string|int|Currency $fromCurrency,
        ?\DateTimeInterface $date = null
    ): ?float {
        return CurrencyHelper::convertToDefault($amount, $fromCurrency, $date);
    }
}

if (! function_exists('currency_format')) {
    /**
     * Format amount with currency symbol
     */
    function currency_format(
        float $amount,
        string|int|Currency $currency,
        ?int $decimals = null
    ): string {
        return CurrencyHelper::format($amount, $currency, $decimals);
    }
}

if (! function_exists('currency_format_code')) {
    /**
     * Format amount with currency code
     */
    function currency_format_code(
        float $amount,
        string|int|Currency $currency,
        ?int $decimals = null
    ): string {
        return CurrencyHelper::formatWithCode($amount, $currency, $decimals);
    }
}

if (! function_exists('currency_format_dual')) {
    /**
     * Format amount with dual currency display (original + default)
     */
    function currency_format_dual(
        float $amount,
        string|int|Currency $currency,
        ?\DateTimeInterface $date = null,
        ?int $decimals = null
    ): string {
        return CurrencyHelper::formatDual($amount, $currency, $date, $decimals);
    }
}

if (! function_exists('default_currency')) {
    /**
     * Get the default currency
     */
    function default_currency(): ?Currency
    {
        return CurrencyHelper::getDefaultCurrency();
    }
}

if (! function_exists('default_currency_code')) {
    /**
     * Get the default currency code
     */
    function default_currency_code(): string
    {
        return CurrencyHelper::getDefaultCurrencyCode();
    }
}
