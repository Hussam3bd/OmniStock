<?php

namespace App\Filament\Resources\Product\VariantOptions\Pages;

use App\Filament\Resources\Product\VariantOptions\VariantOptionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditVariantOption extends EditRecord
{
    protected static string $resource = VariantOptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
