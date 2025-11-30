<?php

namespace App\Filament\Resources\ExchangeRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ExchangeRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('from_currency_id')
                    ->relationship('fromCurrency', 'name')
                    ->required(),
                Select::make('to_currency_id')
                    ->relationship('toCurrency', 'name')
                    ->required(),
                TextInput::make('rate')
                    ->required()
                    ->numeric(),
                DatePicker::make('effective_date')
                    ->required(),
            ]);
    }
}
