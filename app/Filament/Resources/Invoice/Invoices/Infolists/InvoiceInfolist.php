<?php

namespace App\Filament\Resources\Invoice\Invoices\Infolists;

use Filament\Infolists;
use Filament\Schemas;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Schemas\Components\Section::make(__('Invoice Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('invoice_number')
                            ->label(__('Invoice Number'))
                            ->copyable()
                            ->size(Infolists\Components\TextEntry\TextEntrySize::Large)
                            ->weight('bold'),

                        Infolists\Components\TextEntry::make('invoice_type')
                            ->label(__('Invoice Type'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('external_id')
                            ->label(__('External ID'))
                            ->copyable()
                            ->placeholder(__('N/A')),

                        Infolists\Components\TextEntry::make('invoice_uuid')
                            ->label(__('Invoice UUID'))
                            ->copyable()
                            ->placeholder(__('N/A'))
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('issued_at')
                            ->label(__('Issued At'))
                            ->dateTime()
                            ->placeholder(__('Not issued'))
                            ->icon('heroicon-o-calendar'),

                        Infolists\Components\TextEntry::make('created_at')
                            ->label(__('Created At'))
                            ->dateTime()
                            ->icon('heroicon-o-clock'),

                        Infolists\Components\TextEntry::make('sent_to_customer')
                            ->label(__('Sent to Customer'))
                            ->formatStateUsing(fn ($state) => $state ? __('Yes') : __('No'))
                            ->badge()
                            ->color(fn ($state) => $state ? 'success' : 'gray'),

                        Infolists\Components\TextEntry::make('sent_at')
                            ->label(__('Sent At'))
                            ->dateTime()
                            ->placeholder(__('Not sent'))
                            ->visible(fn ($record) => $record->sent_to_customer),

                        Infolists\Components\TextEntry::make('send_methods')
                            ->label(__('Send Methods'))
                            ->badge()
                            ->placeholder(__('N/A'))
                            ->visible(fn ($record) => $record->sent_to_customer && $record->send_methods),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Related Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('order.order_number')
                            ->label(__('Order Number'))
                            ->url(fn ($record) => $record->order ? route('filament.admin.resources.orders.view', $record->order) : null)
                            ->color('primary')
                            ->icon('heroicon-o-shopping-bag'),

                        Infolists\Components\TextEntry::make('integration.name')
                            ->label(__('Invoice Provider'))
                            ->badge()
                            ->color('success')
                            ->icon('heroicon-o-building-office-2'),

                        Infolists\Components\TextEntry::make('order.customer.full_name')
                            ->label(__('Customer'))
                            ->icon('heroicon-o-user')
                            ->placeholder(__('N/A')),

                        Infolists\Components\TextEntry::make('order.total_amount')
                            ->label(__('Order Amount'))
                            ->money(fn ($record) => $record->order?->currency ?? 'TRY')
                            ->placeholder(__('N/A')),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Cancellation Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('cancelled_at')
                            ->label(__('Cancelled At'))
                            ->dateTime()
                            ->icon('heroicon-o-x-circle')
                            ->color('danger'),

                        Infolists\Components\TextEntry::make('cancel_reason')
                            ->label(__('Cancel Reason'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => $record->status->value === 'cancelled'),

                Schemas\Components\Section::make(__('Error Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('error_message')
                            ->label(__('Error Message'))
                            ->columnSpanFull()
                            ->color('danger'),
                    ])
                    ->visible(fn ($record) => $record->status->value === 'failed' && $record->error_message),

                Schemas\Components\Section::make(__('Invoice Data'))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('invoice_data')
                            ->label(__('Invoice Data'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn ($record) => ! empty($record->invoice_data)),

                Schemas\Components\Section::make(__('Provider Response'))
                    ->schema([
                        Infolists\Components\KeyValueEntry::make('provider_response')
                            ->label(__('Provider Response'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed()
                    ->visible(fn ($record) => ! empty($record->provider_response)),
            ]);
    }
}
