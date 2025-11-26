<?php

namespace App\Filament\Resources\Product\VariantOptions\Pages;

use App\Filament\Resources\Product\VariantOptions\VariantOptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVariantOptions extends ListRecords
{
    protected static string $resource = VariantOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
