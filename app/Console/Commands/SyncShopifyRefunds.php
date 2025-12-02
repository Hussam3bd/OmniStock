<?php

namespace App\Console\Commands;

use App\Enums\Order\OrderChannel;
use App\Jobs\SyncShopifyReturns;
use App\Models\Integration\Integration;
use App\Models\Platform\PlatformMapping;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class SyncShopifyRefunds extends Command
{
    protected $signature = 'shopify:sync-refunds
                            {--integration= : Specific integration ID to sync}
                            {--order= : Sync refunds for a specific Shopify order ID}';

    protected $description = 'Sync refunds from Shopify to the returns system';

    public function handle(): int
    {
        $integrations = $this->getIntegrations();

        if ($integrations->isEmpty()) {
            $this->error('No active Shopify integrations found.');

            return self::FAILURE;
        }

        $this->info('Starting Shopify refunds sync...');

        foreach ($integrations as $integration) {
            $this->info("Processing integration: {$integration->name}");

            try {
                $this->syncRefundsForIntegration($integration);
            } catch (\Exception $e) {
                $this->error("Failed to sync refunds for {$integration->name}: {$e->getMessage()}");

                continue;
            }
        }

        $this->info('✓ Shopify refunds sync completed!');

        return self::SUCCESS;
    }

    protected function getIntegrations()
    {
        $query = Integration::where('provider', 'shopify')
            ->where('is_active', true);

        if ($integrationId = $this->option('integration')) {
            $query->where('id', $integrationId);
        }

        return $query->get();
    }

    protected function syncRefundsForIntegration(Integration $integration): void
    {
        $adapter = new ShopifyAdapter($integration);

        // Get order IDs to fetch refunds for
        $orderIds = $this->getOrderIds($integration);

        if ($orderIds->isEmpty()) {
            $this->warn("No orders found for integration: {$integration->name}");

            return;
        }

        $this->info("Found {$orderIds->count()} orders to check for refunds");

        // Fetch all refunds for these orders
        $allRefunds = collect();

        $progressBar = $this->output->createProgressBar($orderIds->count());
        $progressBar->start();

        foreach ($orderIds as $orderId) {
            $refunds = $adapter->fetchOrderRefunds($orderId);

            foreach ($refunds as $refund) {
                // Add order_id to refund data for mapping
                $refund['order_id'] = $orderId;
                $allRefunds->push($refund);
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine();

        if ($allRefunds->isEmpty()) {
            $this->info('No refunds found.');

            return;
        }

        $this->info("Found {$allRefunds->count()} refunds to sync");

        // Batch process refunds
        $jobs = $allRefunds->map(function ($refund) use ($integration) {
            return new SyncShopifyReturns($integration, $refund);
        });

        Bus::batch($jobs)
            ->name("Sync Shopify Refunds - {$integration->name}")
            ->allowFailures()
            ->dispatch();

        $this->info("✓ Dispatched {$jobs->count()} refund sync jobs for {$integration->name}");
    }

    protected function getOrderIds(Integration $integration): \Illuminate\Support\Collection
    {
        // If specific order ID provided, use it
        if ($specificOrderId = $this->option('order')) {
            return collect([$specificOrderId]);
        }

        // Get all Shopify order IDs from platform mappings
        return PlatformMapping::where('platform', OrderChannel::SHOPIFY->value)
            ->where('entity_type', \App\Models\Order\Order::class)
            ->pluck('platform_id');
    }
}
