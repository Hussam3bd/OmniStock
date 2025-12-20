<?php

namespace App\Filament\Resources\Accounting\Accounts;

use App\Filament\Resources\Accounting\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounting\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounting\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounting\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounting\Accounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\Accounting\Accounts\Schemas\AccountForm;
use App\Filament\Resources\Accounting\Accounts\Tables\AccountsTable;
use App\Models\Accounting\Account;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('Accounting');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'view' => ViewAccount::route('/{record}'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }
}
