<?php

namespace App\Filament\Resources\Order\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Order Items';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('productVariant.sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('productVariant.product.name')
                    ->label(__('Product'))
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->productVariant->product->name),

                TextColumn::make('productVariant.option_values')
                    ->label(__('Variant'))
                    ->placeholder(__('Default'))
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),

                TextColumn::make('quantity')
                    ->label(__('Qty'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                TextColumn::make('unit_price')
                    ->label(__('Unit Price'))
                    ->money(fn ($record) => $record->order->currency)
                    ->sortable(),

                TextColumn::make('discount_amount')
                    ->label(__('Discount'))
                    ->money(fn ($record) => $record->order->currency)
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('tax_amount')
                    ->label(__('VAT'))
                    ->money(fn ($record) => $record->order->currency)
                    ->description(fn ($record) => number_format($record->tax_rate, 1).'%'),

                TextColumn::make('commission_amount')
                    ->label(__('Commission'))
                    ->money(fn ($record) => $record->order->currency)
                    ->color('warning')
                    ->description(fn ($record) => number_format($record->commission_rate, 1).'%')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_price')
                    ->label(__('Total'))
                    ->money(fn ($record) => $record->order->currency)
                    ->weight('medium')
                    ->sortable(),
            ])
            ->defaultSort('id', 'asc');
    }
}
