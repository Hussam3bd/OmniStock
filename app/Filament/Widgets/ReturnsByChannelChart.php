<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class ReturnsByChannelChart extends ChartWidget
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Returns by Channel';

    protected function getData(): array
    {
        // Exclude cancelled returns
        $returnsByChannel = OrderReturn::select('channel', DB::raw('COUNT(*) as count'))
            ->where('status', '!=', ReturnStatus::Cancelled)
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

        foreach ($returnsByChannel as $channel => $count) {
            $channelEnum = OrderChannel::tryFrom($channel);
            $labels[] = $channelEnum?->getLabel() ?? ucfirst($channel);
            $data[] = $count;
            $colors[] = $channelColors[$channel] ?? '#6b7280'; // default gray
        }

        return [
            'datasets' => [
                [
                    'label' => __('Returns'),
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
                        'stepSize' => 1,
                    ],
                ],
            ],
        ];
    }
}
