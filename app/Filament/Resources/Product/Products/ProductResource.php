<?php

namespace App\Filament\Resources\Product\Products;

use App\Filament\Resources\Product\Products\Pages\CreateProduct;
use App\Filament\Resources\Product\Products\Pages\EditProduct;
use App\Filament\Resources\Product\Products\Pages\ListProducts;
use App\Filament\Resources\Product\Products\Pages\ManageProductChannels;
use App\Filament\Resources\Product\Products\Pages\ManageProductMedia;
use App\Filament\Resources\Product\Products\Pages\ManageProductVariants;
use App\Filament\Resources\Product\Products\Schemas\ProductForm;
use App\Filament\Resources\Product\Products\Tables\ProductsTable;
use App\Models\Product\Product;
use BackedEnum;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?int $navigationSort = 2;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function getModelLabel(): string
    {
        return __('Product');
    }

    public static function getPluralModelLabel(): string
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

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            EditProduct::class,
            ManageProductVariants::class,
            ManageProductChannels::class,
            ManageProductMedia::class,
        ]);
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
            'variants' => ManageProductVariants::route('/{record}/variants'),
            'channels' => ManageProductChannels::route('/{record}/channels'),
            'media' => ManageProductMedia::route('/{record}/media'),
        ];
    }
}
