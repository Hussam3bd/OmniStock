<?php

namespace App\Console\Commands\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\LocationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateMovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:cleanup-duplicates {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove duplicate sale inventory movements caused by double processing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding duplicate sale movements...');
        $this->newLine();

        // Find all duplicate sale movements
        // Duplicates are movements with the same order_id, product_variant_id, and type=sale
        $duplicates = DB::select('
            SELECT
                order_id,
                product_variant_id,
                COUNT(*) as movement_count,
                GROUP_CONCAT(id ORDER BY created_at) as movement_ids
            FROM inventory_movements
            WHERE type = ?
            AND order_id IS NOT NULL
            GROUP BY order_id, product_variant_id
            HAVING COUNT(*) > 1
            ORDER BY order_id
        ', [InventoryMovementType::Sale->value]);

        if (empty($duplicates)) {
            $this->info('✅ No duplicate movements found!');

            return self::SUCCESS;
        }

        $this->warn('Found '.count($duplicates).' orders with duplicate movements');
        $this->newLine();

        $totalMovementsToDelete = 0;
        $affectedVariants = [];

        foreach ($duplicates as $duplicate) {
            $movementIds = explode(',', $duplicate->movement_ids);
            $keepId = array_shift($movementIds); // Keep the first movement
            $deleteIds = $movementIds;

            $totalMovementsToDelete += count($deleteIds);
            $affectedVariants[$duplicate->product_variant_id] = true;

            if ($this->output->isVerbose()) {
                $this->line("Order {$duplicate->order_id}, Variant {$duplicate->product_variant_id}: Keeping movement #{$keepId}, deleting ".implode(', ', array_map(fn ($id) => "#$id", $deleteIds)));
            }
        }

        $this->info("Will delete {$totalMovementsToDelete} duplicate movements");
        $this->info('Affected variants: '.count($affectedVariants));
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->info('Run without --dry-run to actually delete duplicates');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->confirm('Do you want to proceed with deleting these duplicate movements?')) {
            $this->warn('❌ Operation cancelled');

            return self::FAILURE;
        }

        $this->info('🗑️  Deleting duplicate movements...');

        DB::transaction(function () use ($duplicates, &$affectedVariants) {
            foreach ($duplicates as $duplicate) {
                $movementIds = explode(',', $duplicate->movement_ids);
                array_shift($movementIds); // Remove first (we keep it)
                $deleteIds = $movementIds;

                // Delete duplicate movements
                InventoryMovement::whereIn('id', $deleteIds)->delete();
            }

            $this->info('✅ Deleted duplicate movements');
            $this->newLine();

            // Recalculate inventory quantities for affected variants
            $this->info('🔄 Recalculating inventory quantities for affected variants...');

            $progressBar = $this->output->createProgressBar(count($affectedVariants));
            $progressBar->start();

            foreach (array_keys($affectedVariants) as $variantId) {
                $this->recalculateInventoryForVariant($variantId);
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);
        });

        $this->info('✅ Cleanup completed successfully!');
        $this->info('🔍 Run "php artisan inventory:verify" to verify the fix');

        return self::SUCCESS;
    }

    /**
     * Recalculate inventory quantity for a variant based on movements
     */
    protected function recalculateInventoryForVariant(int $variantId): void
    {
        // Get all location inventories for this variant
        $locationInventories = LocationInventory::where('product_variant_id', $variantId)->get();

        foreach ($locationInventories as $locationInventory) {
            // Calculate quantity based on movements
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
