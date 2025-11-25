<?php

namespace App\Filament\Resources\Accounting\Transactions;

use App\Filament\Resources\Accounting\Transactions\Pages\CreateTransaction;
use App\Filament\Resources\Accounting\Transactions\Pages\EditTransaction;
use App\Filament\Resources\Accounting\Transactions\Pages\ListTransactions;
use App\Filament\Resources\Accounting\Transactions\Schemas\TransactionForm;
use App\Filament\Resources\Accounting\Transactions\Tables\TransactionsTable;
use App\Models\Accounting\Transaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Accounting');
    }

    public static function form(Schema $schema): Schema
    {
        return TransactionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TransactionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransactions::route('/'),
            'create' => CreateTransaction::route('/create'),
            'edit' => EditTransaction::route('/{record}/edit'),
        ];
    }
}
