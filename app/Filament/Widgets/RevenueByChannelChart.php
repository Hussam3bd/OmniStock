<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;

class RevenueByChannelChart extends ChartWidget
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Revenue by Channel';

    protected function getData(): array
    {
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

        // Calculate revenue using the same logic as ChannelSalesBreakdown
        $channels = OrderChannel::cases();

        foreach ($channels as $channel) {
            $channelValue = $channel->value;

            // For Shopify: Sales amount = collected payments (excluding cancelled/rejected)
            // For marketplaces: Sales amount = completed orders
            if ($channelValue === OrderChannel::SHOPIFY->value) {
                $revenue = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('total_amount') / 100;
            } else {
                $revenue = Order::where('channel', $channelValue)
                    ->where('order_status', OrderStatus::COMPLETED)
                    ->sum('total_amount') / 100;
            }

            // Only add channels with revenue
            if ($revenue > 0) {
                $labels[] = $channel->getLabel();
                $data[] = round($revenue, 2);
                $colors[] = $channelColors[$channelValue] ?? '#6b7280';
            }
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
