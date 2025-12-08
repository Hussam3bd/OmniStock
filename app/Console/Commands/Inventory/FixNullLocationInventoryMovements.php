<?php

namespace App\Console\Commands\Inventory;

use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use App\Models\Inventory\LocationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixNullLocationInventoryMovements extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:fix-null-locations {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix inventory movements with NULL location_id by assigning them to the default location';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
        }

        // Find movements with NULL location_id
        $movementsWithNullLocation = InventoryMovement::whereNull('location_id')->get();

        if ($movementsWithNullLocation->isEmpty()) {
            $this->info('✅ No inventory movements with NULL location_id found.');

            return self::SUCCESS;
        }

        $this->info("Found {$movementsWithNullLocation->count()} inventory movements with NULL location_id");

        // Get default location
        $defaultLocation = Location::where('is_default', true)->first() ?? Location::first();

        if (! $defaultLocation) {
            $this->error('❌ No location found in the system. Please create a location first.');

            return self::FAILURE;
        }

        $this->info("Using location: {$defaultLocation->name} (ID: {$defaultLocation->id})");

        $progressBar = $this->output->createProgressBar($movementsWithNullLocation->count());
        $progressBar->start();

        $fixed = 0;
        $errors = 0;

        foreach ($movementsWithNullLocation as $movement) {
            try {
                DB::transaction(function () use ($movement, $defaultLocation, $isDryRun, &$fixed) {
                    // Find or create location inventory record
                    $locationInventory = LocationInventory::firstOrCreate(
                        [
                            'location_id' => $defaultLocation->id,
                            'product_variant_id' => $movement->product_variant_id,
                        ],
                        ['quantity' => 0]
                    );

                    if (! $isDryRun) {
                        // Update the movement to have the location_id
                        $movement->update(['location_id' => $defaultLocation->id]);

                        // Update location inventory with the movement quantity
                        // This ensures the location inventory reflects this movement
                        $locationInventory->increment('quantity', $movement->quantity);

                        // Sync the variant's total inventory
                        if ($movement->productVariant) {
                            $movement->productVariant->syncInventoryQuantity();
                        }
                    }

                    $fixed++;
                });

                $progressBar->advance();
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error fixing movement ID {$movement->id}: {$e->getMessage()}");
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($isDryRun) {
            $this->info("✅ Would fix {$fixed} inventory movements");
        } else {
            $this->info("✅ Fixed {$fixed} inventory movements");
        }

        if ($errors > 0) {
            $this->warn("⚠️  {$errors} movements had errors");
        }

        if ($isDryRun) {
            $this->newLine();
            $this->info('To apply changes, run without --dry-run flag:');
            $this->comment('  php artisan inventory:fix-null-locations');
        }

        return self::SUCCESS;
    }
}
