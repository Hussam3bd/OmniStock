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

                Schemas\Components\Section::make(__('Payment Information'))
                    ->schema([
                        Infolists\Components\TextEntry::make('payment_method')
                            ->label(__('Payment Method'))
                            ->formatStateUsing(fn ($state) => match ($state) {
                                'cod' => 'Cash on Delivery',
                                'bank_transfer' => 'Bank Transfer',
                                'online' => 'Online Payment',
                                default => $state ? ucfirst($state) : '-',
                            })
                            ->icon(fn ($state) => match ($state) {
                                'cod' => 'heroicon-o-banknotes',
                                'bank_transfer' => 'heroicon-o-building-library',
                                'online' => 'heroicon-o-credit-card',
                                default => 'heroicon-o-currency-dollar',
                            })
                            ->color(fn ($state) => match ($state) {
                                'cod' => 'warning',
                                'bank_transfer' => 'info',
                                'online' => 'success',
                                default => 'gray',
                            })
                            ->badge(),

                        Infolists\Components\TextEntry::make('payment_gateway')
                            ->label(__('Payment Gateway'))
                            ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : '-')
                            ->placeholder(__('Not available'))
                            ->badge()
                            ->color('gray'),

                        Infolists\Components\TextEntry::make('payment_transaction_id')
                            ->label(__('Transaction ID'))
                            ->copyable()
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->payment_transaction_id)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => $record->payment_method || $record->payment_gateway),

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

                Schemas\Components\Section::make(__('Shipping Address'))
                    ->schema([
                        Infolists\Components\TextEntry::make('shippingAddress.full_name')
                            ->label(__('Name'))
                            ->icon('heroicon-o-user')
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('shippingAddress.phone')
                            ->label(__('Phone'))
                            ->icon('heroicon-o-phone')
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('shippingAddress.email')
                            ->label(__('Email'))
                            ->icon('heroicon-o-envelope')
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->shippingAddress?->email),

                        Infolists\Components\TextEntry::make('shippingAddress.type')
                            ->label(__('Type'))
                            ->badge()
                            ->visible(fn ($record) => $record->shippingAddress?->type),

                        Infolists\Components\TextEntry::make('shippingAddress.full_address')
                            ->label(__('Address'))
                            ->icon('heroicon-o-map-pin')
                            ->placeholder(__('Not available'))
                            ->columnSpanFull()
                            ->prose(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->visible(fn ($record) => $record->shippingAddress),

                Schemas\Components\Section::make(__('Billing Address'))
                    ->schema([
                        Infolists\Components\TextEntry::make('billingAddress.full_name')
                            ->label(__('Name'))
                            ->icon('heroicon-o-user')
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('billingAddress.phone')
                            ->label(__('Phone'))
                            ->icon('heroicon-o-phone')
                            ->placeholder(__('Not available')),

                        Infolists\Components\TextEntry::make('billingAddress.email')
                            ->label(__('Email'))
                            ->icon('heroicon-o-envelope')
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->billingAddress?->email),

                        Infolists\Components\TextEntry::make('billingAddress.type')
                            ->label(__('Type'))
                            ->badge()
                            ->visible(fn ($record) => $record->billingAddress?->type),

                        Infolists\Components\TextEntry::make('billingAddress.tax_office')
                            ->label(__('Tax Office'))
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->billingAddress?->tax_office),

                        Infolists\Components\TextEntry::make('billingAddress.tax_number')
                            ->label(__('Tax Number'))
                            ->placeholder(__('Not available'))
                            ->visible(fn ($record) => $record->billingAddress?->tax_number),

                        Infolists\Components\TextEntry::make('billingAddress.full_address')
                            ->label(__('Address'))
                            ->icon('heroicon-o-map-pin')
                            ->placeholder(__('Not available'))
                            ->columnSpanFull()
                            ->prose(),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->visible(fn ($record) => $record->billingAddress),

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
