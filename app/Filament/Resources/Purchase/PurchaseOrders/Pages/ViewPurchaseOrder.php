<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Purchase\PurchaseOrders\Infolists\PurchaseOrderInfolist;
use App\Filament\Resources\Purchase\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    public function infolist(Schema $schema): Schema
    {
        return PurchaseOrderInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('mark_as_ordered')
                ->label(__('Mark as Ordered'))
                ->icon('heroicon-o-check-circle')
                ->color('warning')
                ->visible(fn () => $this->record->status === PurchaseOrderStatus::Draft)
                ->requiresConfirmation()
                ->modalHeading(__('Mark Order as Ordered'))
                ->modalDescription(__('This will mark the order as sent to the supplier and ready to receive.'))
                ->action(function () {
                    $this->record->status = PurchaseOrderStatus::Ordered;
                    $this->record->save();

                    Notification::make()
                        ->success()
                        ->title(__('Order marked as ordered'))
                        ->send();
                }),

            Actions\Action::make('receive_items')
                ->label(__('Receive Items'))
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [
                    PurchaseOrderStatus::Ordered,
                    PurchaseOrderStatus::PartiallyReceived,
                ]))
                ->url(fn () => PurchaseOrderResource::getUrl('receive', ['record' => $this->record])),

            Actions\EditAction::make(),
        ];
    }
}
