<?php

namespace App\Filament\Resources\Order\Orders\Pages;

use App\Filament\Resources\Order\Orders\Infolists\OrderInfolist;
use App\Filament\Resources\Order\Orders\OrderResource;
use App\Filament\Resources\Order\Orders\RelationManagers\ReturnsRelationManager;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            \App\Filament\Resources\Order\Orders\RelationManagers\AddressesRelationManager::class,
            \App\Filament\Resources\Order\Orders\RelationManagers\ItemsRelationManager::class,
            ReturnsRelationManager::class,
        ];
    }
}
