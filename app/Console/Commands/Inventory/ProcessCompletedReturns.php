<?php

namespace App\Console\Commands\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Order\ReturnStatus;
use App\Models\Inventory\InventoryMovement;
use App\Models\Order\OrderReturn;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;

class ProcessCompletedReturns extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:process-returns {--dry-run : Show what would be processed without actually processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process completed returns to restore inventory (for returns that were imported already completed)';

    public function __construct(
        protected InventoryService $inventoryService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding completed returns without inventory movements...');
        $this->newLine();

        // Find all completed returns that don't have return inventory movements
        $returns = OrderReturn::where('status', ReturnStatus::Completed)
            ->with(['items.orderItem.productVariant', 'order'])
            ->get()
            ->filter(function ($return) {
                // Check if this return already has inventory movements
                $hasReturnMovements = InventoryMovement::where('order_id', $return->order_id)
                    ->where('type', InventoryMovementType::Return->value)
                    ->where('reference', 'LIKE', "%{$return->return_number}%")
                    ->exists();

                if ($hasReturnMovements) {
                    return false;
                }

                // Skip returns for cancelled/rejected orders — stock is already restored
                // via a cancellation movement. Creating a return movement on top would
                // double-count the restoration.
                $hasCancellationMovements = InventoryMovement::where('order_id', $return->order_id)
                    ->where('type', InventoryMovementType::Cancellation->value)
                    ->exists();

                return ! $hasCancellationMovements;
            });

        if ($returns->isEmpty()) {
            $this->info('✅ No returns need processing - all completed returns already have inventory movements');

            return self::SUCCESS;
        }

        $this->warn('Found '.$returns->count().' completed returns without inventory movements');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();

            foreach ($returns as $return) {
                $itemsCount = $return->items->count();
                $orderNumber = $return->order->order_number ?? $return->order_id;
                $this->line("Would process Return #{$return->return_number} (Order #{$orderNumber}) - {$itemsCount} items");
            }

            $this->newLine();
            $this->info('Run without --dry-run to process these returns');

            return self::SUCCESS;
        }

        $this->info('📦 Processing returns...');
        $progressBar = $this->output->createProgressBar($returns->count());
        $progressBar->start();

        $processed = 0;
        $failed = 0;

        foreach ($returns as $return) {
            try {
                $this->inventoryService->restoreInventoryForReturn($return);
                $processed++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to process Return #{$return->return_number}: ".$e->getMessage());
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Processed {$processed} returns successfully");
        if ($failed > 0) {
            $this->warn("⚠️  {$failed} returns failed to process");
        }

        return self::SUCCESS;
    }
}
