<?php

namespace App\Filament\Resources\Accounting\Transactions\Schemas;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->relationship('account', 'name')
                    ->required()
                    ->columnSpan(1),

                Select::make('type')
                    ->options(TransactionType::class)
                    ->required()
                    ->live()
                    ->columnSpan(1),

                Select::make('category')
                    ->label(__('Category'))
                    ->options(function (Get $get) {
                        $type = $get('type');
                        if (! $type) {
                            return [];
                        }

                        return match ($type) {
                            TransactionType::INCOME->value, 'income' => IncomeCategory::class,
                            TransactionType::EXPENSE->value, 'expense' => ExpenseCategory::class,
                            default => [],
                        };
                    })
                    ->required()
                    ->visible(fn (Get $get) => in_array($get('type'), [
                        TransactionType::INCOME->value,
                        TransactionType::EXPENSE->value,
                        'income',
                        'expense',
                    ]))
                    ->columnSpan(1),

                TextInput::make('amount')
                    ->required()
                    ->numeric()
                    ->prefix(fn (Get $get) => Currency::find($get('currency_id'))?->symbol ?? 'TRY')
                    ->columnSpan(1),

                Select::make('currency_id')
                    ->relationship('account.currency', 'code')
                    ->label(__('Currency'))
                    ->required()
                    ->default(fn () => Currency::where('code', 'TRY')->first()?->id)
                    ->columnSpan(1),

                DatePicker::make('transaction_date')
                    ->label(__('Transaction Date'))
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->columnSpan(1),

                Select::make('order_id')
                    ->relationship('order', 'order_number')
                    ->label(__('Related Order'))
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get) => $get('type') === TransactionType::INCOME->value || $get('type') === 'income')
                    ->columnSpan(1),

                Select::make('purchase_order_id')
                    ->relationship('purchaseOrder', 'order_number')
                    ->label(__('Related Purchase Order'))
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get) => $get('type') === TransactionType::EXPENSE->value || $get('type') === 'expense')
                    ->columnSpan(1),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
