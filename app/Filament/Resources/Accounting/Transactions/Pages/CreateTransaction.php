<?php

namespace App\Filament\Resources\Accounting\Transactions\Pages;

use App\Filament\Resources\Accounting\Transactions\TransactionResource;
use App\Helpers\CurrencyHelper;
use App\Models\Accounting\Account;
use App\Models\Currency;
use Filament\Resources\Pages\CreateRecord;

class CreateTransaction extends CreateRecord
{
    protected static string $resource = TransactionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Get account to determine target currency for exchange rate calculation
        $account = Account::find($data['account_id']);

        if (! $account) {
            return $data;
        }

        // Get transaction currency
        $transactionCurrency = Currency::find($data['currency_id']);

        if (! $transactionCurrency) {
            $transactionCurrency = Currency::getDefault() ?? Currency::where('code', 'TRY')->first();
            $data['currency_id'] = $transactionCurrency->id;
        }

        $data['currency'] = $transactionCurrency->code;

        // Calculate exchange rate from transaction currency to account currency
        $exchangeRate = 1.0;

        if ($transactionCurrency->id !== $account->currency_id) {
            // Get current exchange rate from transaction currency to account currency
            $rate = CurrencyHelper::getRate($transactionCurrency, $account->currency);

            if ($rate) {
                $exchangeRate = $rate;
            }
        }

        $data['exchange_rate'] = $exchangeRate;

        return $data;
    }
}
