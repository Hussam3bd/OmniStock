<?php

namespace App\Console\Commands;

use App\Services\Shipping\ShippingCostSyncService;
use Illuminate\Console\Command;

class SyncShopifyShippingCosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync-shipping-costs
                            {--limit=50 : Maximum number of orders to process}
                            {--all : Process all orders regardless of limit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync shipping costs from BasitKargo for Shopify orders';

    /**
     * Execute the console command.
     */
    public function handle(ShippingCostSyncService $syncService): int
    {
        $this->info('Starting Shopify shipping cost sync from BasitKargo...');

        $limit = $this->option('all') ? 10000 : (int) $this->option('limit');

        $synced = $syncService->syncMultipleOrders($limit);

        if ($synced > 0) {
            $this->info("✓ Successfully synced shipping costs for {$synced} orders");
        } else {
            $this->warn('No orders needed shipping cost sync');
        }

        return self::SUCCESS;
    }
}
