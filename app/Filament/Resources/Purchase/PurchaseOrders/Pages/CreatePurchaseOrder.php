<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Pages;

use App\Filament\Resources\Purchase\PurchaseOrders\PurchaseOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;
}
