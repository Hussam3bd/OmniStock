<?php

namespace App\Console\Commands\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\LocationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveCancelledOrderMovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:remove-cancelled-movements {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove inventory movements (sale/cancellation) for orders that are cancelled';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding cancelled orders with inventory movements...');
        $this->newLine();

        // Find cancelled orders that have sale movements (with or without cancellation movements)
        $cancelledOrders = DB::table('orders as o')
            ->select('o.id', 'o.order_number')
            ->where('o.order_status', 'cancelled')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('inventory_movements as im1')
                    ->whereColumn('im1.order_id', 'o.id')
                    ->where('im1.type', InventoryMovementType::Sale->value);
            })
            ->get();

        if ($cancelledOrders->isEmpty()) {
            $this->info('✅ No cancelled orders with movements found');

            return self::SUCCESS;
        }

        $this->warn('Found '.$cancelledOrders->count().' cancelled orders with inventory movements');
        $this->newLine();

        // Count total movements to delete
        $orderIds = $cancelledOrders->pluck('id')->toArray();
        $totalMovements = InventoryMovement::whereIn('order_id', $orderIds)
            ->whereIn('type', [
                InventoryMovementType::Sale->value,
                InventoryMovementType::Cancellation->value,
            ])
            ->count();

        $this->info("Will delete {$totalMovements} movements from {$cancelledOrders->count()} cancelled orders");
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();

            foreach ($cancelledOrders as $order) {
                $movementCount = InventoryMovement::where('order_id', $order->id)
                    ->whereIn('type', [
                        InventoryMovementType::Sale->value,
                        InventoryMovementType::Cancellation->value,
                    ])
                    ->count();
                $this->line("Would delete {$movementCount} movements for Order #{$order->order_number}");
            }

            $this->newLine();
            $this->info('Run without --dry-run to delete these movements');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->confirm('Do you want to proceed with deleting these movements?')) {
            $this->warn('❌ Operation cancelled');

            return self::FAILURE;
        }

        $this->info('🗑️  Deleting movements...');

        DB::transaction(function () use ($orderIds) {
            // Get affected variants before deletion
            $affectedVariants = InventoryMovement::whereIn('order_id', $orderIds)
                ->whereIn('type', [
                    InventoryMovementType::Sale->value,
                    InventoryMovementType::Cancellation->value,
                ])
                ->distinct()
                ->pluck('product_variant_id')
                ->toArray();

            // Delete the movements
            $deleted = InventoryMovement::whereIn('order_id', $orderIds)
                ->whereIn('type', [
                    InventoryMovementType::Sale->value,
                    InventoryMovementType::Cancellation->value,
                ])
                ->delete();

            $this->info("✅ Deleted {$deleted} movements");
            $this->newLine();

            // Recalculate inventory for affected variants
            $this->info('🔄 Recalculating inventory for affected variants...');

            $progressBar = $this->output->createProgressBar(count($affectedVariants));
            $progressBar->start();

            foreach ($affectedVariants as $variantId) {
                $this->recalculateInventoryForVariant($variantId);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        });

        $this->info('✅ Cleanup completed successfully!');
        $this->info('🔍 Run "php artisan inventory:recalculate-history" to fix before/after values');

        return self::SUCCESS;
    }

    /**
     * Recalculate inventory quantity for a variant based on remaining movements
     */
    protected function recalculateInventoryForVariant(int $variantId): void
    {
        // Get all location inventories for this variant
        $locationInventories = LocationInventory::where('product_variant_id', $variantId)->get();

        foreach ($locationInventories as $locationInventory) {
            // Calculate quantity based on remaining movements
            $movements = InventoryMovement::where('product_variant_id', $variantId)
                ->where('location_id', $locationInventory->location_id)
                ->orderBy('created_at')
                ->get();

            $calculatedQuantity = 0;

            foreach ($movements as $movement) {
                $calculatedQuantity += $movement->quantity;
            }

            // Update location inventory
            $locationInventory->update(['quantity' => $calculatedQuantity]);
        }

        // Sync variant's total inventory quantity
        $variant = \App\Models\Product\ProductVariant::find($variantId);
        if ($variant) {
            $variant->syncInventoryQuantity();
        }
    }
}
