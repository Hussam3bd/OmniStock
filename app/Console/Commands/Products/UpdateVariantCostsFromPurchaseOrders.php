<?php

namespace App\Console\Commands\Products;

use App\Models\Product\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateVariantCostsFromPurchaseOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:update-variant-costs {--dry-run : Show what would be updated without actually updating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update product variant costs based on the latest purchase order prices with currency conversion';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding variants with purchase order history...');
        $this->newLine();

        // Get all variants that have purchase order items
        $variantsWithPurchases = DB::table('product_variants')
            ->select('product_variants.id')
            ->join('purchase_order_items', 'purchase_order_items.product_variant_id', '=', 'product_variants.id')
            ->distinct()
            ->pluck('id');

        if ($variantsWithPurchases->isEmpty()) {
            $this->warn('No variants found with purchase order history');

            return self::SUCCESS;
        }

        $this->info('Found '.$variantsWithPurchases->count().' variants with purchase orders');
        $this->newLine();

        // Get default currency
        $defaultCurrency = DB::table('currencies')->where('is_default', 1)->first();

        if (! $defaultCurrency) {
            $this->error('❌ No default currency found. Please set a default currency first.');

            return self::FAILURE;
        }

        $this->info("Default currency: {$defaultCurrency->code} ({$defaultCurrency->name})");
        $this->newLine();

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $updated = 0;
        $noChange = 0;
        $errors = 0;

        $progressBar = $this->output->createProgressBar($variantsWithPurchases->count());
        $progressBar->start();

        foreach ($variantsWithPurchases as $variantId) {
            try {
                $result = $this->updateVariantCost($variantId, $defaultCurrency, $dryRun);

                if ($result['updated']) {
                    $updated++;
                    if ($dryRun) {
                        $this->newLine();
                        $this->line($result['message']);
                    }
                } else {
                    $noChange++;
                }
            } catch (\Exception $e) {
                $errors++;
                $this->newLine();
                $this->error("Error updating variant ID {$variantId}: ".$e->getMessage());
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Would update {$updated} variants");
            $this->info("{$noChange} variants already have the correct cost");
            if ($errors > 0) {
                $this->warn("{$errors} variants had errors");
            }
            $this->newLine();
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info("✅ Updated {$updated} variants");
            $this->info("{$noChange} variants already had the correct cost");
            if ($errors > 0) {
                $this->warn("{$errors} variants had errors");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Update cost for a single variant based on latest purchase order
     */
    protected function updateVariantCost(int $variantId, object $defaultCurrency, bool $dryRun): array
    {
        $variant = ProductVariant::find($variantId);

        if (! $variant) {
            throw new \Exception('Variant not found');
        }

        // Find the most recent purchase order item for this variant
        $latestPurchase = DB::table('purchase_order_items')
            ->select(
                'purchase_order_items.unit_cost',
                'purchase_orders.currency_id',
                'purchase_orders.currency_code',
                'purchase_orders.exchange_rate',
                'purchase_orders.order_number',
                'purchase_orders.order_date',
                'currencies.is_default'
            )
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->join('currencies', 'purchase_orders.currency_id', '=', 'currencies.id')
            ->where('purchase_order_items.product_variant_id', $variantId)
            ->orderBy('purchase_orders.order_date', 'desc')
            ->orderBy('purchase_orders.id', 'desc')
            ->first();

        if (! $latestPurchase) {
            throw new \Exception('No purchase order found');
        }

        // Calculate the cost in default currency
        $costInDefaultCurrency = $latestPurchase->unit_cost;

        // If purchase order is in a different currency, convert it and round up
        if (! $latestPurchase->is_default) {
            // Convert to default currency cents, then to currency units, ceil, back to cents
            $costInDefaultCurrency = (int) (ceil($latestPurchase->unit_cost * $latestPurchase->exchange_rate / 100) * 100);
        } else {
            // Even if in default currency, round up to remove any decimals
            $costInDefaultCurrency = (int) (ceil($latestPurchase->unit_cost / 100) * 100);
        }

        // Get the current cost price as integer (Money object has getAmount() method)
        $currentCostPrice = $variant->cost_price ? $variant->cost_price->getAmount() : 0;

        // Check if cost needs updating
        if ($currentCostPrice === $costInDefaultCurrency) {
            return [
                'updated' => false,
                'message' => '',
            ];
        }

        $oldCost = $currentCostPrice ? number_format($currentCostPrice / 100, 2) : '0.00';
        $newCost = number_format($costInDefaultCurrency / 100, 2);

        $message = "Variant: {$variant->sku} | Old: {$oldCost} {$defaultCurrency->code} | New: {$newCost} {$defaultCurrency->code} | From PO: {$latestPurchase->order_number} ({$latestPurchase->currency_code})";

        if (! $dryRun) {
            $variant->update(['cost_price' => $costInDefaultCurrency]);
        }

        return [
            'updated' => true,
            'message' => $message,
        ];
    }
}
