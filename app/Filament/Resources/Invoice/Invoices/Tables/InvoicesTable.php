<?php

namespace App\Filament\Resources\Invoice\Invoices\Tables;

use App\Actions\Invoice\CancelInvoiceAction;
use App\Models\Invoice\Invoice;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label(__('Invoice #'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->description(fn (Invoice $record, $state) => __('Type: :type', ['type' => $record->invoice_type->getLabel()])),

                TextColumn::make('order.order_number')
                    ->label(__('Order #'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->order ? route('filament.admin.resources.order.orders.view', $record->order) : null)
                    ->color('primary'),

                TextColumn::make('integration.name')
                    ->label(__('Provider'))
                    ->badge()
                    ->color('success')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('external_id')
                    ->label(__('External ID'))
                    ->copyable()
                    ->toggleable()
                    ->placeholder(__('N/A'))
                    ->searchable(),

                TextColumn::make('issued_at')
                    ->label(__('Issued'))
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->placeholder(__('Not issued'))
                    ->description(fn ($record) => $record->issued_at?->format('M d, Y')),

                IconColumn::make('sent_to_customer')
                    ->label(__('Sent'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cancelled_at')
                    ->label(__('Cancelled'))
                    ->dateTime()
                    ->since()
                    ->toggleable()
                    ->placeholder(__('N/A'))
                    ->description(fn ($record) => $record->cancel_reason),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'draft' => __('Draft'),
                        'pending' => __('Pending'),
                        'issued' => __('Issued'),
                        'cancelled' => __('Cancelled'),
                        'failed' => __('Failed'),
                    ])
                    ->multiple(),

                SelectFilter::make('integration')
                    ->relationship('integration', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('invoice_type')
                    ->options([
                        'e-archive' => __('E-Archive'),
                        'e-invoice' => __('E-Invoice'),
                    ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),

                Action::make('view_pdf')
                    ->label(__('View PDF'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->url(fn ($record) => $record->pdf_url, shouldOpenInNewTab: true)
                    ->visible(fn ($record) => ! empty($record->pdf_url)),

                Action::make('cancel')
                    ->label(__('Cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Cancel Invoice'))
                    ->modalDescription(__('Are you sure you want to cancel this invoice? This will also cancel it with the provider.'))
                    ->visible(fn ($record) => $record->canBeCancelled())
                    ->action(function ($record) {
                        try {
                            $action = new CancelInvoiceAction;
                            $action->execute($record, [
                                'reason' => 'Cancelled from admin panel',
                            ]);

                            Notification::make()
                                ->success()
                                ->title(__('Invoice Cancelled'))
                                ->body(__('Successfully cancelled invoice :number', ['number' => $record->invoice_number]))
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title(__('Cancellation Failed'))
                                ->body(__('Failed to cancel invoice: :error', ['error' => $e->getMessage()]))
                                ->send();
                        }
                    }),
            ]);
    }
}
