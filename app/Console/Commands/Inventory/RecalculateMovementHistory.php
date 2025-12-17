<?php

namespace App\Console\Commands\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\LocationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateMovementHistory extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:recalculate-history {--dry-run : Show what would be changed without actually changing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate before/after values for all inventory movements in chronological order';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Recalculating inventory movement history...');
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        // Get all unique combinations of variant + location that have movements
        $combinations = DB::table('inventory_movements')
            ->select('product_variant_id', 'location_id')
            ->distinct()
            ->get();

        $this->info('Found '.count($combinations).' variant-location combinations to process');
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($combinations));
        $progressBar->start();

        $totalUpdated = 0;
        $totalCorrect = 0;

        foreach ($combinations as $combo) {
            $updated = $this->recalculateForVariantLocation(
                $combo->product_variant_id,
                $combo->location_id,
                $dryRun
            );

            if ($updated > 0) {
                $totalUpdated += $updated;
            } else {
                $totalCorrect++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Would update {$totalUpdated} movements");
            $this->info("{$totalCorrect} variant-locations already have correct values");
            $this->newLine();
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info("✅ Updated {$totalUpdated} movements");
            $this->info("✅ {$totalCorrect} variant-locations already had correct values");
        }

        return self::SUCCESS;
    }

    /**
     * Recalculate movements for a specific variant-location combination
     */
    protected function recalculateForVariantLocation(int $variantId, int $locationId, bool $dryRun): int
    {
        $movements = InventoryMovement::where('product_variant_id', $variantId)
            ->where('location_id', $locationId)
            ->orderBy('created_at')
            ->orderBy('id') // Secondary sort by ID for same timestamp
            ->get();

        $runningQuantity = 0;
        $updatedCount = 0;

        foreach ($movements as $movement) {
            $correctBefore = $runningQuantity;
            $correctAfter = $runningQuantity + $movement->quantity;

            // Check if values need updating
            if ($movement->quantity_before != $correctBefore || $movement->quantity_after != $correctAfter) {
                if (! $dryRun) {
                    $movement->update([
                        'quantity_before' => $correctBefore,
                        'quantity_after' => $correctAfter,
                    ]);
                }
                $updatedCount++;
            }

            $runningQuantity = $correctAfter;
        }

        // Update location inventory to match final calculated quantity
        if (! $dryRun && $updatedCount > 0) {
            LocationInventory::where('product_variant_id', $variantId)
                ->where('location_id', $locationId)
                ->update(['quantity' => $runningQuantity]);

            // Sync variant's total inventory
            $variant = \App\Models\Product\ProductVariant::find($variantId);
            if ($variant) {
                $variant->syncInventoryQuantity();
            }
        }

        return $updatedCount;
    }
}
