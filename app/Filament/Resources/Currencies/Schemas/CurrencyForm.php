<?php

namespace App\Filament\Resources\Currencies\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas;
use Filament\Schemas\Schema;

class CurrencyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Schemas\Components\Section::make(__('Currency Information'))
                    ->schema([
                        TextInput::make('code')
                            ->label(__('Currency Code'))
                            ->required()
                            ->maxLength(3)
                            ->placeholder('USD')
                            ->helperText(__('ISO 4217 currency code'))
                            ->unique(ignorable: fn ($record) => $record),

                        TextInput::make('name')
                            ->label(__('Currency Name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder('US Dollar'),

                        TextInput::make('symbol')
                            ->label(__('Symbol'))
                            ->required()
                            ->maxLength(10)
                            ->placeholder('$'),

                        TextInput::make('decimal_places')
                            ->label(__('Decimal Places'))
                            ->required()
                            ->numeric()
                            ->default(2)
                            ->minValue(0)
                            ->maxValue(8)
                            ->helperText(__('Number of decimal places for this currency')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Schemas\Components\Section::make(__('Settings'))
                    ->schema([
                        Toggle::make('is_default')
                            ->label(__('Default Currency'))
                            ->helperText(__('Only one currency can be the default')),

                        Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true)
                            ->helperText(__('Only active currencies can be used in transactions')),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
