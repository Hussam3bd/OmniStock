<?php

namespace App\Filament\Resources\ExchangeRates;

use App\Filament\Resources\ExchangeRates\Pages\ListExchangeRates;
use App\Filament\Resources\ExchangeRates\Schemas\ExchangeRateForm;
use App\Filament\Resources\ExchangeRates\Tables\ExchangeRatesTable;
use App\Models\ExchangeRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ExchangeRateResource extends Resource
{
    protected static ?string $model = ExchangeRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Settings');
    }

    public static function getModelLabel(): string
    {
        return __('Exchange Rate');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Exchange Rates');
    }

    public static function canCreate(): bool
    {
        return false; // Rates are auto-updated from API
    }

    public static function form(Schema $schema): Schema
    {
        return ExchangeRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExchangeRatesTable::configure($table);
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
            'index' => ListExchangeRates::route('/'),
        ];
    }
}
