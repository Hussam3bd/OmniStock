<?php

namespace App\Filament\Resources\Product\ProductVariants\Pages;

use App\Filament\Resources\Product\ProductVariants\ProductVariantResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProductVariant extends CreateRecord
{
    protected static string $resource = ProductVariantResource::class;
}
