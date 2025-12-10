<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get all distinct currency codes from existing orders
        $currencyCodes = DB::table('orders')
            ->select('currency')
            ->distinct()
            ->whereNotNull('currency')
            ->pluck('currency');

        foreach ($currencyCodes as $code) {
            // Find the currency_id for this code
            $currency = DB::table('currencies')
                ->where('code', $code)
                ->first();

            if ($currency) {
                // Update all orders with this currency code
                DB::table('orders')
                    ->where('currency', $code)
                    ->update([
                        'currency_id' => $currency->id,
                        // Exchange rate 1.0 means the order was in its native currency
                        // (no conversion needed at the time of order creation)
                        'exchange_rate' => 1.0,
                        'updated_at' => now(),
                    ]);
            }
        }

        // Handle any orders with null currency (shouldn't happen, but defensive)
        $defaultCurrency = DB::table('currencies')
            ->where('is_default', true)
            ->first();

        if ($defaultCurrency) {
            DB::table('orders')
                ->whereNull('currency_id')
                ->update([
                    'currency' => $defaultCurrency->code,
                    'currency_id' => $defaultCurrency->id,
                    'exchange_rate' => 1.0,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set currency_id and exchange_rate back to null
        DB::table('orders')->update([
            'currency_id' => null,
            'exchange_rate' => null,
        ]);
    }
};
