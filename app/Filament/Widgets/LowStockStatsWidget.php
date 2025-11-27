<?php

namespace App\Filament\Widgets;

use App\Models\Product\ProductVariant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class LowStockStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $outOfStock = ProductVariant::where('inventory_quantity', '<=', 0)->count();
        $lowStock = ProductVariant::whereBetween('inventory_quantity', [1, 10])->count();
        $totalVariants = ProductVariant::count();
        $inStock = $totalVariants - $outOfStock - $lowStock;

        return [
            Stat::make(__('Out of Stock'), $outOfStock)
                ->description(__('Variants with no stock'))
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->url(route('filament.admin.resources.product.products.index', [
                    'tableFilters' => ['stock_status' => ['value' => 'out_of_stock']],
                ])),

            Stat::make(__('Low Stock'), $lowStock)
                ->description(__('Variants with ≤10 units'))
                ->descriptionIcon('heroicon-o-arrow-trending-down')
                ->color('warning')
                ->url(route('filament.admin.resources.product.products.index', [
                    'tableFilters' => ['stock_status' => ['value' => 'low_stock']],
                ])),

            Stat::make(__('In Stock'), $inStock)
                ->description(__('Variants with >10 units'))
                ->descriptionIcon('heroicon-o-check-circle')
                ->color('success'),
        ];
    }
}
