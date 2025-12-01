<?php

namespace App\Filament\Resources\Order\OrderReturns\Pages;

use App\Filament\Resources\Order\OrderReturns\OrderReturnResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOrderReturn extends EditRecord
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
