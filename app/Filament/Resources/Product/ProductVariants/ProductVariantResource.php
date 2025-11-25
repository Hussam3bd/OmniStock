<?php

namespace App\Filament\Resources\Product\ProductVariants;

use App\Filament\Resources\Product\ProductVariants\Pages\CreateProductVariant;
use App\Filament\Resources\Product\ProductVariants\Pages\EditProductVariant;
use App\Filament\Resources\Product\ProductVariants\Pages\ListProductVariants;
use App\Filament\Resources\Product\ProductVariants\Schemas\ProductVariantForm;
use App\Filament\Resources\Product\ProductVariants\Tables\ProductVariantsTable;
use App\Models\Product\ProductVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductVariantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductVariantsTable::configure($table);
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
            'index' => ListProductVariants::route('/'),
            'create' => CreateProductVariant::route('/create'),
            'edit' => EditProductVariant::route('/{record}/edit'),
        ];
    }
}
