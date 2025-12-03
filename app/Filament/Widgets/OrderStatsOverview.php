<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderStatus;
use App\Models\Order\Order;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class OrderStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        // Get date range (last 30 days for comparison)
        $startDate = now()->subDays(30);
        $previousStartDate = now()->subDays(60);
        $previousEndDate = now()->subDays(30);

        // Current period stats
        $currentOrders = Order::where('order_date', '>=', $startDate)->count();
        $currentRevenue = Order::where('order_date', '>=', $startDate)
            ->where('order_status', OrderStatus::COMPLETED)
            ->sum('total_amount');

        // Previous period stats for comparison
        $previousOrders = Order::whereBetween('order_date', [$previousStartDate, $previousEndDate])->count();
        $previousRevenue = Order::whereBetween('order_date', [$previousStartDate, $previousEndDate])
            ->where('order_status', OrderStatus::COMPLETED)
            ->sum('total_amount');

        // Calculate percentage changes
        $ordersChange = $previousOrders > 0
            ? round((($currentOrders - $previousOrders) / $previousOrders) * 100, 1)
            : 0;

        $revenueChange = $previousRevenue > 0
            ? round((($currentRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
            : 0;

        // Total all-time stats
        $totalOrders = Order::count();
        $completedOrders = Order::where('order_status', OrderStatus::COMPLETED)->count();
        $totalRevenue = Order::where('order_status', OrderStatus::COMPLETED)->sum('total_amount');
        $avgOrderValue = $completedOrders > 0 ? $totalRevenue / $completedOrders : 0;

        // Pending orders
        $pendingOrders = Order::whereIn('order_status', [
            OrderStatus::PENDING,
            OrderStatus::CONFIRMED,
            OrderStatus::PROCESSING,
        ])->count();

        return [
            Stat::make(__('Total Orders'), number_format($totalOrders))
                ->description(__(':count orders in last 30 days', ['count' => number_format($currentOrders)]))
                ->descriptionIcon($ordersChange >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($ordersChange >= 0 ? 'success' : 'danger')
                ->chart(array_values($this->getOrdersTrendData()))
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                ]),

            Stat::make(__('Total Revenue'), money($totalRevenue / 100, 'TRY')->format())
                ->description(__(':change% from previous period', ['change' => $revenueChange >= 0 ? "+{$revenueChange}" : $revenueChange]))
                ->descriptionIcon($revenueChange >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down')
                ->color($revenueChange >= 0 ? 'success' : 'danger')
                ->chart(array_values($this->getRevenueTrendData())),

            Stat::make(__('Average Order Value'), money($avgOrderValue / 100, 'TRY')->format())
                ->description(__('Across all completed orders'))
                ->descriptionIcon('heroicon-m-calculator')
                ->color('info'),

            Stat::make(__('Pending Orders'), number_format($pendingOrders))
                ->description(__('Awaiting fulfillment'))
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }

    protected function getOrdersTrendData(): array
    {
        // Get orders count for last 7 days
        return Order::where('order_date', '>=', now()->subDays(7))
            ->select(DB::raw('DATE(order_date) as date'), DB::raw('COUNT(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    protected function getRevenueTrendData(): array
    {
        // Get revenue for last 7 days
        return Order::where('order_date', '>=', now()->subDays(7))
            ->where('order_status', OrderStatus::COMPLETED)
            ->select(DB::raw('DATE(order_date) as date'), DB::raw('SUM(total_amount) as revenue'))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('revenue', 'date')
            ->map(fn ($value) => $value / 100) // Convert from minor units
            ->toArray();
    }
}
