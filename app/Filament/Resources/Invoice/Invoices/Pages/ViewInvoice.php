<?php

namespace App\Filament\Resources\Invoice\Invoices\Pages;

use App\Actions\Invoice\CancelInvoiceAction;
use App\Filament\Resources\Invoice\Invoices\Infolists\InvoiceInfolist;
use App\Filament\Resources\Invoice\Invoices\InvoiceResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    public function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_pdf')
                ->label(__('View PDF'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('info')
                ->url(fn () => $this->record->pdf_url, shouldOpenInNewTab: true)
                ->visible(fn () => ! empty($this->record->pdf_url)),

            Action::make('view_html')
                ->label(__('View HTML'))
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->url(fn () => $this->record->html_url, shouldOpenInNewTab: true)
                ->visible(fn () => ! empty($this->record->html_url)),

            Action::make('view_order')
                ->label(__('View Order'))
                ->icon('heroicon-o-shopping-bag')
                ->color('primary')
                ->url(fn () => $this->record->order
                    ? route('filament.admin.resources.orders.view', $this->record->order)
                    : null)
                ->visible(fn () => $this->record->order),

            Action::make('cancel')
                ->label(__('Cancel Invoice'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Cancel Invoice'))
                ->modalDescription(__('Are you sure you want to cancel this invoice? This will also cancel it with the provider.'))
                ->visible(fn () => $this->record->canBeCancelled())
                ->action(function () {
                    try {
                        $action = new CancelInvoiceAction;
                        $action->execute($this->record, [
                            'reason' => 'Cancelled from admin panel',
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('Invoice Cancelled'))
                            ->body(__('Successfully cancelled invoice :number', ['number' => $this->record->invoice_number]))
                            ->send();

                        // Refresh the page to show updated status
                        $this->redirect($this->getResource()::getUrl('view', ['record' => $this->record]));
                    } catch (\Exception $e) {
                        Notification::make()
                            ->danger()
                            ->title(__('Cancellation Failed'))
                            ->body(__('Failed to cancel invoice: :error', ['error' => $e->getMessage()]))
                            ->send();
                    }
                }),
        ];
    }
}
