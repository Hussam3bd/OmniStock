<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class OrdersByChannelChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Orders by Channel';

    protected function getData(): array
    {
        $ordersByChannel = Order::select('channel', DB::raw('COUNT(*) as count'))
            ->groupBy('channel')
            ->pluck('count', 'channel')
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

        foreach ($ordersByChannel as $channel => $count) {
            $channelEnum = OrderChannel::tryFrom($channel);
            $labels[] = $channelEnum?->getLabel() ?? ucfirst($channel);
            $data[] = $count;
            $colors[] = $channelColors[$channel] ?? '#6b7280'; // default gray
        }

        return [
            'datasets' => [
                [
                    'label' => __('Orders'),
                    'data' => $data,
                    'backgroundColor' => $colors,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
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
        ];
    }
}
