<?php

namespace App\Filament\Resources\Accounting\Expenses;

use App\Enums\Accounting\TransactionType;
use App\Filament\Resources\Accounting\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Accounting\Expenses\Pages\EditExpense;
use App\Filament\Resources\Accounting\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Accounting\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Accounting\Expenses\Schemas\ExpenseForm;
use App\Filament\Resources\Accounting\Expenses\Schemas\ExpenseInfolist;
use App\Filament\Resources\Accounting\Expenses\Tables\ExpensesTable;
use App\Models\Accounting\Transaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ExpenseResource extends Resource
{
    protected static ?string $model = Transaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Accounting');
    }

    public static function getNavigationLabel(): string
    {
        return __('Expenses');
    }

    public static function getModelLabel(): string
    {
        return __('Expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Expenses');
    }

    public static function getEloquentQuery(): Builder
    {
        // Only show expense transactions
        return parent::getEloquentQuery()
            ->where('type', TransactionType::EXPENSE);
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ExpenseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
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
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
