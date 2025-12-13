<?php

namespace App\Filament\Resources\Accounting\Expenses\Schemas;

use App\Enums\Accounting\ExpenseCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('category')
                    ->label(__('Expense Category'))
                    ->options(ExpenseCategory::class)
                    ->required()
                    ->searchable()
                    ->helperText(__('What type of expense is this?'))
                    ->columnSpan(2),

                Select::make('account_id')
                    ->relationship('account', 'name')
                    ->label(__('Paid From'))
                    ->preload()
                    ->required()
                    ->searchable()
                    ->live()
                    ->helperText(__('Which account did you pay from?'))
                    ->columnSpan(1),

                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->prefix(function (Get $get) {
                        if (! $get('account_id')) {
                            return '₺';
                        }
                        $account = \App\Models\Accounting\Account::find($get('account_id'));

                        return $account?->currency?->symbol ?? '₺';
                    })
                    ->helperText(__('Enter the expense amount'))
                    ->columnSpan(2),

                DatePicker::make('transaction_date')
                    ->label(__('Date'))
                    ->required()
                    ->default(now())
                    ->native(false)
                    ->maxDate(now())
                    ->helperText(__('When did this expense occur?'))
                    ->columnSpan(1),

                Textarea::make('description')
                    ->label(__('Description / Notes'))
                    ->placeholder(__('e.g., Meta Ads Campaign - December 2025, Photography session for winter collection'))
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText(__('Add any notes or details about this expense')),
            ])
            ->columns(2);
    }
}
