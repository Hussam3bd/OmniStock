<?php

namespace App\Services\Product;

use App\Enums\Order\OrderStatus;
use App\Models\Product\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductDemandAnalyzer
{
    /**
     * Analyze product demand and inventory levels to identify reorder candidates
     *
     * @param  int  $daysToAnalyze  Days of sales history to analyze (default: 30)
     * @param  int  $minDaysOfStock  Minimum days of stock before flagging (default: 14)
     * @param  int  $minSales  Minimum total sales to consider (filter out slow movers)
     */
    public function getReorderRecommendations(
        int $daysToAnalyze = 30,
        int $minDaysOfStock = 14,
        int $minSales = 5
    ): Collection {
        // Get variants with their sales data
        $variants = ProductVariant::with('product')
            ->where('inventory_quantity', '>=', 0) // Has inventory tracking
            ->get();

        $recommendations = [];

        foreach ($variants as $variant) {
            $analysis = $this->analyzeVariant($variant, $daysToAnalyze);

            // Skip if no sales or below minimum
            if ($analysis['total_sales'] < $minSales) {
                continue;
            }

            // Skip if enough stock
            if ($analysis['days_of_stock_remaining'] > $minDaysOfStock) {
                continue;
            }

            $recommendations[] = [
                'variant_id' => $variant->id,
                'sku' => $variant->sku,
                'product_name' => $variant->product->name,
                'variant_title' => $variant->title,
                'current_stock' => $variant->inventory_quantity,
                'total_sales_30d' => $analysis['total_sales'],
                'total_sales_7d' => $analysis['sales_7d'],
                'avg_daily_sales' => $analysis['avg_daily_sales'],
                'days_of_stock_remaining' => $analysis['days_of_stock_remaining'],
                'demand_trend' => $analysis['demand_trend'],
                'suggested_order_quantity' => $analysis['suggested_order_quantity'],
                'cost_price' => $variant->cost_price,
                'priority' => $this->calculatePriority($analysis),
            ];
        }

        // Sort by priority (highest first)
        return collect($recommendations)
            ->sortByDesc('priority')
            ->values();
    }

    /**
     * Analyze a single product variant
     */
    public function analyzeVariant(ProductVariant $variant, int $daysToAnalyze = 30): array
    {
        $now = now();

        // Get sales data for different periods
        $sales30d = $this->getSalesForPeriod($variant, $now->copy()->subDays(30), $now);
        $sales14d = $this->getSalesForPeriod($variant, $now->copy()->subDays(14), $now);
        $sales7d = $this->getSalesForPeriod($variant, $now->copy()->subDays(7), $now);

        // Calculate average daily sales (weighted: recent = more important)
        $avgDailySales = $this->calculateWeightedAvgDailySales($sales7d, $sales14d, $sales30d);

        // Calculate days of stock remaining
        $daysOfStockRemaining = $avgDailySales > 0
            ? round($variant->inventory_quantity / $avgDailySales, 1)
            : 999; // Infinite if no sales

        // Determine demand trend
        $demandTrend = $this->calculateDemandTrend($sales7d, $sales14d, $sales30d);

        // Suggest order quantity (30 days of stock based on recent trend)
        $suggestedOrderQuantity = $this->calculateSuggestedOrderQuantity(
            $variant->inventory_quantity,
            $avgDailySales,
            $demandTrend
        );

        return [
            'total_sales' => $sales30d,
            'sales_14d' => $sales14d,
            'sales_7d' => $sales7d,
            'avg_daily_sales' => round($avgDailySales, 2),
            'days_of_stock_remaining' => $daysOfStockRemaining,
            'demand_trend' => $demandTrend,
            'suggested_order_quantity' => $suggestedOrderQuantity,
        ];
    }

    /**
     * Get total sales quantity for a variant in a period
     */
    protected function getSalesForPeriod(ProductVariant $variant, $startDate, $endDate): int
    {
        return DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('order_items.product_variant_id', $variant->id)
            ->whereBetween('orders.order_date', [$startDate, $endDate])
            ->whereNotIn('orders.order_status', [
                OrderStatus::CANCELLED->value,
                OrderStatus::REJECTED->value,
            ])
            ->sum('order_items.quantity');
    }

    /**
     * Calculate weighted average daily sales (recent sales weighted more heavily)
     */
    protected function calculateWeightedAvgDailySales(int $sales7d, int $sales14d, int $sales30d): float
    {
        // Weight recent sales more heavily
        // 7 days: weight 50%, 14 days: weight 30%, 30 days: weight 20%
        $avg7d = $sales7d / 7;
        $avg14d = ($sales14d - $sales7d) / 7; // Days 8-14
        $avg30d = ($sales30d - $sales14d) / 16; // Days 15-30

        return ($avg7d * 0.5) + ($avg14d * 0.3) + ($avg30d * 0.2);
    }

    /**
     * Calculate demand trend (increasing, stable, decreasing)
     */
    protected function calculateDemandTrend(int $sales7d, int $sales14d, int $sales30d): string
    {
        $avg7d = $sales7d / 7;
        $avg14d = ($sales14d - $sales7d) / 7;
        $avg30d = ($sales30d - $sales14d) / 16;

        // Compare recent (7d) to older periods
        $recent = $avg7d;
        $older = ($avg14d + $avg30d) / 2;

        if ($older == 0) {
            return 'new'; // New product
        }

        $change = (($recent - $older) / $older) * 100;

        if ($change > 20) {
            return 'increasing'; // Growing >20%
        } elseif ($change < -20) {
            return 'decreasing'; // Declining >20%
        } else {
            return 'stable'; // Within 20%
        }
    }

    /**
     * Calculate suggested order quantity based on demand
     */
    protected function calculateSuggestedOrderQuantity(
        int $currentStock,
        float $avgDailySales,
        string $demandTrend
    ): int {
        // Base target: 30 days of stock
        $targetDays = 30;

        // Adjust based on trend
        if ($demandTrend === 'increasing') {
            $targetDays = 45; // Order more for growing products
        } elseif ($demandTrend === 'decreasing') {
            $targetDays = 20; // Order less for declining products
        }

        $targetStock = ceil($avgDailySales * $targetDays);
        $orderQuantity = max(0, $targetStock - $currentStock);

        return (int) $orderQuantity;
    }

    /**
     * Calculate priority score (0-100) for reordering
     * Higher = more urgent
     */
    protected function calculatePriority(array $analysis): int
    {
        $priority = 0;

        // Factor 1: Days of stock remaining (max 50 points)
        // 0 days = 50 points, 14 days = 0 points
        if ($analysis['days_of_stock_remaining'] <= 14) {
            $priority += (int) (50 * (1 - ($analysis['days_of_stock_remaining'] / 14)));
        }

        // Factor 2: Sales velocity (max 30 points)
        // Higher sales = higher priority
        $dailySales = $analysis['avg_daily_sales'];
        if ($dailySales >= 10) {
            $priority += 30;
        } elseif ($dailySales >= 5) {
            $priority += 20;
        } elseif ($dailySales >= 2) {
            $priority += 10;
        }

        // Factor 3: Demand trend (max 20 points)
        $priority += match ($analysis['demand_trend']) {
            'increasing' => 20, // High priority for growing products
            'stable' => 10,
            'decreasing' => 0,
            'new' => 15, // Medium-high for new products
            default => 0,
        };

        return min(100, $priority);
    }
}
