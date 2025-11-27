<?php

namespace App\Filament\Pages;

use App\Models\Product\ProductVariant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class InventoryReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Inventory Report');
    }

    public function getTitle(): string
    {
        return __('Inventory Report');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(ProductVariant::query()->with(['product', 'optionValues']))
            ->columns([
                Tables\Columns\TextColumn::make('product.title')
                    ->label(__('Product'))
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('optionValues.value')
                    ->label(__('Variant'))
                    ->badge()
                    ->separator(' / ')
                    ->formatStateUsing(fn ($state) => __($state)),

                Tables\Columns\TextColumn::make('inventory_quantity')
                    ->label(__('Stock'))
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 10 => 'warning',
                        default => 'success',
                    })
                    ->summarize([
                        Tables\Columns\Summarizers\Sum::make()
                            ->label(__('Total Units')),
                    ]),

                Tables\Columns\TextColumn::make('price')
                    ->label(__('Price'))
                    ->money('TRY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_price')
                    ->label(__('Cost'))
                    ->money('TRY')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('inventory_value')
                    ->label(__('Stock Value'))
                    ->money('TRY')
                    ->getStateUsing(fn (ProductVariant $record): float => $record->price * $record->inventory_quantity)
                    ->sortable(query: function ($query, string $direction): void {
                        $query->orderByRaw("(price * inventory_quantity) {$direction}");
                    }),

                Tables\Columns\TextColumn::make('cost_value')
                    ->label(__('Cost Value'))
                    ->money('TRY')
                    ->getStateUsing(fn (ProductVariant $record): float => ($record->cost_price ?? 0) * $record->inventory_quantity)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('potential_profit')
                    ->label(__('Potential Profit'))
                    ->money('TRY')
                    ->getStateUsing(fn (ProductVariant $record): float => ($record->price - ($record->cost_price ?? 0)) * $record->inventory_quantity)
                    ->toggleable()
                    ->color(fn (float $state): string => $state > 0 ? 'success' : 'danger'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stock_status')
                    ->label(__('Stock Status'))
                    ->options([
                        'in_stock' => __('In Stock'),
                        'low_stock' => __('Low Stock (≤10)'),
                        'out_of_stock' => __('Out of Stock'),
                    ])
                    ->query(function ($query, $state) {
                        return match ($state['value'] ?? null) {
                            'out_of_stock' => $query->where('inventory_quantity', '<=', 0),
                            'low_stock' => $query->whereBetween('inventory_quantity', [1, 10]),
                            'in_stock' => $query->where('inventory_quantity', '>', 10),
                            default => $query,
                        };
                    }),

                Tables\Filters\Filter::make('has_cost')
                    ->label(__('Has Cost Price'))
                    ->query(fn ($query) => $query->whereNotNull('cost_price')),

                Tables\Filters\Filter::make('profitable')
                    ->label(__('Profitable Only'))
                    ->query(fn ($query) => $query->whereRaw('price > COALESCE(cost_price, 0)')),
            ])
            ->defaultSort('inventory_value', 'desc')
            ->paginated([10, 25, 50, 100]);
    }
}
