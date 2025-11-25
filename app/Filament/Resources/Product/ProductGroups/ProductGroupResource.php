<?php

namespace App\Filament\Resources\Product\ProductGroups;

use App\Filament\Resources\Product\ProductGroups\Pages\CreateProductGroup;
use App\Filament\Resources\Product\ProductGroups\Pages\EditProductGroup;
use App\Filament\Resources\Product\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\Product\ProductGroups\Schemas\ProductGroupForm;
use App\Filament\Resources\Product\ProductGroups\Tables\ProductGroupsTable;
use App\Models\Product\ProductGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProductGroupResource extends Resource
{
    protected static ?string $model = ProductGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('Products');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductGroupsTable::configure($table);
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
            'index' => ListProductGroups::route('/'),
            'create' => CreateProductGroup::route('/create'),
            'edit' => EditProductGroup::route('/{record}/edit'),
        ];
    }
}
