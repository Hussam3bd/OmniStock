<?php

namespace App\Console\Commands\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\LocationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveDuplicateReturnMovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:remove-duplicate-returns {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove return movements for orders that already have cancellation movements (prevents double restoration)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding return movements for orders that already have cancellation movements...');
        $this->newLine();

        // Find orders that have BOTH cancellation and return movements
        $duplicateReturns = DB::table('inventory_movements as cancellation')
            ->select(
                'cancellation.order_id',
                'cancellation.product_variant_id',
                'return_movement.id as return_movement_id',
                'return_movement.quantity as return_quantity',
                'return_movement.reference as return_reference'
            )
            ->join('inventory_movements as return_movement', function ($join) {
                $join->on('cancellation.order_id', '=', 'return_movement.order_id')
                    ->on('cancellation.product_variant_id', '=', 'return_movement.product_variant_id');
            })
            ->where('cancellation.type', InventoryMovementType::Cancellation->value)
            ->where('return_movement.type', InventoryMovementType::Return->value)
            ->get();

        if ($duplicateReturns->isEmpty()) {
            $this->info('✅ No duplicate return movements found');

            return self::SUCCESS;
        }

        $this->warn('Found '.$duplicateReturns->count().' return movements that duplicate cancellation restorations');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();

            foreach ($duplicateReturns as $duplicate) {
                $this->line("Would delete return movement #{$duplicate->return_movement_id}: {$duplicate->return_reference} (qty: {$duplicate->return_quantity})");
            }

            $this->newLine();
            $this->info('Run without --dry-run to delete these movements');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->confirm('Do you want to proceed with deleting these movements?')) {
            $this->warn('❌ Operation cancelled');

            return self::FAILURE;
        }

        $this->info('🗑️  Deleting duplicate return movements...');

        DB::transaction(function () use ($duplicateReturns) {
            // Get affected variants before deletion
            $affectedVariants = $duplicateReturns
                ->pluck('product_variant_id')
                ->unique()
                ->toArray();

            // Delete the return movements
            $returnMovementIds = $duplicateReturns->pluck('return_movement_id')->toArray();
            $deleted = InventoryMovement::whereIn('id', $returnMovementIds)->delete();

            $this->info("✅ Deleted {$deleted} duplicate return movements");
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
