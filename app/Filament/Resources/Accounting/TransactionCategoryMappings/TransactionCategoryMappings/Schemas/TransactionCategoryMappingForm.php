<?php

namespace App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Schemas;

use App\Enums\Accounting\CapitalCategory;
use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Account;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class TransactionCategoryMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('pattern')
                    ->required()
                    ->maxLength(255)
                    ->label(__('Pattern'))
                    ->helperText(__('Text to match in transaction descriptions (e.g., "FACEBK", "TRENDYOL")')),

                Select::make('type')
                    ->required()
                    ->options(TransactionType::class)
                    ->label(__('Transaction Type'))
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('category', null)),

                Select::make('category')
                    ->required()
                    ->options(fn (Get $get) => static::getCategoryOptions($get('type')))
                    ->label(__('Category'))
                    ->helperText(__('Category to auto-assign when pattern matches')),

                Select::make('account_id')
                    ->label(__('Account'))
                    ->options(Account::pluck('name', 'id'))
                    ->searchable()
                    ->nullable()
                    ->helperText(__('Leave empty for global mapping, or select specific account')),

                TextInput::make('priority')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->label(__('Priority'))
                    ->helperText(__('Lower number = higher priority. First matching rule wins.')),

                Toggle::make('is_active')
                    ->label(__('Active'))
                    ->default(true),
            ]);
    }

    protected static function getCategoryOptions(?string $type): array
    {
        if (! $type) {
            return [];
        }

        return match ($type) {
            TransactionType::INCOME->value => collect(IncomeCategory::cases())
                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                ->toArray(),
            TransactionType::EXPENSE->value => collect(ExpenseCategory::cases())
                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                ->toArray(),
            TransactionType::CAPITAL->value => collect(CapitalCategory::cases())
                ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()])
                ->toArray(),
            default => [],
        };
    }
}
