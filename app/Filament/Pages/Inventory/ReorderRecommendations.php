<?php

namespace App\Filament\Pages\Inventory;

use App\Services\Product\ProductDemandAnalyzer;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class ReorderRecommendations extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';
    protected string $view = 'filament.pages.inventory.reorder-recommendations';
    protected static string|UnitEnum|null $navigationGroup = 'Inventory';
    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('Reorder Recommendations');
    }

    public function getTitle(): string
    {
        return __('Reorder Recommendations');
    }

    public function getHeading(): string
    {
        return __('Product Reorder Recommendations');
    }

    public function getSubheading(): ?string
    {
        return __('Low stock & high demand products based on sales analysis');
    }

    public function table(Table $table): Table
    {
        $analyzer = app(ProductDemandAnalyzer::class);

        return $table
            ->records(fn () => $analyzer->getReorderRecommendations())
            ->columns([
                TextColumn::make('priority')
                    ->label(__('Priority'))
                    ->badge()
                    ->color(fn($state) => match (true) {
                        $state >= 70 => 'danger',
                        $state >= 50 => 'warning',
                        $state >= 30 => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state) => $state.'%')
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('product_name')
                    ->label(__('Product'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),

                TextColumn::make('variant_title')
                    ->label(__('Variant'))
                    ->searchable()
                    ->limit(20)
                    ->toggleable(),

                TextColumn::make('current_stock')
                    ->label(__('Current Stock'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->color('warning'),

                TextColumn::make('avg_daily_sales')
                    ->label(__('Avg Daily Sales'))
                    ->numeric(2)
                    ->sortable()
                    ->alignCenter()
                    ->tooltip(__('Weighted average (recent sales weighted more)')),

                TextColumn::make('days_of_stock_remaining')
                    ->label(__('Days of Stock'))
                    ->numeric(1)
                    ->sortable()
                    ->alignCenter()
                    ->color(fn($state) => match (true) {
                        $state <= 3 => 'danger',
                        $state <= 7 => 'warning',
                        $state <= 14 => 'info',
                        default => 'success',
                    })
                    ->tooltip(__('Days until stock runs out at current sales rate')),

                TextColumn::make('demand_trend')
                    ->label(__('Trend'))
                    ->badge()
                    ->color(fn($state) => match ($state) {
                        'increasing' => 'success',
                        'stable' => 'info',
                        'decreasing' => 'warning',
                        'new' => 'primary',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state) => match ($state) {
                        'increasing' => '📈 '.ucfirst($state),
                        'stable' => '➡️ '.ucfirst($state),
                        'decreasing' => '📉 '.ucfirst($state),
                        'new' => '⭐ '.ucfirst($state),
                        default => ucfirst($state),
                    })
                    ->sortable()
                    ->alignCenter(),

                TextColumn::make('suggested_order_quantity')
                    ->label(__('Suggested Order'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->weight('bold')
                    ->color('success')
                    ->tooltip(__('Recommended quantity to order')),

                TextColumn::make('total_sales_7d')
                    ->label(__('Sales (7d)'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('total_sales_30d')
                    ->label(__('Sales (30d)'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('cost_price')
                    ->label(__('Cost'))
                    ->money('TRY')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('estimated_cost')
                    ->label(__('Est. Order Cost'))
                    ->getStateUsing(function ($record) {
                        if (!$record['cost_price']) {
                            return null;
                        }

                        return $record['cost_price']->getAmount() * $record['suggested_order_quantity'] / 100;
                    })
                    ->money('TRY')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('priority', 'desc')
            ->filters([
                //
            ])
            ->headerActions([
                // TODO: Add "Create PO" action
            ])
            ->paginated([10, 25, 50, 100]);
    }

    protected function getViewData(): array
    {
        $analyzer = app(ProductDemandAnalyzer::class);
        $recommendations = $analyzer->getReorderRecommendations();

        return [
            'totalProducts' => $recommendations->count(),
            'criticalProducts' => $recommendations->where('days_of_stock_remaining', '<=', 3)->count(),
            'urgentProducts' => $recommendations->where('days_of_stock_remaining', '<=', 7)->count(),
            'increasingDemand' => $recommendations->where('demand_trend', 'increasing')->count(),
        ];
    }
}
