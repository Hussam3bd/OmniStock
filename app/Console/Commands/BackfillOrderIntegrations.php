<?php

namespace App\Console\Commands;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use Illuminate\Console\Command;

class BackfillOrderIntegrations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:backfill-integrations
                            {--dry-run : Run in dry-run mode without updating database}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill integration_id for existing orders that don\'t have one';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Backfilling integration_id for orders...');

        if ($dryRun) {
            $this->warn('Running in DRY-RUN mode - no changes will be made to the database');
        }

        // Get orders without integration_id
        $ordersWithoutIntegration = Order::whereNull('integration_id')
            ->whereNotNull('channel')
            ->get();

        $totalOrders = $ordersWithoutIntegration->count();

        if ($totalOrders === 0) {
            $this->info('No orders found without integration_id');

            return self::SUCCESS;
        }

        $this->info("Found {$totalOrders} orders without integration_id");

        // Ask for confirmation unless --force is used
        if (! $force && ! $dryRun) {
            if (! $this->confirm('Do you want to proceed with backfilling?')) {
                $this->info('Operation cancelled');

                return self::SUCCESS;
            }
        }

        // Group integrations by provider value for efficient lookup
        $integrationsByProvider = Integration::where('type', IntegrationType::SALES_CHANNEL)
            ->where('is_active', true)
            ->get()
            ->groupBy(fn ($integration) => $integration->provider->value);

        $updated = 0;
        $skipped = 0;
        $failed = 0;

        $this->withProgressBar($ordersWithoutIntegration, function ($order) use ($integrationsByProvider, $dryRun, &$updated, &$skipped, &$failed) {
            try {
                $integration = $this->findIntegrationForOrder($order, $integrationsByProvider);

                if ($integration) {
                    if (! $dryRun) {
                        $order->update(['integration_id' => $integration->id]);
                    }
                    $updated++;
                } else {
                    // Could not determine integration
                    $skipped++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to process order #{$order->id}: {$e->getMessage()}");
                $failed++;
            }
        });

        $this->newLine(2);

        // Summary
        $this->info('Backfill Summary:');
        $this->table(
            ['Status', 'Count'],
            [
                ['Updated', $updated],
                ['Skipped (no integration found)', $skipped],
                ['Failed', $failed],
                ['Total', $totalOrders],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a DRY-RUN. No changes were made to the database.');
            $this->info('Run without --dry-run to apply changes.');
        }

        if ($skipped > 0) {
            $this->warn("Could not determine integration for {$skipped} orders.");
            $this->info('These orders may be from providers with multiple integrations.');
            $this->info('You may need to manually assign integration_id for these orders.');
        }

        return self::SUCCESS;
    }

    /**
     * Find the appropriate integration for an order
     */
    protected function findIntegrationForOrder(Order $order, $integrationsByProvider): ?Integration
    {
        // Map channel to provider
        $provider = match ($order->channel) {
            OrderChannel::SHOPIFY => IntegrationProvider::SHOPIFY,
            OrderChannel::TRENDYOL => IntegrationProvider::TRENDYOL,
            default => null,
        };

        if (! $provider) {
            return null;
        }

        // Get integrations for this provider (use enum value as key)
        $integrations = $integrationsByProvider->get($provider->value);

        if (! $integrations || $integrations->isEmpty()) {
            return null;
        }

        // If there's only one integration for this provider, use it
        if ($integrations->count() === 1) {
            return $integrations->first();
        }

        // Multiple integrations exist - try to match by platform data
        // For Shopify orders, check shop_domain in platform_data
        if ($provider === IntegrationProvider::SHOPIFY) {
            return $this->findShopifyIntegration($order, $integrations);
        }

        // For Trendyol orders, check by API key or supplier ID in platform_data
        if ($provider === IntegrationProvider::TRENDYOL) {
            return $this->findTrendyolIntegration($order, $integrations);
        }

        // Could not determine - return null and let it be skipped
        return null;
    }

    /**
     * Find Shopify integration by matching shop domain
     */
    protected function findShopifyIntegration(Order $order, $integrations): ?Integration
    {
        // Get platform mapping to extract shop domain from platform_data
        $mapping = $order->platformMappings()->first();

        if (! $mapping || ! isset($mapping->platform_data)) {
            return null;
        }

        $platformData = $mapping->platform_data;

        // Try to extract shop domain from various possible locations in platform_data
        $shopDomain = $platformData['shop_domain']
            ?? $platformData['shop']
            ?? $platformData['admin_graphql_api_shop_id']
            ?? null;

        if (! $shopDomain) {
            return null;
        }

        // Normalize shop domain (remove .myshopify.com suffix)
        $shopDomain = str_replace('.myshopify.com', '', $shopDomain);

        // Find matching integration
        return $integrations->first(function ($integration) use ($shopDomain) {
            $configuredDomain = $integration->settings['shop_domain'] ?? null;
            if (! $configuredDomain) {
                return false;
            }

            $configuredDomain = str_replace('.myshopify.com', '', $configuredDomain);

            return $configuredDomain === $shopDomain;
        });
    }

    /**
     * Find Trendyol integration by matching supplier ID or other identifiers
     */
    protected function findTrendyolIntegration(Order $order, $integrations): ?Integration
    {
        // Get platform mapping to extract supplier info from platform_data
        $mapping = $order->platformMappings()->first();

        if (! $mapping || ! isset($mapping->platform_data)) {
            return null;
        }

        $platformData = $mapping->platform_data;

        // Try to extract supplier ID or seller ID
        $supplierId = $platformData['supplierId']
            ?? $platformData['supplier_id']
            ?? $platformData['sellerId']
            ?? null;

        if (! $supplierId) {
            return null;
        }

        // Find matching integration
        return $integrations->first(function ($integration) use ($supplierId) {
            $configuredSupplierId = $integration->settings['supplier_id'] ?? null;

            return $configuredSupplierId && $configuredSupplierId == $supplierId;
        });
    }
}
