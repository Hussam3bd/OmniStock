<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    protected string $apiUrl = 'https://api.exchangerate-api.com/v4/latest/';

    /**
     * Fetch and store exchange rates for a given base currency
     */
    public function updateRatesForCurrency(Currency $baseCurrency): bool
    {
        try {
            $response = Http::timeout(30)->get($this->apiUrl.$baseCurrency->code);

            if (! $response->successful()) {
                Log::error('Failed to fetch exchange rates', [
                    'currency' => $baseCurrency->code,
                    'status' => $response->status(),
                ]);

                return false;
            }

            $data = $response->json();
            $rates = $data['rates'] ?? [];

            if (empty($rates)) {
                Log::warning('No exchange rates found', ['currency' => $baseCurrency->code]);

                return false;
            }

            $effectiveDate = now()->toDateString();

            foreach ($rates as $targetCurrencyCode => $rate) {
                $targetCurrency = Currency::where('code', $targetCurrencyCode)->first();

                if (! $targetCurrency) {
                    continue; // Skip currencies not in our database
                }

                // Don't store rate for same currency
                if ($baseCurrency->id === $targetCurrency->id) {
                    continue;
                }

                ExchangeRate::updateOrCreate(
                    [
                        'from_currency_id' => $baseCurrency->id,
                        'to_currency_id' => $targetCurrency->id,
                        'effective_date' => $effectiveDate,
                    ],
                    [
                        'rate' => $rate,
                    ]
                );
            }

            Log::info('Exchange rates updated successfully', [
                'base_currency' => $baseCurrency->code,
                'rates_count' => count($rates),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Exception while updating exchange rates', [
                'currency' => $baseCurrency->code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Update exchange rates for all active currencies
     */
    public function updateAllRates(): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'currencies' => [],
        ];

        $baseCurrencies = Currency::where('is_active', true)
            ->whereIn('code', ['USD', 'EUR', 'TRY', 'GBP']) // Update from major currencies only
            ->get();

        foreach ($baseCurrencies as $currency) {
            if ($this->updateRatesForCurrency($currency)) {
                $results['success']++;
                $results['currencies'][] = $currency->code;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get the current exchange rate between two currencies
     */
    public function getCurrentRate(Currency $fromCurrency, Currency $toCurrency): ?float
    {
        return ExchangeRate::getRateWithFallback($fromCurrency->id, $toCurrency->id);
    }

    /**
     * Convert an amount from one currency to another
     */
    public function convert(float $amount, Currency $fromCurrency, Currency $toCurrency): ?float
    {
        $rate = $this->getCurrentRate($fromCurrency, $toCurrency);

        if ($rate === null) {
            return null;
        }

        return $amount * $rate;
    }
}
