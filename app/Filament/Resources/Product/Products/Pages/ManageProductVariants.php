<?php

namespace App\Filament\Resources\Product\Products\Pages;

use App\Filament\Resources\Product\Products\ProductResource;
use App\Filament\Resources\Product\Products\Tables\ProductVariantsTable;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ManageProductVariants extends ManageRelatedRecords
{
    protected static string $resource = ProductResource::class;

    protected static string $relationship = 'variants';

    public static function getNavigationLabel(): string
    {
        return __('Variants');
    }

    public function table(Table $table): Table
    {
        return ProductVariantsTable::configure($table, $this)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['optionValues.variantOption', 'channelAvailability']));
    }
}
