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
    protected $description = 'Remove duplicate inventory restorations (keeps return movements, removes cancellations when both exist)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding duplicate restoration movements (return + cancellation)...');
        $this->newLine();

        // Find orders that have BOTH return and cancellation movements
        // Strategy: Keep return movements (real goods), delete cancellation movements (status change)
        $duplicateCancellations = DB::table('inventory_movements as return_movement')
            ->select(
                'return_movement.order_id',
                'return_movement.product_variant_id',
                'cancellation.id as cancellation_movement_id',
                'cancellation.quantity as cancellation_quantity',
                'cancellation.reference as cancellation_reference',
                'cancellation.created_at as cancellation_created_at'
            )
            ->join('inventory_movements as cancellation', function ($join) {
                $join->on('return_movement.order_id', '=', 'cancellation.order_id')
                    ->on('return_movement.product_variant_id', '=', 'cancellation.product_variant_id');
            })
            ->where('return_movement.type', InventoryMovementType::Return->value)
            ->where('cancellation.type', InventoryMovementType::Cancellation->value)
            ->get();

        if ($duplicateCancellations->isEmpty()) {
            $this->info('✅ No duplicate restoration movements found');

            return self::SUCCESS;
        }

        $this->warn('Found '.$duplicateCancellations->count().' cancellation movements that duplicate return restorations');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();

            foreach ($duplicateCancellations as $duplicate) {
                $this->line("Would delete cancellation movement #{$duplicate->cancellation_movement_id}: {$duplicate->cancellation_reference} (created: {$duplicate->cancellation_created_at})");
            }

            $this->newLine();
            $this->info('Run without --dry-run to delete these movements');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->confirm('Do you want to proceed with deleting these movements?')) {
            $this->warn('❌ Operation cancelled');

            return self::FAILURE;
        }

        $this->info('🗑️  Deleting duplicate cancellation movements...');

        DB::transaction(function () use ($duplicateCancellations) {
            // Get affected variants before deletion
            $affectedVariants = $duplicateCancellations
                ->pluck('product_variant_id')
                ->unique()
                ->toArray();

            // Delete the cancellation movements (keep returns)
            $cancellationMovementIds = $duplicateCancellations->pluck('cancellation_movement_id')->toArray();
            $deleted = InventoryMovement::whereIn('id', $cancellationMovementIds)->delete();

            $this->info("✅ Deleted {$deleted} duplicate cancellation movements");
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
