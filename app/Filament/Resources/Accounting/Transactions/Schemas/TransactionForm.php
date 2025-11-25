<?php

namespace App\Filament\Resources\Accounting\Transactions\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('account_id')
                    ->relationship('account', 'name')
                    ->required(),
                Select::make('order_id')
                    ->relationship('order', 'id'),
                TextInput::make('type')
                    ->required(),
                TextInput::make('category'),
                TextInput::make('amount')
                    ->required()
                    ->numeric(),
                TextInput::make('currency')
                    ->required()
                    ->default('TRY'),
                Textarea::make('description')
                    ->columnSpanFull(),
                DatePicker::make('transaction_date')
                    ->required(),
            ]);
    }
}
