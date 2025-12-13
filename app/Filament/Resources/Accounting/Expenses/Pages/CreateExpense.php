<?php

namespace App\Filament\Resources\Accounting\Expenses\Pages;

use App\Enums\Accounting\TransactionType;
use App\Filament\Resources\Accounting\Expenses\ExpenseResource;
use App\Models\Accounting\Account;
use App\Models\Currency;
use Filament\Resources\Pages\CreateRecord;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Always set type to EXPENSE
        $data['type'] = TransactionType::EXPENSE;

        // Set transaction date to today if not provided
        $data['transaction_date'] = $data['transaction_date'] ?? now();

        // Get account to determine currency
        $account = Account::find($data['account_id']);

        if ($account) {
            // For expenses, the transaction currency is always the account's currency
            $data['currency'] = $account->currency->code;
            $data['currency_id'] = $account->currency_id;

            // Since transaction currency = account currency, exchange rate is 1.0
            $data['exchange_rate'] = 1.0;
        } else {
            // Fallback to default currency
            $defaultCurrency = Currency::getDefault() ?? Currency::where('code', 'TRY')->first();
            $data['currency'] = $defaultCurrency->code;
            $data['currency_id'] = $defaultCurrency->id;
            $data['exchange_rate'] = 1.0;
        }

        // Convert amount to minor units (cents)
        if (isset($data['amount'])) {
            $data['amount'] = (int) ($data['amount'] * 100);
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
