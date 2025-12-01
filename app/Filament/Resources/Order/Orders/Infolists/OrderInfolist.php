<?php

namespace App\Filament\Resources\Order\Orders\Infolists;

use Filament\Infolists;
use Filament\Schemas;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Schemas\Components\Section::make(__('Order Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('order_number')
                            ->label(__('Order Number'))
                            ->copyable(),

                        Infolists\Components\TextEntry::make('customer.full_name')
                            ->label(__('Customer'))
                            ->icon('heroicon-o-user'),

                        Infolists\Components\TextEntry::make('channel')
                            ->label(__('Channel'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('order_status')
                            ->label(__('Order Status'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('payment_status')
                            ->label(__('Payment Status'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('fulfillment_status')
                            ->label(__('Fulfillment Status'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('order_date')
                            ->label(__('Order Date'))
                            ->dateTime(),

                        Infolists\Components\TextEntry::make('currency')
                            ->label(__('Currency'))
                            ->badge()
                            ->color('gray'),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Order Summary'))
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal')
                            ->label(__('Subtotal'))
                            ->money(fn ($record) => $record->currency),

                        Infolists\Components\TextEntry::make('tax_amount')
                            ->label(__('Tax'))
                            ->money(fn ($record) => $record->currency),

                        Infolists\Components\TextEntry::make('shipping_amount')
                            ->label(__('Shipping'))
                            ->money(fn ($record) => $record->currency),

                        Infolists\Components\TextEntry::make('discount_amount')
                            ->label(__('Discount'))
                            ->money(fn ($record) => $record->currency)
                            ->color('danger'),

                        Infolists\Components\TextEntry::make('total_commission')
                            ->label(__('Total Commission'))
                            ->money(fn ($record) => $record->currency)
                            ->color('warning')
                            ->visible(fn ($record) => $record->total_commission->getAmount() > 0),

                        Infolists\Components\TextEntry::make('total_amount')
                            ->label(__('Total'))
                            ->money(fn ($record) => $record->currency)
                            ->weight('bold')
                            ->size('lg')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Order Items'))
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('productVariant.sku')
                                    ->label(__('SKU')),

                                Infolists\Components\TextEntry::make('productVariant.product.name')
                                    ->label(__('Product'))
                                    ->columnSpan(2),

                                Infolists\Components\TextEntry::make('productVariant.option_values')
                                    ->label(__('Variant'))
                                    ->placeholder(__('Default'))
                                    ->listWithLineBreaks()
                                    ->limitList(2),

                                Infolists\Components\TextEntry::make('quantity')
                                    ->label(__('Quantity'))
                                    ->badge()
                                    ->color('gray'),

                                Infolists\Components\TextEntry::make('unit_price')
                                    ->label(__('Unit Price'))
                                    ->money(fn ($record) => $record->order->currency),

                                Infolists\Components\TextEntry::make('discount_amount')
                                    ->label(__('Discount'))
                                    ->money(fn ($record) => $record->order->currency)
                                    ->color('danger')
                                    ->visible(fn ($record) => $record->discount_amount->getAmount() > 0),

                                Infolists\Components\TextEntry::make('tax_amount')
                                    ->label(fn ($record) => __('VAT').' ('.number_format($record->tax_rate, 1).'%)')
                                    ->money(fn ($record) => $record->order->currency)
                                    ->visible(fn ($record) => $record->tax_amount->getAmount() > 0),

                                Infolists\Components\TextEntry::make('commission_amount')
                                    ->label(fn ($record) => __('Commission').' ('.number_format($record->commission_rate, 1).'%)')
                                    ->money(fn ($record) => $record->order->currency)
                                    ->color('warning')
                                    ->visible(fn ($record) => $record->commission_amount->getAmount() > 0),

                                Infolists\Components\TextEntry::make('total_price')
                                    ->label(__('Total'))
                                    ->money(fn ($record) => $record->order->currency)
                                    ->weight('medium'),
                            ])
                            ->columns(4)
                            ->contained(false),
                    ]),

                Schemas\Components\Section::make(__('Shipping Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('shipping_carrier')
                            ->label(__('Carrier'))
                            ->icon('heroicon-o-truck')
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('shipping_desi')
                            ->label(__('Desi (Volumetric Weight)'))
                            ->suffix(' desi')
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->shipping_desi > 0),

                        Infolists\Components\TextEntry::make('shipping_tracking_number')
                            ->label(__('Tracking Number'))
                            ->copyable()
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('shipping_tracking_url')
                            ->label(__('Tracking Link'))
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab()
                            ->placeholder(__('Not available'))
                            ->columnSpanFull(),

                        Infolists\Components\TextEntry::make('shipped_at')
                            ->label(__('Shipped Date'))
                            ->dateTime()
                            ->placeholder(__('Not shipped yet')),

                        Infolists\Components\TextEntry::make('delivered_at')
                            ->label(__('Delivered Date'))
                            ->dateTime()
                            ->placeholder(__('Not delivered yet'))
                            ->visible(fn ($record) => $record->delivered_at),

                        Infolists\Components\TextEntry::make('estimated_delivery_start')
                            ->label(__('Estimated Delivery (Start)'))
                            ->dateTime()
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->estimated_delivery_start),

                        Infolists\Components\TextEntry::make('estimated_delivery_end')
                            ->label(__('Estimated Delivery (End)'))
                            ->dateTime()
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->estimated_delivery_end),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->visible(fn ($record) => $record->shipping_carrier || $record->shipping_tracking_number),

                Schemas\Components\Section::make(__('Invoice Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('invoice_number')
                            ->label(__('Invoice Number'))
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('invoice_date')
                            ->label(__('Invoice Date'))
                            ->date()
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('invoice_url')
                            ->label(__('Invoice Link'))
                            ->url(fn ($state) => $state)
                            ->openUrlInNewTab()
                            ->placeholder(__('Not available'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Schemas\Components\Section::make(__('Notes'))
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('')
                            ->placeholder(__('No notes'))
                            ->columnSpanFull()
                            ->prose(),
                    ])
                    ->collapsible()
                    ->collapsed(),

                Schemas\Components\Section::make(__('Returns'))
                    ->schema([
                        Infolists\Components\TextEntry::make('return_status')
                            ->label(__('Return Status'))
                            ->badge()
                            ->color(fn ($state) => match ($state) {
                                'full' => 'danger',
                                'partial' => 'warning',
                                'none', null => 'success',
                                default => 'gray',
                            })
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'full' => __('Fully Returned'),
                                'partial' => __('Partially Returned'),
                                'none', null => __('No Returns'),
                                default => ucfirst($state),
                            }),

                        Infolists\Components\RepeatableEntry::make('returns')
                            ->label(__('Return Requests'))
                            ->schema([
                                Infolists\Components\TextEntry::make('return_number')
                                    ->label(__('Return #'))
                                    ->url(fn ($record) => route('filament.admin.resources.order.order-returns.view', ['record' => $record]))
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('status')
                                    ->label(__('Status'))
                                    ->badge(),

                                Infolists\Components\TextEntry::make('reason_name')
                                    ->label(__('Reason'))
                                    ->limit(40),

                                Infolists\Components\TextEntry::make('requested_at')
                                    ->label(__('Requested'))
                                    ->dateTime()
                                    ->since(),

                                Infolists\Components\TextEntry::make('total_refund_amount')
                                    ->label(__('Refund'))
                                    ->money(fn ($record) => $record->currency),

                                Infolists\Components\TextEntry::make('items_count')
                                    ->label(__('Items'))
                                    ->state(fn ($record) => $record->items->count())
                                    ->badge(),
                            ])
                            ->columns(3)
                            ->contained(false),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => ! $record->hasReturns())
                    ->visible(fn ($record) => $record->returns()->exists()),

                Schemas\Components\Section::make(__('Activity Log'))
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('activities')
                            ->label('')
                            ->schema([
                                Infolists\Components\TextEntry::make('description')
                                    ->label(__('Event'))
                                    ->formatStateUsing(fn ($state) => ucfirst(str_replace('_', ' ', $state))),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label(__('Date'))
                                    ->dateTime()
                                    ->since(),

                                Infolists\Components\TextEntry::make('causer.name')
                                    ->label(__('By'))
                                    ->placeholder(__('System'))
                                    ->icon('heroicon-o-user'),

                                Infolists\Components\TextEntry::make('properties')
                                    ->label(__('Details'))
                                    ->formatStateUsing(function ($state) {
                                        if (is_array($state) && ! empty($state)) {
                                            return collect($state)
                                                ->map(fn ($value, $key) => ucfirst($key).': '.json_encode($value))
                                                ->join(', ');
                                        }

                                        return __('No details');
                                    })
                                    ->placeholder(__('No details'))
                                    ->columnSpanFull()
                                    ->prose(),
                            ])
                            ->columns(3)
                            ->contained(false),
                    ])
                    ->collapsible()
                    ->collapsed(fn ($record) => ! $record->relationLoaded('activities') || $record->activities->count() === 0),
            ]);
    }
}
