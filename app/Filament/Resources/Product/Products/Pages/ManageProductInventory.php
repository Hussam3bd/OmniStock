<?php

namespace App\Filament\Resources\Product\Products\Pages;

use App\Filament\Resources\Product\Products\ProductResource;
use App\Filament\Resources\Product\Products\Tables\ProductInventoryTable;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;

class ManageProductInventory extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'variants';

    public static function getNavigationLabel(): string
    {
        return __('Inventory');
    }

    public function table(Table $table): Table
    {
        return ProductInventoryTable::configure($table);
    }
}
