<?php

namespace App\Filament\Resources\Order\Orders\Pages;

use App\Filament\Resources\Order\Orders\OrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;
}
