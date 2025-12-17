<?php

namespace App\Console\Commands\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Order\OrderStatus;
use App\Models\Inventory\InventoryMovement;
use App\Models\Order\Order;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;

class ProcessCancelledOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:process-cancellations {--dry-run : Show what would be processed without actually processing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process cancelled orders to restore inventory (for orders that were imported already cancelled)';

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

        $this->info('🔍 Finding cancelled orders without inventory restoration...');
        $this->newLine();

        // Find all cancelled orders that have sale movements but no cancellation movements
        $orders = Order::where('order_status', OrderStatus::CANCELLED->value)
            ->with('items.productVariant')
            ->get()
            ->filter(function ($order) {
                // Check if this order has sale movements
                $hasSaleMovements = InventoryMovement::where('order_id', $order->id)
                    ->where('type', InventoryMovementType::Sale->value)
                    ->exists();

                if (! $hasSaleMovements) {
                    return false;
                }

                // Check if this order already has cancellation movements
                $hasCancellationMovements = InventoryMovement::where('order_id', $order->id)
                    ->where('type', InventoryMovementType::Cancellation->value)
                    ->exists();

                return ! $hasCancellationMovements;
            });

        if ($orders->isEmpty()) {
            $this->info('✅ No cancelled orders need processing - all cancelled orders already have inventory restored');

            return self::SUCCESS;
        }

        $this->warn('Found '.$orders->count().' cancelled orders without inventory restoration');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();

            foreach ($orders as $order) {
                $itemsCount = $order->items->count();
                $this->line("Would process Order #{$order->order_number} - {$itemsCount} items");
            }

            $this->newLine();
            $this->info('Run without --dry-run to process these orders');

            return self::SUCCESS;
        }

        $this->info('📦 Processing cancelled orders...');
        $progressBar = $this->output->createProgressBar($orders->count());
        $progressBar->start();

        $processed = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $this->inventoryService->restoreInventoryForCancellation($order);
                $processed++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to process Order #{$order->order_number}: ".$e->getMessage());
                $failed++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Processed {$processed} cancelled orders successfully");
        if ($failed > 0) {
            $this->warn("⚠️  {$failed} orders failed to process");
        }

        return self::SUCCESS;
    }
}
