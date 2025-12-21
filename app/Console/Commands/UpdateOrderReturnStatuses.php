<?php

namespace App\Console\Commands;

use App\Actions\Order\UpdateOrderReturnStatusAction;
use App\Enums\Order\ReturnStatus;
use App\Models\Order\OrderReturn;
use Illuminate\Console\Command;

class UpdateOrderReturnStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:update-return-statuses
                            {--dry-run : Show what would be changed without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update order return_status and order_status fields based on completed returns';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting order return status update...');

        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
        }

        // Get all returns that are approved or completed
        $returns = OrderReturn::with('order.items')
            ->whereIn('status', [ReturnStatus::Approved, ReturnStatus::Completed])
            ->get();

        if ($returns->isEmpty()) {
            $this->info('No returns found to process');

            return Command::SUCCESS;
        }

        $this->info("Processing {$returns->count()} returns...");

        $updatedOrders = 0;
        $skippedOrders = 0;
        $changes = [];

        $progressBar = $this->output->createProgressBar($returns->count());
        $progressBar->start();

        $action = app(UpdateOrderReturnStatusAction::class);

        foreach ($returns as $return) {
            $order = $return->order;

            if (! $dryRun) {
                // Execute the action
                $result = $action->execute($order);

                if ($result['changed']) {
                    $updatedOrders++;

                    if ($this->output->isVerbose()) {
                        $this->line("\nOrder #{$order->order_number}:");
                        $this->line("  return_status: {$result['before']['return_status']} → {$result['return_status']}");
                        $this->line("  order_status: {$result['before']['order_status']} → {$result['order_status']}");
                    }
                } else {
                    $skippedOrders++;
                }
            } else {
                // Dry-run: execute action but check what would change
                $beforeReturnStatus = $order->return_status;
                $beforeOrderStatus = $order->order_status->value;

                // Simulate the calculation without saving
                $result = $action->execute($order);

                if ($result['changed']) {
                    $updatedOrders++;
                    $changes[] = [
                        'order_number' => $order->order_number,
                        'return_status' => "{$beforeReturnStatus} → {$result['return_status']}",
                        'order_status' => "{$beforeOrderStatus} → {$result['order_status']}",
                    ];

                    // Revert changes since this is dry-run
                    $order->refresh();
                } else {
                    $skippedOrders++;
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display summary
        $this->info('✅ Update completed!');
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Returns processed', number_format($returns->count())],
                ['Orders updated', number_format($updatedOrders)],
                ['Orders skipped (no change)', number_format($skippedOrders)],
            ]
        );

        // Show sample changes in dry-run mode
        if ($dryRun && ! empty($changes)) {
            $this->newLine();
            $this->info('📋 Sample changes (first 10):');
            $this->table(
                ['Order #', 'Return Status Change', 'Order Status Change'],
                array_slice($changes, 0, 10)
            );

            if (count($changes) > 10) {
                $this->line('  ... and '.(count($changes) - 10).' more changes');
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
