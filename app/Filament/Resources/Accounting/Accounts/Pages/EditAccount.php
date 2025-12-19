<?php

namespace App\Filament\Resources\Accounting\Accounts\Pages;

use App\Filament\Imports\BankAccountTransactionImporter;
use App\Filament\Imports\CreditCardTransactionImporter;
use App\Filament\Resources\Accounting\Accounts\AccountResource;
use App\Services\Accounting\InternalTransferDetectionService;
use App\Services\Accounting\RefundDetectionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ImportAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make('import_credit_card')
                ->label(__('Import Credit Card Transactions'))
                ->color('info')
                ->importer(CreditCardTransactionImporter::class)
                ->options([
                    'accountId' => $this->record->id,
                ])
                ->modalHeading(__('Import Credit Card Transactions'))
                ->modalDescription(__('Upload a CSV file with credit card transactions. The CSV should have columns for: Date, Description, Label, Amount')),
            ImportAction::make('import_bank_account')
                ->label(__('Import Bank Account Transactions'))
                ->color('success')
                ->importer(BankAccountTransactionImporter::class)
                ->options([
                    'accountId' => $this->record->id,
                ])
                ->modalHeading(__('Import Bank Account Transactions'))
                ->modalDescription(__('Upload a CSV file with bank account transactions. The CSV should have columns for: Date, Description, Label, Amount, Balance, Reference No')),
            Action::make('detect_refunds')
                ->label(__('Detect Refunds'))
                ->color('warning')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->requiresConfirmation()
                ->modalHeading(__('Detect Refund Pairs'))
                ->modalDescription(__('This will scan all transactions in this account and automatically detect refund pairs (matching transactions with opposite amounts). Detected refunds will be marked and linked together.'))
                ->action(function () {
                    $service = app(RefundDetectionService::class);
                    $results = $service->detectRefunds($this->record->id);

                    Notification::make()
                        ->title(__('Refund Detection Complete'))
                        ->body(__('Found :count refund pairs (:total transactions marked)', [
                            'count' => $results['refund_pairs_found'],
                            'total' => $results['transactions_marked'],
                        ]))
                        ->success()
                        ->send();
                }),
            Action::make('detect_transfers')
                ->label(__('Detect Internal Transfers'))
                ->color('info')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalHeading(__('Detect Internal Transfers'))
                ->modalDescription(__('This will scan transactions and detect internal transfers (credit card payments, bank transfers, etc.) based on description patterns. Detected transfers will be marked accordingly.'))
                ->action(function () {
                    $service = app(InternalTransferDetectionService::class);
                    $results = $service->detectTransfers($this->record->id);

                    Notification::make()
                        ->title(__('Transfer Detection Complete'))
                        ->body(__('Found :pairs transfer pairs and :singles single transfers (:total transactions marked)', [
                            'pairs' => $results['transfer_pairs_found'],
                            'singles' => $results['single_transfers_marked'],
                            'total' => $results['total_transactions_marked'],
                        ]))
                        ->success()
                        ->send();
                }),
            DeleteAction::make(),
        ];
    }
}
