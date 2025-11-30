<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $jsonPath = database_path('seeders/data/currencies.json');
        $currenciesData = json_decode(file_get_contents($jsonPath), true);

        foreach ($currenciesData as $currencyData) {
            // Decode HTML entities in symbols
            $symbol = html_entity_decode($currencyData['symbol'] ?? $currencyData['code']);

            Currency::updateOrCreate(
                ['code' => $currencyData['code']],
                [
                    'code' => $currencyData['code'],
                    'name' => $currencyData['english_name'],
                    'symbol' => $symbol,
                    'decimal_places' => 2, // Default to 2 decimal places
                    'is_default' => $currencyData['code'] === 'TRY', // TRY is default
                    'is_active' => true, // All active by default
                ]
            );
        }
    }
}
