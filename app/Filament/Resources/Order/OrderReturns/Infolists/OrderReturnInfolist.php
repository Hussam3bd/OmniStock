<?php

namespace App\Filament\Resources\Order\OrderReturns\Infolists;

use Filament\Infolists;
use Filament\Schemas;
use Filament\Schemas\Schema;

class OrderReturnInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Schemas\Components\Section::make(__('Return Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('return_number')
                            ->label(__('Return Number'))
                            ->copyable(),

                        Infolists\Components\TextEntry::make('order.order_number')
                            ->label(__('Order Number'))
                            ->url(fn ($record) => route('filament.admin.resources.order.orders.view', ['record' => $record->order]))
                            ->copyable(),

                        Infolists\Components\TextEntry::make('channel')
                            ->label(__('Channel'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('requested_at')
                            ->label(__('Requested Date'))
                            ->dateTime(),

                        Infolists\Components\TextEntry::make('approved_at')
                            ->label(__('Approved Date'))
                            ->dateTime()
                            ->placeholder(__('Not approved yet')),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Customer & Reason'))
                    ->schema([
                        Infolists\Components\TextEntry::make('order.customer.full_name')
                            ->label(__('Customer')),

                        Infolists\Components\TextEntry::make('reason_name')
                            ->label(__('Return Reason')),

                        Infolists\Components\TextEntry::make('customer_note')
                            ->label(__('Customer Note'))
                            ->placeholder(__('No note'))
                            ->columnSpanFull()
                            ->prose(),

                        Infolists\Components\TextEntry::make('internal_note')
                            ->label(__('Internal Note'))
                            ->placeholder(__('No note'))
                            ->columnSpanFull()
                            ->prose()
                            ->visible(fn ($record) => $record->internal_note),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Financial Summary'))
                    ->schema([
                        Infolists\Components\TextEntry::make('original_shipping_cost')
                            ->label(__('Original Shipping Cost'))
                            ->money(fn ($record) => $record->currency)
                            ->color('warning'),

                        Infolists\Components\TextEntry::make('return_shipping_cost_excluding_vat')
                            ->label(__('Return Shipping (excl. VAT)'))
                            ->money(fn ($record) => $record->currency)
                            ->color('warning')
                            ->visible(fn ($record) => $record->return_shipping_cost_excluding_vat),

                        Infolists\Components\TextEntry::make('return_shipping_vat_amount')
                            ->label(__('Return Shipping VAT'))
                            ->money(fn ($record) => $record->currency)
                            ->color('warning')
                            ->visible(fn ($record) => $record->return_shipping_vat_amount),

                        Infolists\Components\TextEntry::make('return_shipping_total')
                            ->label(__('Return Shipping Total'))
                            ->state(fn ($record) => $record->return_shipping_total)
                            ->money(fn ($record) => $record->currency)
                            ->color('warning'),

                        Infolists\Components\TextEntry::make('total_refund_amount')
                            ->label(__('Total Refund'))
                            ->money(fn ($record) => $record->currency)
                            ->color('danger'),

                        Infolists\Components\TextEntry::make('restocking_fee')
                            ->label(__('Restocking Fee'))
                            ->money(fn ($record) => $record->currency)
                            ->visible(fn ($record) => $record->restocking_fee->getAmount() > 0),

                        Infolists\Components\TextEntry::make('total_loss')
                            ->label(__('Total Loss'))
                            ->state(fn ($record) => $record->getTotalLoss())
                            ->money(fn ($record) => $record->currency)
                            ->weight('bold')
                            ->size('lg')
                            ->color('danger')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Return Items'))
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('orderItem.productVariant.sku')
                                    ->label(__('SKU')),

                                Infolists\Components\TextEntry::make('orderItem.productVariant.product.name')
                                    ->label(__('Product'))
                                    ->columnSpan(2),

                                Infolists\Components\TextEntry::make('quantity')
                                    ->label(__('Quantity'))
                                    ->badge(),

                                Infolists\Components\TextEntry::make('reason_name')
                                    ->label(__('Reason'))
                                    ->columnSpan(2),

                                Infolists\Components\TextEntry::make('received_condition')
                                    ->label(__('Condition'))
                                    ->badge()
                                    ->color(fn ($state) => match ($state) {
                                        'good' => 'success',
                                        'damaged', 'defective' => 'danger',
                                        default => 'gray',
                                    })
                                    ->visible(fn ($record) => $record->received_condition),

                                Infolists\Components\TextEntry::make('refund_amount')
                                    ->label(__('Refund'))
                                    ->money(fn ($record) => $record->return->currency),

                                Infolists\Components\TextEntry::make('inspection_note')
                                    ->label(__('Inspection Note'))
                                    ->placeholder(__('No inspection note'))
                                    ->columnSpanFull()
                                    ->visible(fn ($record) => $record->inspection_note),
                            ])
                            ->columns(4)
                            ->contained(false),
                    ]),

                Schemas\Components\Section::make(__('Return Shipping'))
                    ->schema([
                        Infolists\Components\TextEntry::make('return_shipping_carrier')
                            ->label(__('Carrier'))
                            ->badge()
                            ->icon('heroicon-o-truck')
                            ->placeholder(__('Not detected')),

                        Infolists\Components\TextEntry::make('return_shipping_desi')
                            ->label(__('Desi (Volumetric Weight)'))
                            ->suffix(' desi')
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->return_shipping_desi),

                        Infolists\Components\TextEntry::make('return_shipping_vat_rate')
                            ->label(__('VAT Rate'))
                            ->suffix('%')
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->return_shipping_vat_rate),

                        Infolists\Components\TextEntry::make('return_tracking_number')
                            ->label(__('Tracking Number'))
                            ->copyable()
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('return_tracking_url')
                            ->label(__('Tracking Link'))
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab()
                            ->placeholder(__('Not available'))
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('return_label_url')
                            ->label(__('Return Label'))
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab()
                            ->placeholder(__('Not generated'))
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('shipped_at')
                            ->label(__('Shipped Date'))
                            ->dateTime()
                            ->placeholder(__('Not shipped yet')),

                        Infolists\Components\TextEntry::make('received_at')
                            ->label(__('Received Date'))
                            ->dateTime()
                            ->placeholder(__('Not received yet')),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->visible(fn ($record) => $record->return_tracking_number || $record->return_label_url || $record->return_shipping_carrier),

                Schemas\Components\Section::make(__('Audit Trail'))
                    ->schema([
                        Infolists\Components\TextEntry::make('approvedBy.name')
                            ->label(__('Approved By'))
                            ->placeholder(__('Not approved'))
                            ->icon('heroicon-o-user'),

                        Infolists\Components\TextEntry::make('approved_at')
                            ->label(__('Approved At'))
                            ->dateTime()
                            ->placeholder(__('Not approved')),

                        Infolists\Components\TextEntry::make('rejectedBy.name')
                            ->label(__('Rejected By'))
                            ->placeholder(__('Not rejected'))
                            ->icon('heroicon-o-user')
                            ->visible(fn ($record) => $record->rejected_by),

                        Infolists\Components\TextEntry::make('rejected_at')
                            ->label(__('Rejected At'))
                            ->dateTime()
                            ->placeholder(__('Not rejected'))
                            ->visible(fn ($record) => $record->rejected_at),

                        Infolists\Components\TextEntry::make('inspectedBy.name')
                            ->label(__('Inspected By'))
                            ->placeholder(__('Not inspected'))
                            ->icon('heroicon-o-user')
                            ->visible(fn ($record) => $record->inspected_by),

                        Infolists\Components\TextEntry::make('inspected_at')
                            ->label(__('Inspected At'))
                            ->dateTime()
                            ->placeholder(__('Not inspected'))
                            ->visible(fn ($record) => $record->inspected_at),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(),

                Schemas\Components\Section::make(__('Customer Photos'))
                    ->schema([
                        \Filament\Infolists\Components\SpatieMediaLibraryImageEntry::make('customer_photos')
                            ->label('')
                            ->collection('customer_photos')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->getMedia('customer_photos')->count() > 0)
                    ->collapsible(),

                Schemas\Components\Section::make(__('Inspection Photos'))
                    ->schema([
                        \Filament\Infolists\Components\SpatieMediaLibraryImageEntry::make('inspection_photos')
                            ->label('')
                            ->collection('inspection_photos')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record->getMedia('inspection_photos')->count() > 0)
                    ->collapsible(),
            ]);
    }
}
