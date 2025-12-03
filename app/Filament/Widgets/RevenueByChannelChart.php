<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class RevenueByChannelChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Revenue by Channel';

    protected function getData(): array
    {
        $revenueByChannel = Order::select('channel', DB::raw('SUM(total_amount) / 100 as revenue'))
            ->where('order_status', OrderStatus::COMPLETED)
            ->groupBy('channel')
            ->pluck('revenue', 'channel')
            ->toArray();

        $labels = [];
        $data = [];
        $colors = [];

        $channelColors = [
            'portal' => '#3b82f6',     // blue
            'shopify' => '#10b981',    // green
            'trendyol' => '#f59e0b',   // amber
            'marketplace' => '#8b5cf6', // purple
            'wholesale' => '#ec4899',   // pink
        ];

        foreach ($revenueByChannel as $channel => $revenue) {
            $channelEnum = OrderChannel::tryFrom($channel);
            $labels[] = $channelEnum?->getLabel() ?? ucfirst($channel);
            $data[] = round($revenue, 2);
            $colors[] = $channelColors[$channel] ?? '#6b7280'; // default gray
        }

        return [
            'datasets' => [
                [
                    'label' => __('Revenue (TRY)'),
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
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
