<?php

namespace App\Filament\Resources\Order\Orders\RelationManagers;

use App\Actions\Invoice\CancelInvoiceAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('invoice_number')
            ->columns([
                TextColumn::make('invoice_number')
                    ->label(__('Invoice #'))
                    ->searchable()
                    ->copyable()
                    ->description(fn ($record) => __('Type: :type', ['type' => $record->invoice_type->getLabel()])),

                TextColumn::make('integration.name')
                    ->label(__('Provider'))
                    ->badge()
                    ->color('success'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'draft' => 'gray',
                        'pending' => 'warning',
                        'issued' => 'success',
                        'cancelled' => 'danger',
                        'failed' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('external_id')
                    ->label(__('External ID'))
                    ->copyable()
                    ->toggleable()
                    ->placeholder(__('N/A')),

                TextColumn::make('issued_at')
                    ->label(__('Issued'))
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->placeholder(__('Not issued'))
                    ->description(fn ($record) => $record->issued_at?->format('M d, Y')),

                IconColumn::make('sent_to_customer')
                    ->label(__('Sent'))
                    ->boolean(),

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
            ])
            ->actions([
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

                Action::make('view_pdf')
                    ->label(__('View PDF'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('info')
                    ->url(fn ($record) => $record->pdf_url, shouldOpenInNewTab: true)
                    ->visible(fn ($record) => ! empty($record->pdf_url)),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
