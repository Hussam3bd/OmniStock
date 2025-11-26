<?php

namespace App\Filament\Resources\Product\VariantOptions;

use App\Filament\Resources\Product\VariantOptions\Pages\CreateVariantOption;
use App\Filament\Resources\Product\VariantOptions\Pages\EditVariantOption;
use App\Filament\Resources\Product\VariantOptions\Pages\ListVariantOptions;
use App\Filament\Resources\Product\VariantOptions\Schemas\VariantOptionForm;
use App\Filament\Resources\Product\VariantOptions\Tables\VariantOptionsTable;
use App\Models\Product\VariantOption;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VariantOptionResource extends Resource
{
    protected static ?string $model = VariantOption::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function getModelLabel(): string
    {
        return __('Variant Option');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Variant Options');
    }

    public static function form(Schema $schema): Schema
    {
        return VariantOptionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VariantOptionsTable::configure($table);
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
            'index' => ListVariantOptions::route('/'),
            'create' => CreateVariantOption::route('/create'),
            'edit' => EditVariantOption::route('/{record}/edit'),
        ];
    }
}
