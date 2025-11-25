<?php

namespace App\Filament\Resources\Product\Products;

use App\Filament\Resources\Product\Products\Pages\CreateProduct;
use App\Filament\Resources\Product\Products\Pages\EditProduct;
use App\Filament\Resources\Product\Products\Pages\ListProducts;
use App\Filament\Resources\Product\Products\Schemas\ProductForm;
use App\Filament\Resources\Product\Products\Tables\ProductsTable;
use App\Models\Product\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
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
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
