<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Infolists;

use Filament\Infolists;
use Filament\Schemas;
use Filament\Schemas\Schema;

class PurchaseOrderInfolist
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

                        Infolists\Components\TextEntry::make('supplier.name')
                            ->label(__('Supplier')),

                        Infolists\Components\TextEntry::make('location.name')
                            ->label(__('Destination Location'))
                            ->icon('heroicon-o-map-pin')
                            ->placeholder(__('Not set')),

                        Infolists\Components\TextEntry::make('status')
                            ->label(__('Status'))
                            ->badge(),

                        Infolists\Components\TextEntry::make('order_date')
                            ->label(__('Order Date'))
                            ->date(),

                        Infolists\Components\TextEntry::make('expected_delivery_date')
                            ->label(__('Expected Delivery Date'))
                            ->date()
                            ->placeholder(__('Not set')),

                        Infolists\Components\TextEntry::make('received_date')
                            ->label(__('Received Date'))
                            ->date()
                            ->placeholder(__('Not received')),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Order Summary'))
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal')
                            ->label(__('Subtotal'))
                            ->money('TRY', divideBy: 100),

                        Infolists\Components\TextEntry::make('tax')
                            ->label(__('Tax'))
                            ->money('TRY', divideBy: 100),

                        Infolists\Components\TextEntry::make('shipping_cost')
                            ->label(__('Shipping Cost'))
                            ->money('TRY', divideBy: 100),

                        Infolists\Components\TextEntry::make('total')
                            ->label(__('Total'))
                            ->money('TRY', divideBy: 100)
                            ->weight('medium')
                            ->size('lg'),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(function ($record) {
                    $itemsCount = $record->items->count();

                    return __('Order Items').' ('.$itemsCount.')';
                })
                    ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->label('')
                        ->schema([
                            Infolists\Components\TextEntry::make('productVariant.sku')
                                ->label(__('SKU')),

                            Infolists\Components\TextEntry::make('productVariant.product.name')
                                ->label(__('Product')),

                            Infolists\Components\TextEntry::make('quantity_ordered')
                                ->label(__('Qty Ordered'))
                                ->badge()
                                ->color('gray'),

                            Infolists\Components\TextEntry::make('quantity_received')
                                ->label(__('Qty Received'))
                                ->badge()
                                ->color(fn ($state, $record) => match (true) {
                                    $state == 0 => 'gray',
                                    $state < $record->quantity_ordered => 'warning',
                                    $state == $record->quantity_ordered => 'success',
                                    default => 'danger',
                                }),

                            Infolists\Components\TextEntry::make('unit_cost')
                                ->label(__('Unit Cost'))
                                ->money('TRY', divideBy: 100),

                            Infolists\Components\TextEntry::make('tax_rate')
                                ->label(__('Tax Rate'))
                                ->suffix('%'),

                            Infolists\Components\TextEntry::make('total')
                                ->label(__('Total'))
                                ->money('TRY', divideBy: 100)
                                ->weight('medium'),
                        ])
                        ->columns(4)
                        ->contained(false),
                ]),

                Schemas\Components\Section::make(__('Notes'))
                    ->schema([
                        Infolists\Components\TextEntry::make('notes')
                            ->label('')
                            ->placeholder(__('No notes'))
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
