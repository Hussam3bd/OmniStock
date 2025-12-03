<?php

namespace App\Filament\Widgets;

use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use App\Models\Order\ReturnRefund;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReturnsStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        // Returns statistics (exclude cancelled)
        $totalReturns = OrderReturn::where('status', '!=', ReturnStatus::Cancelled)->count();
        $pendingReturns = OrderReturn::where('status', ReturnStatus::Requested)->count();
        $completedReturns = OrderReturn::where('status', ReturnStatus::Completed)->count();

        // Calculate return rate
        $totalOrders = \App\Models\Order\Order::count();
        $returnRate = $totalOrders > 0 ? round(($totalReturns / $totalOrders) * 100, 1) : 0;

        // Refunds statistics
        $totalRefundAmount = ReturnRefund::where('status', 'completed')->sum('amount');
        $pendingRefundAmount = ReturnRefund::where('status', 'pending')->sum('amount');
        $totalRefunds = ReturnRefund::count();

        return [
            Stat::make(__('Total Returns'), number_format($totalReturns))
                ->description(__(':rate% return rate', ['rate' => $returnRate]))
                ->descriptionIcon('heroicon-m-arrow-trending-down')
                ->color('warning')
                ->chart(array_values($this->getReturnsTrendData())),

            Stat::make(__('Pending Returns'), number_format($pendingReturns))
                ->description(__(':completed completed', ['completed' => number_format($completedReturns)]))
                ->descriptionIcon('heroicon-m-clock')
                ->color('danger'),

            Stat::make(__('Total Refunded'), money($totalRefundAmount / 100, 'TRY')->format())
                ->description(__(':count refund transactions', ['count' => number_format($totalRefunds)]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('info'),

            Stat::make(__('Pending Refunds'), money($pendingRefundAmount / 100, 'TRY')->format())
                ->description(__('Awaiting processing'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('warning'),
        ];
    }

    protected function getReturnsTrendData(): array
    {
        // Get returns count for last 7 days (exclude cancelled)
        return OrderReturn::where('requested_at', '>=', now()->subDays(7))
            ->where('status', '!=', ReturnStatus::Cancelled)
            ->selectRaw('DATE(requested_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }
}
