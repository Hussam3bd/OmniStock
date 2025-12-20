<?php

namespace App\Console\Commands;

use App\Models\Order\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateOrderCosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:recalculate-costs
                            {--order= : Specific order ID to recalculate}
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate product costs for all orders and order items from product variant cost prices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting order cost recalculation...');

        $dryRun = $this->option('dry-run');
        $specificOrderId = $this->option('order');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
        }

        // Get orders to process
        $ordersQuery = Order::query()->with('items.productVariant');

        if ($specificOrderId) {
            $ordersQuery->where('id', $specificOrderId);
        }

        $orders = $ordersQuery->get();

        if ($orders->isEmpty()) {
            $this->error('No orders found to process');

            return Command::FAILURE;
        }

        $this->info("Processing {$orders->count()} orders...");

        $updatedOrderItems = 0;
        $updatedOrders = 0;
        $skippedItems = 0;
        $errors = [];

        $progressBar = $this->output->createProgressBar($orders->count());
        $progressBar->start();

        foreach ($orders as $order) {
            try {
                DB::beginTransaction();

                $orderUpdated = false;
                $orderTotalCost = 0;

                foreach ($order->items as $item) {
                    // Get the product variant cost price
                    $variant = $item->productVariant;

                    if (! $variant) {
                        $skippedItems++;
                        $errors[] = "Order #{$order->order_number} - Item #{$item->id}: Product variant not found";

                        continue;
                    }

                    if (! $variant->cost_price || $variant->cost_price->getAmount() == 0) {
                        $skippedItems++;
                        $errors[] = "Order #{$order->order_number} - SKU {$variant->sku}: Cost price is zero or null";

                        continue;
                    }

                    // Calculate the correct unit cost (get amount in cents from Money object)
                    $correctUnitCost = $variant->cost_price->getAmount();
                    $currentUnitCost = $item->unit_cost?->getAmount() ?? 0;

                    // Update order item if cost is different
                    if ($correctUnitCost != $currentUnitCost) {
                        if (! $dryRun) {
                            $item->update(['unit_cost' => $correctUnitCost]);
                        }

                        $updatedOrderItems++;
                        $orderUpdated = true;

                        if ($this->output->isVerbose()) {
                            $this->line("\n  Item #{$item->id} ({$variant->sku}): ".
                                money($currentUnitCost, 'TRY')->format().' → '.
                                money($correctUnitCost, 'TRY')->format());
                        }
                    }

                    // Add to order total cost
                    $orderTotalCost += $correctUnitCost * $item->quantity;
                }

                // Update order's total_product_cost if changed
                $currentOrderCost = $order->total_product_cost?->getAmount() ?? 0;

                if ($orderTotalCost != $currentOrderCost) {
                    if (! $dryRun) {
                        $order->update(['total_product_cost' => $orderTotalCost]);
                    }

                    $updatedOrders++;

                    if ($this->output->isVerbose()) {
                        $this->line("\nOrder #{$order->order_number}: ".
                            money($currentOrderCost, 'TRY')->format().' → '.
                            money($orderTotalCost, 'TRY')->format());
                    }
                }

                if (! $dryRun) {
                    DB::commit();
                } else {
                    DB::rollBack();
                }
            } catch (\Exception $e) {
                DB::rollBack();
                $errors[] = "Order #{$order->order_number}: {$e->getMessage()}";
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->info('✅ Recalculation completed!');
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Orders processed', number_format($orders->count())],
                ['Orders updated', number_format($updatedOrders)],
                ['Order items updated', number_format($updatedOrderItems)],
                ['Items skipped (no cost)', number_format($skippedItems)],
                ['Errors', count($errors)],
            ]
        );

        // Show errors if any
        if (! empty($errors)) {
            $this->newLine();
            $this->warn('⚠️  Errors encountered:');

            foreach (array_slice($errors, 0, 10) as $error) {
                $this->line("  • $error");
            }

            if (count($errors) > 10) {
                $this->line('  • ... and '.(count($errors) - 10).' more errors');
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('🔍 This was a DRY RUN - no changes were made');
            $this->info('Run without --dry-run to apply changes');
        }

        return Command::SUCCESS;
    }
}
