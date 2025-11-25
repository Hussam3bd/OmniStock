<?php

namespace App\Filament\Resources\Product\ProductGroups\Pages;

use App\Filament\Resources\Product\ProductGroups\ProductGroupResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductGroup extends CreateRecord
{
    protected static string $resource = ProductGroupResource::class;
}
