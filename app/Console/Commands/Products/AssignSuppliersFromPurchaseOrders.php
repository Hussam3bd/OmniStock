<?php

namespace App\Console\Commands\Products;

use App\Models\Product\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignSuppliersFromPurchaseOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:assign-suppliers {--dry-run : Show what would be assigned without actually assigning}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-assign suppliers to products based on purchase order history';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $this->info('🔍 Finding products without suppliers...');
        $this->newLine();

        // Get products without supplier_id
        $productsWithoutSupplier = Product::whereNull('supplier_id')
            ->with('variants')
            ->get();

        if ($productsWithoutSupplier->isEmpty()) {
            $this->info('✅ All products already have suppliers assigned');

            return self::SUCCESS;
        }

        $this->warn('Found '.$productsWithoutSupplier->count().' products without suppliers');
        $this->newLine();

        $assigned = 0;
        $notFound = 0;

        if ($dryRun) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $progressBar = $this->output->createProgressBar($productsWithoutSupplier->count());
        $progressBar->start();

        foreach ($productsWithoutSupplier as $product) {
            $supplierId = $this->findSupplierForProduct($product);

            if ($supplierId) {
                if ($dryRun) {
                    $supplier = DB::table('suppliers')->find($supplierId);
                    $this->newLine();
                    $this->line("Would assign '{$supplier->name}' to product '{$product->title}' (Model: {$product->model_code})");
                } else {
                    $product->update(['supplier_id' => $supplierId]);
                }
                $assigned++;
            } else {
                $notFound++;
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("Would assign suppliers to {$assigned} products");
            $this->warn("{$notFound} products have no purchase history");
            $this->newLine();
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info("✅ Assigned suppliers to {$assigned} products");
            if ($notFound > 0) {
                $this->warn("{$notFound} products have no purchase history and remain unassigned");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Find the most appropriate supplier for a product based on purchase order history
     */
    protected function findSupplierForProduct(Product $product): ?int
    {
        $variantIds = $product->variants->pluck('id')->toArray();

        if (empty($variantIds)) {
            return null;
        }

        // Find the most recent purchase order for any of the product's variants
        $supplierId = DB::table('purchase_order_items')
            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
            ->whereIn('purchase_order_items.product_variant_id', $variantIds)
            ->whereNotNull('purchase_orders.supplier_id')
            ->orderBy('purchase_orders.order_date', 'desc')
            ->value('purchase_orders.supplier_id');

        return $supplierId;
    }
}
