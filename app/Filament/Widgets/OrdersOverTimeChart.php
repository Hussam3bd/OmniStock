<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class OrdersOverTimeChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Orders Over Time by Channel';

    public ?string $filter = 'daily';

    protected function getData(): array
    {
        $period = $this->filter;

        // Determine date range and grouping based on period
        switch ($period) {
            case 'daily':
                $days = 30;
                $dateFormat = 'Y-m-d';
                $labelFormat = 'M d';
                $groupByExpression = 'DATE(order_date)';
                break;
            case 'weekly':
                $days = 90;
                $dateFormat = 'Y-\WW';
                $labelFormat = 'W';
                $groupByExpression = 'YEARWEEK(order_date, 1)';
                break;
            case 'monthly':
                $days = 365;
                $dateFormat = 'Y-m';
                $labelFormat = 'M Y';
                $groupByExpression = 'DATE_FORMAT(order_date, "%Y-%m")';
                break;
            default:
                $days = 30;
                $dateFormat = 'Y-m-d';
                $labelFormat = 'M d';
                $groupByExpression = 'DATE(order_date)';
        }

        $startDate = now()->subDays($days);

        // Get orders grouped by date and channel
        $ordersByDateAndChannel = Order::where('order_date', '>=', $startDate)
            ->select(
                'channel',
                DB::raw("{$groupByExpression} as period"),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('channel', DB::raw($groupByExpression))
            ->orderBy('period')
            ->get()
            ->groupBy('channel');

        // Prepare labels (dates)
        $labels = [];
        $periods = [];

        if ($period === 'weekly') {
            // For weekly, generate week labels
            $currentDate = now()->subDays($days);
            $endDate = now();
            while ($currentDate <= $endDate) {
                $yearWeek = $currentDate->format('oW'); // ISO year and week
                if (! in_array($yearWeek, $periods)) {
                    $periods[] = $yearWeek;
                    $labels[] = 'Week '.$currentDate->format('W');
                }
                $currentDate->addWeek();
            }
        } elseif ($period === 'monthly') {
            // For monthly, generate month labels
            for ($i = $days / 30; $i >= 0; $i--) {
                $date = now()->subMonths($i);
                $periodKey = $date->format($dateFormat);
                $periods[] = $periodKey;
                $labels[] = $date->format($labelFormat);
            }
        } else {
            // For daily
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = now()->subDays($i);
                $periodKey = $date->format($dateFormat);
                $periods[] = $periodKey;
                $labels[] = $date->format($labelFormat);
            }
        }

        // Prepare datasets (one per channel)
        $datasets = [];
        $channelColors = [
            'portal' => '#3b82f6',     // blue
            'shopify' => '#10b981',    // green
            'trendyol' => '#f59e0b',   // amber
            'marketplace' => '#8b5cf6', // purple
            'wholesale' => '#ec4899',   // pink
        ];

        foreach (OrderChannel::cases() as $channel) {
            $channelValue = $channel->value;
            $channelData = $ordersByDateAndChannel->get($channelValue, collect());

            $data = [];
            foreach ($periods as $period) {
                $periodData = $channelData->firstWhere('period', $period);
                $data[] = $periodData ? $periodData->count : 0;
            }

            // Only add dataset if there's data
            if (array_sum($data) > 0) {
                $datasets[] = [
                    'label' => $channel->getLabel(),
                    'data' => $data,
                    'backgroundColor' => $channelColors[$channelValue] ?? '#6b7280',
                    'borderColor' => $channelColors[$channelValue] ?? '#6b7280',
                ];
            }
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getFilters(): ?array
    {
        return [
            'daily' => __('Daily (Last 30 days)'),
            'weekly' => __('Weekly (Last 90 days)'),
            'monthly' => __('Monthly (Last year)'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'x' => [
                    'stacked' => true,
                ],
                'y' => [
                    'stacked' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
