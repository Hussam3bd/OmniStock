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
                    ->description(function ($record) {
                        $hasDiscount = $record->discount_amount->getAmount() > 0;
                        if (! $hasDiscount) {
                            return null;
                        }

                        $perUnitDiscount = $record->discount_amount->divide($record->quantity);
                        $finalUnitPrice = $record->unit_price->subtract($perUnitDiscount);

                        return 'Discount: -'.$perUnitDiscount->format().' → Final: '.$finalUnitPrice->format();
                    })
                    ->color(fn ($record) => $record->discount_amount->getAmount() > 0 ? 'danger' : null)
                    ->icon(fn ($record) => $record->discount_amount->getAmount() > 0 ? 'heroicon-o-tag' : null)
                    ->sortable(),

                TextColumn::make('tax_amount')
                    ->label(__('VAT'))
                    ->money(fn ($record) => $record->order->currency)
                    ->description(fn ($record) => $record->tax_rate > 0 ? number_format($record->tax_rate, 1).'%' : null)
                    ->placeholder('-'),

                TextColumn::make('commission_amount')
                    ->label(__('Commission'))
                    ->money(fn ($record) => $record->order->currency)
                    ->color('warning')
                    ->description(fn ($record) => $record->commission_rate > 0 ? number_format($record->commission_rate, 1).'%' : null)
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
