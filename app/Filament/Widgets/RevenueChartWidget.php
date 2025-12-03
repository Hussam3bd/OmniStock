<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderStatus;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RevenueChartWidget extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Revenue Over Time';

    public ?string $filter = '30';

    protected function getData(): array
    {
        $days = (int) $this->filter;
        $startDate = now()->subDays($days);

        // Get revenue grouped by date
        $revenueByDate = Order::where('order_date', '>=', $startDate)
            ->where('order_status', OrderStatus::COMPLETED)
            ->select(
                DB::raw('DATE(order_date) as date'),
                DB::raw('SUM(total_amount) / 100 as revenue')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('revenue', 'date');

        // Fill in missing dates with 0
        $labels = [];
        $data = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $labels[] = Carbon::parse($date)->format('M d');
            $data[] = $revenueByDate->get($date, 0);
        }

        return [
            'datasets' => [
                [
                    'label' => __('Revenue'),
                    'data' => $data,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getFilters(): ?array
    {
        return [
            '7' => __('Last 7 days'),
            '30' => __('Last 30 days'),
            '90' => __('Last 90 days'),
            '365' => __('Last year'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'ticks' => [
                        'callback' => "function(value) { return '₺' + value.toLocaleString(); }",
                    ],
                ],
            ],
        ];
    }
}
