<?php

namespace App\Filament\Resources\Order\Orders\Pages;

use App\Filament\Actions\Order\GenerateInvoiceAction;
use App\Filament\Actions\Order\ResyncOrderAction;
use App\Filament\Actions\Order\ResyncPaymentCostAction;
use App\Filament\Actions\Order\ResyncShippingCostAction;
use App\Filament\Resources\Order\Orders\Infolists\OrderInfolist;
use App\Filament\Resources\Order\Orders\OrderResource;
use App\Filament\Resources\Order\Orders\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\Order\Orders\RelationManagers\InvoicesRelationManager;
use App\Filament\Resources\Order\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Order\Orders\RelationManagers\ReturnsRelationManager;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        // Eager load items for effective_product_cost calculation
        $this->record->load('items');
    }

    public function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn () => ! $this->record->isExternal()),
            GenerateInvoiceAction::make(),
            ResyncOrderAction::make(),
            ResyncShippingCostAction::make(),
            ResyncPaymentCostAction::make(),
        ];
    }

    public function getRelationManagers(): array
    {
        return [
            AddressesRelationManager::class,
            ItemsRelationManager::class,
            InvoicesRelationManager::class,
            ReturnsRelationManager::class,
        ];
    }
}
