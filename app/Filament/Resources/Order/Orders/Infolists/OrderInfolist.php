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
                                    ->label(__('Product')),

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

                                Infolists\Components\TextEntry::make('total_price')
                                    ->label(__('Total'))
                                    ->money(fn ($record) => $record->order->currency)
                                    ->weight('medium'),
                            ])
                            ->columns(3)
                            ->contained(false),
                    ]),

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
