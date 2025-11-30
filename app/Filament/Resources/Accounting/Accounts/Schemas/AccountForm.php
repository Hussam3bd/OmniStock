<?php

namespace App\Filament\Resources\Accounting\Accounts\Schemas;

use App\Enums\Accounting\AccountType;
use App\Models\Currency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('Name'))
                    ->required()
                    ->maxLength(255),

                Select::make('type')
                    ->label(__('Type'))
                    ->options(AccountType::class)
                    ->required()
                    ->native(false),

                Select::make('currency_id')
                    ->label(__('Currency'))
                    ->relationship('currency', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn () => Currency::where('code', config('money.defaultCurrency'))->first()?->id),

                TextInput::make('balance')
                    ->label(__('Initial Balance'))
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->prefix(fn ($get) => Currency::find($get('currency_id'))?->code ?? 'TRY'),

                Textarea::make('description')
                    ->label(__('Description'))
                    ->columnSpanFull(),
            ]);
    }
}
