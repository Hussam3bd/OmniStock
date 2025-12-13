<?php

use App\Models\Accounting\Transaction;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Get default currency (typically TRY)
        $defaultCurrency = Currency::where('is_default', true)->first();

        if (! $defaultCurrency) {
            // If no default currency, try TRY
            $defaultCurrency = Currency::where('code', 'TRY')->first();
        }

        if (! $defaultCurrency) {
            // Cannot proceed without a default currency
            return;
        }

        // Populate currency_id and exchange_rate for existing transactions
        DB::table('transactions')->chunkById(100, function ($transactions) use ($defaultCurrency) {
            foreach ($transactions as $transaction) {
                // Find currency by code
                $currency = Currency::where('code', strtoupper($transaction->currency))->first();

                if (! $currency) {
                    // Fallback to default currency
                    $currency = $defaultCurrency;
                }

                // Get account to determine target currency
                $account = DB::table('accounts')->find($transaction->account_id);

                if (! $account) {
                    continue;
                }

                $accountCurrency = Currency::find($account->currency_id);

                if (! $accountCurrency) {
                    $accountCurrency = $defaultCurrency;
                }

                // Calculate exchange rate from transaction currency to account currency
                $exchangeRate = 1.0;

                if ($currency->id !== $accountCurrency->id) {
                    // Get exchange rate at transaction date
                    $transactionDate = $transaction->transaction_date ? new \DateTime($transaction->transaction_date) : now();
                    $rate = ExchangeRate::getRateWithFallback($currency->id, $accountCurrency->id, $transactionDate);

                    if ($rate) {
                        $exchangeRate = $rate;
                    }
                }

                // Update transaction with currency_id and exchange_rate
                DB::table('transactions')
                    ->where('id', $transaction->id)
                    ->update([
                        'currency_id' => $currency->id,
                        'exchange_rate' => $exchangeRate,
                    ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Set currency_id and exchange_rate to null for all transactions
        DB::table('transactions')->update([
            'currency_id' => null,
            'exchange_rate' => null,
        ]);
    }
};
