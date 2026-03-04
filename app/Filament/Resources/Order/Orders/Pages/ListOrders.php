<?php

namespace App\Filament\Resources\Order\Orders\Pages;

use App\Filament\Exports\OrderExporter;
use App\Filament\Resources\Order\Orders\OrderResource;
use Filament\Actions\CreateAction;
use Filament\Actions\ExportAction;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->exporter(OrderExporter::class)
                ->label(__('Export')),
            CreateAction::make(),
        ];
    }
}
