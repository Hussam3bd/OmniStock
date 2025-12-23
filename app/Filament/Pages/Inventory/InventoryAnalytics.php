<?php

namespace App\Filament\Pages\Inventory;

use App\Models\Product\ProductVariant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

class InventoryAnalytics extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected string $view = 'filament.pages.inventory.inventory-analytics';

    protected static string|UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('Inventory Analytics');
    }

    public function getTitle(): string
    {
        return __('Inventory Analytics');
    }

    public function getHeading(): string
    {
        return __('Inventory Movement & Sales Report');
    }

    public function getSubheading(): ?string
    {
        return __('Track inventory sales, returns, and stock levels');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getInventoryQuery())
            ->columns([
                TextColumn::make('product_info')
                    ->label(__('Product'))
                    ->searchable(['sku', 'product_title', 'variant_title'])
                    ->html()
                    ->getStateUsing(function ($record) {
                        $product = '<div class="font-medium">'.$record->product_title.'</div>';
                        $sku = '<div class="font-mono text-sm text-gray-600 dark:text-gray-400">'.$record->sku.'</div>';
                        $variant = $record->variant_title
                            ? '<div class="text-sm text-gray-500 dark:text-gray-400">'.$record->variant_title.'</div>'
                            : '';

                        return $product.$sku.$variant;
                    })
                    ->wrap()
                    ->grow()
                    ->extraAttributes(['style' => 'min-width: 300px;']),

                TextColumn::make('current_stock')
                    ->label(__('Current Stock'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->color(fn ($record) => match (true) {
                        $record->current_stock == 0 => 'danger',
                        $record->current_stock <= 5 => 'warning',
                        default => 'success',
                    }),

                TextColumn::make('total_items_ordered')
                    ->label(__('Total Ordered'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(__('Total items ordered (including cancelled/rejected)')),

                TextColumn::make('items_sold_net')
                    ->label(__('Items Sold'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->weight('bold')
                    ->color('success')
                    ->tooltip(__('Completed orders (excluding returns, cancelled, rejected)')),

                TextColumn::make('items_returned')
                    ->label(__('Returns'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->color('danger')
                    ->tooltip(__('Items returned (full or partial returns)')),

                TextColumn::make('net_sold')
                    ->label(__('Net Sold'))
                    ->getStateUsing(fn ($record) => $record->items_sold_net - $record->items_returned)
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->weight('bold')
                    ->color('info')
                    ->tooltip(__('Items Sold - Returns')),

                TextColumn::make('return_rate')
                    ->label(__('Return Rate'))
                    ->getStateUsing(function ($record) {
                        if ($record->items_sold_net == 0) {
                            return 0;
                        }

                        return round(($record->items_returned / $record->total_items_ordered) * 100, 1);
                    })
                    ->suffix('%')
                    ->sortable()
                    ->alignCenter()
                    ->color(fn ($state) => match (true) {
                        $state >= 20 => 'danger',
                        $state >= 10 => 'warning',
                        default => 'success',
                    })
                    ->tooltip(__('Percentage of items returned')),

                TextColumn::make('turnover')
                    ->label(__('Inventory Turnover'))
                    ->getStateUsing(function ($record) {
                        $netSold = $record->items_sold_net - $record->items_returned;
                        if ($record->current_stock == 0) {
                            return $netSold > 0 ? '∞' : '0';
                        }

                        return round($netSold / ($record->current_stock + $netSold), 2);
                    })
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(__('How quickly inventory is sold: Net Sold / (Current Stock + Net Sold)')),
            ])
            ->defaultSort('total_items_ordered', 'desc')
            ->filters([
                //
            ])
            ->paginated([10, 25, 50, 100]);
    }

    protected function getInventoryQuery(): Builder
    {
        return ProductVariant::query()
            ->select([
                'product_variants.id',
                'product_variants.sku',
                'products.title as product_title',
                'product_variants.title as variant_title',
                'product_variants.inventory_quantity as current_stock',
                DB::raw('SUM(CASE WHEN orders.order_status NOT IN (\'cancelled\', \'rejected\') AND (orders.return_status IS NULL OR orders.return_status = \'none\') THEN order_items.quantity ELSE 0 END) as items_sold_net'),
                DB::raw('SUM(CASE WHEN orders.return_status IN (\'full\', \'partial\') THEN order_items.quantity ELSE 0 END) as items_returned'),
                DB::raw('SUM(CASE WHEN orders.order_status NOT IN (\'cancelled\', \'rejected\') THEN order_items.quantity ELSE 0 END) as total_items_ordered'),
            ])
            ->leftJoin('products', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('order_items', 'product_variants.id', '=', 'order_items.product_variant_id')
            ->leftJoin('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('product_variants.inventory_quantity', '>=', 0)
            ->groupBy('product_variants.id', 'product_variants.sku', 'products.title', 'product_variants.title', 'product_variants.inventory_quantity')
            ->having('total_items_ordered', '>', 0);
    }

    protected function getViewData(): array
    {
        $data = $this->getInventoryQuery()->get();

        return [
            'totalVariants' => $data->count(),
            'totalItemsSold' => $data->sum('items_sold_net'),
            'totalItemsReturned' => $data->sum('items_returned'),
            'totalCurrentStock' => $data->sum('current_stock'),
        ];
    }
}
