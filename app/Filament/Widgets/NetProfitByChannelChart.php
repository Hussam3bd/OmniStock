<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class NetProfitByChannelChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected ?string $heading = 'Net Profit by Channel';

    protected function getData(): array
    {
        // Calculate net profit: Revenue - Shipping - Commission
        $profitByChannel = Order::select(
            'channel',
            DB::raw('SUM(total_amount - COALESCE(shipping_amount, 0) - COALESCE(total_commission, 0)) / 100 as net_profit')
        )
            ->where('order_status', OrderStatus::COMPLETED)
            ->groupBy('channel')
            ->pluck('net_profit', 'channel')
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

        foreach ($profitByChannel as $channel => $profit) {
            $channelEnum = OrderChannel::tryFrom($channel);
            $labels[] = $channelEnum?->getLabel() ?? ucfirst($channel);
            $data[] = round($profit, 2);
            $colors[] = $channelColors[$channel] ?? '#6b7280'; // default gray
        }

        return [
            'datasets' => [
                [
                    'label' => __('Net Profit (TRY)'),
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
