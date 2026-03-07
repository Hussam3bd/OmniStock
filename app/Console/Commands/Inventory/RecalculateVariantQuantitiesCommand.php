<?php

namespace App\Console\Commands\Inventory;

use App\Models\Product\ProductVariant;
use Illuminate\Console\Command;

class RecalculateVariantQuantitiesCommand extends Command
{
    protected $signature = 'inventory:recalculate-quantities
                            {--sku= : Recalculate a single SKU only}
                            {--dry-run : Show what would change without saving}';

    protected $description = 'Recalculate all product variant inventory_quantity from location_inventory sums';

    public function handle(): int
    {
        $sku = $this->option('sku');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $query = ProductVariant::query()->with('locationInventories');

        if ($sku) {
            $query->where('sku', $sku);
        }

        $variants = $query->get();

        if ($variants->isEmpty()) {
            $this->warn('No variants found.');

            return Command::SUCCESS;
        }

        $fixed = 0;
        $skipped = 0;

        $this->info("Checking {$variants->count()} variant(s)...");

        $rows = [];

        foreach ($variants as $variant) {
            $correct = $variant->locationInventories->sum('quantity');
            $current = $variant->inventory_quantity;

            if ($current === $correct) {
                $skipped++;

                continue;
            }

            $rows[] = [$variant->sku, $current, $correct, $correct - $current];

            if (! $dryRun) {
                $variant->update(['inventory_quantity' => $correct]);
            }

            $fixed++;
        }

        if (! empty($rows)) {
            $this->table(['SKU', 'Was', 'Now', 'Diff'], $rows);
        }

        $this->newLine();

        if ($dryRun) {
            $this->info("{$fixed} variant(s) would be updated, {$skipped} already correct.");
        } else {
            $this->info("{$fixed} variant(s) updated, {$skipped} already correct.");
        }

        return Command::SUCCESS;
    }
}
