<?php

namespace App\Filament\Resources\Accounting\Expenses\Pages;

use App\Enums\Accounting\TransactionType;
use App\Filament\Resources\Accounting\Expenses\ExpenseResource;
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

        // Get currency code from account's currency
        if (isset($data['account_id'])) {
            $account = \App\Models\Accounting\Account::find($data['account_id']);
            $data['currency'] = $account?->currency?->code ?? 'TRY';
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
