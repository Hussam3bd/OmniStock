<?php

namespace App\Filament\Resources\Order\OrderReturns\Pages;

use App\Filament\Resources\Order\OrderReturns\OrderReturnResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrderReturn extends CreateRecord
{
    protected static string $resource = OrderReturnResource::class;
}
