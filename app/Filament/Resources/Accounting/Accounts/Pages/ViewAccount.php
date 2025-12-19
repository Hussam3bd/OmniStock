<?php

namespace App\Filament\Resources\Accounting\Accounts\Pages;

use App\Filament\Imports\BankAccountTransactionImporter;
use App\Filament\Imports\CreditCardTransactionImporter;
use App\Filament\Resources\Accounting\Accounts\AccountResource;
use App\Services\Accounting\InternalTransferDetectionService;
use App\Services\Accounting\RefundDetectionService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ImportAction;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Number;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),

            ImportAction::make('import_credit_card')
                ->label(__('Import Credit Card'))
                ->color('info')
                ->importer(CreditCardTransactionImporter::class)
                ->options(['accountId' => $this->record->id])
                ->modalDescription(__('Upload a CSV file with credit card transactions to import.')),

            ImportAction::make('import_bank_account')
                ->label(__('Import Bank Account'))
                ->color('success')
                ->importer(BankAccountTransactionImporter::class)
                ->options(['accountId' => $this->record->id])
                ->modalDescription(__('Upload a CSV file with bank account transactions to import.')),

            Action::make('detect_refunds')
                ->label(__('Detect Refunds'))
                ->color('warning')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->modalDescription(__('This will scan transactions and automatically detect refund pairs.'))
                ->action(function () {
                    $service = app(RefundDetectionService::class);
                    $results = $service->detectRefunds($this->record->id);

                    Notification::make()
                        ->title(__('Refund Detection Complete'))
                        ->body(__(
                            'Found :pairs refund pairs (:transactions transactions marked)',
                            [
                                'pairs' => $results['refund_pairs_found'],
                                'transactions' => $results['transactions_marked'],
                            ]
                        ))
                        ->success()
                        ->send();
                }),

            Action::make('detect_transfers')
                ->label(__('Detect Transfers'))
                ->color('info')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->requiresConfirmation()
                ->modalDescription(__('This will scan transactions and automatically detect internal transfers.'))
                ->action(function () {
                    $service = app(InternalTransferDetectionService::class);
                    $results = $service->detectTransfers($this->record->id);

                    Notification::make()
                        ->title(__('Transfer Detection Complete'))
                        ->body(__(
                            'Found :pairs transfer pairs, :single single transfers (:total total marked)',
                            [
                                'pairs' => $results['transfer_pairs_found'],
                                'single' => $results['single_transfers_marked'],
                                'total' => $results['total_transactions_marked'],
                            ]
                        ))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Schemas\Components\Section::make(__('Account Information'))
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')
                            ->label(__('Name'))
                            ->weight(FontWeight::Bold)
                            ->size('lg'),

                        Infolists\Components\TextEntry::make('type')
                            ->label(__('Type'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('balance')
                            ->label(__('Current Balance'))
                            ->money(fn ($record) => $record->currency_code)
                            ->weight(FontWeight::Bold)
                            ->color(fn ($record) => $record->balance->isPositive() ? 'success' : 'danger')
                            ->size('lg'),

                        Infolists\Components\TextEntry::make('currency.name')
                            ->label(__('Currency')),

                        Infolists\Components\TextEntry::make('description')
                            ->label(__('Description'))
                            ->columnSpanFull()
                            ->placeholder(__('No description'))
                            ->visible(fn ($record) => $record->description),
                    ]),

                Schemas\Components\Section::make(__('Transaction Statistics'))
                    ->columns(4)
                    ->schema([
                        Infolists\Components\TextEntry::make('total_transactions')
                            ->label(__('Total Transactions'))
                            ->state(function ($record) {
                                return Number::format($record->transactions()->count());
                            })
                            ->icon('heroicon-o-document-text'),

                        Infolists\Components\TextEntry::make('total_income')
                            ->label(__('Total Income'))
                            ->state(function ($record) {
                                $total = $record->transactions()
                                    ->where('type', 'income')
                                    ->sum('amount');

                                return money($total, $record->currency_code)->format();
                            })
                            ->icon('heroicon-o-arrow-trending-up')
                            ->color('success'),

                        Infolists\Components\TextEntry::make('total_expenses')
                            ->label(__('Total Expenses'))
                            ->state(function ($record) {
                                $total = $record->transactions()
                                    ->where('type', 'expense')
                                    ->sum('amount');

                                return money($total, $record->currency_code)->format();
                            })
                            ->icon('heroicon-o-arrow-trending-down')
                            ->color('danger'),

                        Infolists\Components\TextEntry::make('net_flow')
                            ->label(__('Net Flow'))
                            ->state(function ($record) {
                                $income = $record->transactions()
                                    ->where('type', 'income')
                                    ->sum('amount');
                                $expenses = $record->transactions()
                                    ->where('type', 'expense')
                                    ->sum('amount');
                                $net = $income - $expenses;

                                return money($net, $record->currency_code)->format();
                            })
                            ->icon('heroicon-o-calculator')
                            ->color(function ($record) {
                                $income = $record->transactions()->where('type', 'income')->sum('amount');
                                $expenses = $record->transactions()->where('type', 'expense')->sum('amount');
                                $net = $income - $expenses;

                                return $net >= 0 ? 'success' : 'danger';
                            }),
                    ]),
            ]);
    }

    public function getRelationManagers(): array
    {
        return [
            \App\Filament\Resources\Accounting\Accounts\RelationManagers\TransactionsRelationManager::class,
        ];
    }
}
