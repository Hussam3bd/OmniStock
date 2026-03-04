<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Pages;

use App\Filament\Exports\PurchaseOrderExporter;
use App\Filament\Resources\Purchase\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->exporter(PurchaseOrderExporter::class)
                ->label(__('Export')),
            CreateAction::make(),
        ];
    }
}
