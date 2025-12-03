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
                            {--since= : Start date for sync (Y-m-d format, defaults to 6 months ago)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync shipping costs from BasitKargo for Shopify orders using bulk order filter API';

    /**
     * Execute the console command.
     */
    public function handle(ShippingCostSyncService $syncService): int
    {
        $this->info('Starting Shopify shipping cost sync from BasitKargo...');
        $this->info('Using bulk order filter API to fetch orders month by month');

        $sinceDate = $this->option('since');

        if ($sinceDate) {
            $this->info("Syncing orders since: {$sinceDate}");
        } else {
            $this->info('Syncing orders from last 6 months (default)');
        }

        $this->newLine();

        $result = $syncService->syncFromBasitKargoBulk($sinceDate);

        if (! $result['success']) {
            $this->error('✗ Failed: '.$result['error']);

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Sync completed!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Orders fetched from BasitKargo', $result['total_fetched']],
                ['Orders matched with local database', $result['matched']],
                ['Orders synced with costs', $result['synced']],
            ]
        );

        if ($result['synced'] > 0) {
            $this->info("✓ Successfully synced shipping costs for {$result['synced']} orders");
        } else {
            $this->warn('No orders were synced. They may already have costs or no matches found.');
        }

        return self::SUCCESS;
    }
}
