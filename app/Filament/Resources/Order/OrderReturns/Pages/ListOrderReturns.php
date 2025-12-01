<?php

namespace App\Filament\Resources\Order\OrderReturns\Pages;

use App\Filament\Resources\Order\OrderReturns\OrderReturnResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderReturns extends ListRecords
{
    protected static string $resource = OrderReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Returns can only be created through integrations (Trendyol, Shopify, etc.)
        ];
    }
}
