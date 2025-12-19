<?php

namespace App\Filament\Widgets;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ChannelSalesBreakdown extends TableWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $sortColumn, ?string $sortDirection): array => $this->getChannelBreakdown($sortColumn, $sortDirection))
            ->heading(__('Channel Sales Breakdown'))
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('channel')
                    ->label(__('Channel'))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('total_sales_count')
                    ->label(__('Sales Count'))
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),

                Tables\Columns\TextColumn::make('total_sales_amount')
                    ->label(__('Sales Amount'))
                    ->money('TRY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('pending_payment')
                    ->label(__('Pending Payment'))
                    ->html()
                    ->state(function (array $record): string {
                        if (! isset($record['pending_payment_count']) || $record['pending_payment_count'] === 0) {
                            return '-';
                        }

                        $count = number_format($record['pending_payment_count']);
                        $amount = '₺'.number_format($record['pending_payment_amount'], 2);

                        return "<div class='space-y-1'>
                            <div class='font-semibold text-warning-600 dark:text-warning-400'>{$amount}</div>
                            <div class='text-sm text-gray-600 dark:text-gray-400'>{$count} orders</div>
                        </div>";
                    })
                    ->sortable(query: fn ($query, $direction) => $query),

                Tables\Columns\TextColumn::make('cancellations')
                    ->label(__('Cancellations'))
                    ->html()
                    ->state(function (array $record): string {
                        $rate = number_format($record['cancellation_rate'], 2);
                        $count = number_format($record['cancelled_count']);
                        $amount = '₺'.number_format($record['cancelled_amount'], 2);

                        return "<div class='space-y-1'>
                            <div class='font-semibold text-danger-600 dark:text-danger-400'>{$rate}%</div>
                            <div class='text-sm text-gray-600 dark:text-gray-400'>{$count} orders</div>
                            <div class='text-sm text-gray-600 dark:text-gray-400'>{$amount}</div>
                        </div>";
                    })
                    ->sortable(query: fn ($query, $direction) => $query),

                Tables\Columns\TextColumn::make('returns')
                    ->label(__('Returns'))
                    ->html()
                    ->state(function (array $record): string {
                        $rate = number_format($record['return_rate'], 2);
                        $count = number_format($record['returns_count']);
                        $amount = '₺'.number_format($record['returns_amount'], 2);

                        return "<div class='space-y-1'>
                            <div class='font-semibold text-warning-600 dark:text-warning-400'>{$rate}%</div>
                            <div class='text-sm text-gray-600 dark:text-gray-400'>{$count} orders</div>
                            <div class='text-sm text-gray-600 dark:text-gray-400'>{$amount}</div>
                        </div>";
                    })
                    ->sortable(query: fn ($query, $direction) => $query),

                Tables\Columns\TextColumn::make('product_cost')
                    ->label(__('Product Cost (COGS)'))
                    ->money('TRY')
                    ->sortable()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label(__('Outbound Shipping'))
                    ->money('TRY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('return_shipping_cost')
                    ->label(__('Return Shipping'))
                    ->money('TRY')
                    ->sortable()
                    ->color('warning'),

                Tables\Columns\TextColumn::make('commission_amount')
                    ->label(__('Commission'))
                    ->money('TRY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('net_profit')
                    ->label(__('Net Profit'))
                    ->money('TRY')
                    ->weight('bold')
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->sortable(),
            ]);
    }

    protected function getChannelBreakdown(?string $sortColumn, ?string $sortDirection): array
    {
        $channels = OrderChannel::cases();
        $breakdown = [];

        foreach ($channels as $channel) {
            $channelValue = $channel->value;

            // Total sales count (all orders for the channel)
            $totalSalesCount = Order::where('channel', $channelValue)->count();

            // For Shopify: Sales amount = collected payments (excluding cancelled/rejected)
            // For marketplaces: Sales amount = completed orders
            if ($channelValue === OrderChannel::SHOPIFY->value) {
                $totalSalesAmount = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('total_amount') / 100;
            } else {
                $totalSalesAmount = Order::where('channel', $channelValue)
                    ->where('order_status', OrderStatus::COMPLETED)
                    ->sum('total_amount') / 100;
            }

            // Cancelled orders
            $cancelledCount = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::CANCELLED)
                ->count();
            $cancelledAmount = Order::where('channel', $channelValue)
                ->where('order_status', OrderStatus::CANCELLED)
                ->sum('total_amount') / 100;

            // Returns (exclude cancelled)
            $returnsCount = OrderReturn::where('channel', $channelValue)
                ->where('status', '!=', ReturnStatus::Cancelled)
                ->count();
            $returnsAmount = OrderReturn::where('channel', $channelValue)
                ->where('status', '!=', ReturnStatus::Cancelled)
                ->sum('total_refund_amount') / 100;

            // Calculate return shipping costs (exclude cancelled returns)
            $returnShippingCost = OrderReturn::where('channel', $channelValue)
                ->where('status', '!=', ReturnStatus::Cancelled)
                ->sum('return_shipping_cost') / 100;

            $returnShippingVat = OrderReturn::where('channel', $channelValue)
                ->where('status', '!=', ReturnStatus::Cancelled)
                ->sum('return_shipping_vat_amount') / 100;

            $totalReturnShippingCost = $returnShippingCost + $returnShippingVat;

            // For Shopify: calculate pending payments
            $pendingPaymentCount = 0;
            $pendingPaymentAmount = 0;
            if ($channelValue === OrderChannel::SHOPIFY->value) {
                $pendingPaymentCount = Order::where('channel', $channelValue)
                    ->where('payment_status', PaymentStatus::PENDING)
                    ->count();
                $pendingPaymentAmount = Order::where('channel', $channelValue)
                    ->where('payment_status', PaymentStatus::PENDING)
                    ->sum('total_amount') / 100;
            }

            // For Shopify: calculate shipping/commission/profit only from collected payments (PAID, PARTIALLY_PAID)
            // Exclude cancelled/rejected orders even if paid (refunded orders)
            // For other channels: use completed orders
            if ($channelValue === OrderChannel::SHOPIFY->value) {
                // Calculate actual shipping costs paid to carriers (not what customer paid)
                $shippingCostExVat = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('shipping_cost_excluding_vat') / 100;

                $shippingVat = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('shipping_vat_amount') / 100;

                $shippingCost = $shippingCostExVat + $shippingVat;

                // For Shopify: use payment gateway fees/commission (Iyzico, Stripe, etc.)
                $commissionAmount = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('payment_gateway_commission_amount') / 100;

                // Fallback to payment_gateway_fee if commission_amount is not set
                if ($commissionAmount == 0) {
                    $commissionAmount = Order::where('channel', $channelValue)
                        ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_PAID])
                        ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                        ->sum('payment_gateway_fee') / 100;
                }

                // Calculate total product cost (COGS) - only for orders that were actually fulfilled
                // Exclude cancelled/rejected orders (COGS never incurred if not shipped)
                $totalProductCost = Order::where('channel', $channelValue)
                    ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PARTIALLY_PAID])
                    ->whereNotIn('order_status', [OrderStatus::CANCELLED, OrderStatus::REJECTED])
                    ->sum('total_product_cost') / 100;

                // Net Profit = Revenue - COGS - Outbound Shipping - Payment Gateway Fees - Refunds - Return Shipping
                // Revenue is already calculated as collected amount ($totalSalesAmount)
                $netProfit = $totalSalesAmount - $totalProductCost - $shippingCost - $commissionAmount - $returnsAmount - $totalReturnShippingCost;
            } else {
                // Calculate actual shipping costs paid to carriers (not what customer paid)
                $shippingCostExVat = Order::where('channel', $channelValue)
                    ->where('order_status', OrderStatus::COMPLETED)
                    ->sum('shipping_cost_excluding_vat') / 100;

                $shippingVat = Order::where('channel', $channelValue)
                    ->where('order_status', OrderStatus::COMPLETED)
                    ->sum('shipping_vat_amount') / 100;

                $shippingCost = $shippingCostExVat + $shippingVat;

                // For marketplaces: use total_commission (marketplace commission)
                $commissionAmount = Order::where('channel', $channelValue)
                    ->where('order_status', OrderStatus::COMPLETED)
                    ->sum('total_commission') / 100;

                // Calculate total product cost (COGS)
                $totalProductCost = Order::where('channel', $channelValue)
                    ->where('order_status', OrderStatus::COMPLETED)
                    ->sum('total_product_cost') / 100;

                // Net Profit = Revenue - COGS - Outbound Shipping - Marketplace Commission - Refunds - Return Shipping
                $netProfit = $totalSalesAmount - $totalProductCost - $shippingCost - $commissionAmount - $returnsAmount - $totalReturnShippingCost;
            }

            // Rates
            $cancellationRate = $totalSalesCount > 0 ? round(($cancelledCount / $totalSalesCount) * 100, 2) : 0;
            $returnRate = $totalSalesCount > 0 ? round(($returnsCount / $totalSalesCount) * 100, 2) : 0;

            // Only add channels with sales
            if ($totalSalesCount > 0) {
                $breakdown[$channelValue] = [
                    'channel' => $channel->getLabel(),
                    'total_sales_count' => $totalSalesCount,
                    'total_sales_amount' => $totalSalesAmount,
                    'pending_payment_count' => $pendingPaymentCount,
                    'pending_payment_amount' => $pendingPaymentAmount,
                    'cancelled_count' => $cancelledCount,
                    'cancelled_amount' => $cancelledAmount,
                    'cancellation_rate' => $cancellationRate,
                    'returns_count' => $returnsCount,
                    'returns_amount' => $returnsAmount,
                    'return_rate' => $returnRate,
                    'product_cost' => $totalProductCost,
                    'shipping_cost' => $shippingCost,
                    'return_shipping_cost' => $totalReturnShippingCost,
                    'commission_amount' => $commissionAmount,
                    'net_profit' => $netProfit,
                ];
            }
        }

        // Apply sorting if specified
        if (filled($sortColumn) && filled($sortDirection)) {
            $breakdown = collect($breakdown)
                ->sortBy($sortColumn, SORT_REGULAR, $sortDirection === 'desc')
                ->toArray();
        }

        return $breakdown;
    }
}
