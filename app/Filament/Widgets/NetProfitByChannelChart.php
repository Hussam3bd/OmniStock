<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Models\Order\Order;
use Filament\Widgets\ChartWidget;

class NetProfitByChannelChart extends ChartWidget
{
    protected static ?int $sort = 7;

    protected ?string $heading = 'Net Profit by Channel';

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

        // Use the same profit calculation logic as ChannelSalesBreakdown
        $profitData = $this->calculateNetProfitByChannel();

        foreach ($profitData as $channel => $profit) {
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

    protected function calculateNetProfitByChannel(): array
    {
        $channels = OrderChannel::cases();
        $profitData = [];

        foreach ($channels as $channel) {
            $channelValue = $channel->value;

            // Calculate net profit using the same logic as ChannelSalesBreakdown
            $netProfit = $this->calculateChannelProfit($channelValue);

            if ($netProfit !== null) {
                $profitData[$channelValue] = $netProfit;
            }
        }

        return $profitData;
    }

    protected function calculateChannelProfit(string $channelValue): ?float
    {
        // Check if channel has orders
        $hasOrders = Order::where('channel', $channelValue)->exists();
        if (! $hasOrders) {
            return null;
        }

        // Get returns data
        $returnsAmount = \App\Models\Order\OrderReturn::where('channel', $channelValue)
            ->where('status', '!=', \App\Enums\Order\ReturnStatus::Cancelled)
            ->sum('total_refund_amount') / 100;

        $returnShippingCost = \App\Models\Order\OrderReturn::where('channel', $channelValue)
            ->where('status', '!=', \App\Enums\Order\ReturnStatus::Cancelled)
            ->sum('return_shipping_cost') / 100;

        $returnShippingVat = \App\Models\Order\OrderReturn::where('channel', $channelValue)
            ->where('status', '!=', \App\Enums\Order\ReturnStatus::Cancelled)
            ->sum('return_shipping_vat_amount') / 100;

        $totalReturnShippingCost = $returnShippingCost + $returnShippingVat;

        // Calculate based on channel type (Shopify vs Marketplaces)
        if ($channelValue === OrderChannel::SHOPIFY->value) {
            // Shopify: Revenue = collected payments
            $revenue = Order::where('channel', $channelValue)
                ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                ->sum('total_amount') / 100;

            // Shipping costs
            $shippingCost = Order::where('channel', $channelValue)
                ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                ->sum('shipping_cost_excluding_vat') / 100;

            $shippingVat = Order::where('channel', $channelValue)
                ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                ->sum('shipping_vat_amount') / 100;

            $totalShippingCost = $shippingCost + $shippingVat;

            // Payment gateway commission
            $commission = Order::where('channel', $channelValue)
                ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                ->sum('payment_gateway_commission_amount') / 100;

            if ($commission == 0) {
                $commission = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('payment_gateway_fee') / 100;
            }

            // Effective COGS
            $orders = Order::where('channel', $channelValue)
                ->whereIn('payment_status', [\App\Enums\Order\PaymentStatus::PAID, \App\Enums\Order\PaymentStatus::PARTIALLY_PAID])
                ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                ->with('items.returnItems.return')
                ->get();

            $effectiveCogs = $this->calculateEffectiveCogs($orders);

            return $revenue - $effectiveCogs - $totalShippingCost - $commission - $returnsAmount - $totalReturnShippingCost;
        } else {
            // Marketplaces: Revenue = completed orders
            $revenue = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::COMPLETED)
                ->sum('total_amount') / 100;

            // Shipping costs
            $shippingCost = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::COMPLETED)
                ->sum('shipping_cost_excluding_vat') / 100;

            $shippingVat = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::COMPLETED)
                ->sum('shipping_vat_amount') / 100;

            $totalShippingCost = $shippingCost + $shippingVat;

            // Marketplace commission
            $commission = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::COMPLETED)
                ->sum('total_commission') / 100;

            // Effective COGS
            $orders = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::COMPLETED)
                ->with('items.returnItems.return')
                ->get();

            $effectiveCogs = $this->calculateEffectiveCogs($orders);

            return $revenue - $effectiveCogs - $totalShippingCost - $commission - $returnsAmount - $totalReturnShippingCost;
        }
    }

    protected function calculateEffectiveCogs($orders): float
    {
        $totalCogs = 0;

        foreach ($orders as $order) {
            // For rejected orders (COD refused), COGS is 0 (products came back)
            if ($order->order_status === OrderStatus::REJECTED) {
                continue;
            }

            $orderCogs = $order->total_product_cost?->getAmount() ?? 0;
            $returnedCogs = 0;

            foreach ($order->items as $item) {
                if ($item->unit_cost) {
                    foreach ($item->returnItems as $returnItem) {
                        if (in_array($returnItem->return->status, [\App\Enums\Order\ReturnStatus::Approved, \App\Enums\Order\ReturnStatus::Completed])) {
                            $returnedCogs += $item->unit_cost->getAmount() * $returnItem->quantity;
                        }
                    }
                }
            }

            $totalCogs += ($orderCogs - $returnedCogs) / 100;
        }

        return $totalCogs;
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
