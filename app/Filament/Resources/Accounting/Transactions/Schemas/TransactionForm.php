<?php

namespace App\Filament\Resources\Accounting\Transactions\Schemas;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Forms\Components\MoneyInput;
use App\Models\Currency;
use App\Models\Order\Order;
use App\Models\Purchase\PurchaseOrder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
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

                        // Handle both enum objects and string values
                        $typeValue = $type instanceof TransactionType ? $type->value : $type;

                        return match ($typeValue) {
                            TransactionType::INCOME->value, 'income' => IncomeCategory::class,
                            TransactionType::EXPENSE->value, 'expense' => ExpenseCategory::class,
                            default => [],
                        };
                    })
                    ->required(function (Get $get) {
                        $type = $get('type');
                        $typeValue = $type instanceof TransactionType ? $type->value : $type;

                        return in_array($typeValue, [
                            TransactionType::INCOME->value,
                            TransactionType::EXPENSE->value,
                            'income',
                            'expense',
                        ]);
                    })
                    ->visible(function (Get $get) {
                        $type = $get('type');
                        $typeValue = $type instanceof TransactionType ? $type->value : $type;

                        return in_array($typeValue, [
                            TransactionType::INCOME->value,
                            TransactionType::EXPENSE->value,
                            'income',
                            'expense',
                        ]);
                    })
                    ->columnSpan(1),

                MoneyInput::make('amount')
                    ->required()
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

                MorphToSelect::make('transactionable')
                    ->label(__('Related To'))
                    ->types([
                        MorphToSelect\Type::make(Order::class)
                            ->titleAttribute('order_number')
                            ->label(__('Order')),
                        MorphToSelect\Type::make(PurchaseOrder::class)
                            ->titleAttribute('order_number')
                            ->label(__('Purchase Order')),
                    ])
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }
}
