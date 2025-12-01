<?php

namespace App\Console\Commands;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\ProductMapper;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Illuminate\Console\Command;

class SyncTrendyolProducts extends Command
{
    protected $signature = 'trendyol:sync-products
                            {--integration= : Specific integration ID to sync}
                            {--approved= : Filter by approval status (true/false)}
                            {--force : Force sync even if integration is inactive}';

    protected $description = 'Sync products from Trendyol marketplace';

    public function handle(): int
    {
        $integrationId = $this->option('integration');
        $approved = $this->option('approved');
        $force = $this->option('force');

        $integrations = $this->getIntegrations($integrationId, $force);

        if ($integrations->isEmpty()) {
            $this->error('No active Trendyol integrations found.');

            return self::FAILURE;
        }

        $this->info('Starting product sync from Trendyol...');

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($integrations as $integration) {
            $this->info("Processing integration: {$integration->name} (ID: {$integration->id})");

            try {
                $result = $this->syncIntegration($integration, $approved);
                $totalSynced += $result['synced'];
                $totalErrors += $result['errors'];
            } catch (\Exception $e) {
                $this->error("Failed to sync integration {$integration->id}: {$e->getMessage()}");
                $totalErrors++;

                activity()
                    ->causedBy(null)
                    ->performedOn($integration)
                    ->withProperties([
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ])
                    ->log('trendyol_product_sync_failed');
            }
        }

        $this->newLine();
        $this->info("Sync completed: {$totalSynced} products synced, {$totalErrors} errors");

        return $totalErrors > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function getIntegrations(?string $integrationId, bool $force): \Illuminate\Support\Collection
    {
        $query = Integration::where('type', 'sales_channel')
            ->where('provider', 'trendyol');

        if ($integrationId) {
            $query->where('id', $integrationId);
        }

        if (! $force) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    protected function syncIntegration(Integration $integration, ?string $approved): array
    {
        $adapter = new TrendyolAdapter($integration);
        $mapper = app(ProductMapper::class);

        if (! $adapter->authenticate()) {
            throw new \Exception('Authentication failed');
        }

        $this->info('Fetching products from Trendyol...');
        $products = $adapter->fetchAllProducts($approved);

        $this->info("Found {$products->count()} products to sync");

        $synced = 0;
        $errors = 0;

        $this->withProgressBar($products, function ($trendyolProduct) use ($mapper, $integration, &$synced, &$errors) {
            try {
                $product = $mapper->mapProduct($trendyolProduct);

                activity()
                    ->causedBy(null)
                    ->performedOn($product)
                    ->withProperties([
                        'integration_id' => $integration->id,
                        'trendyol_product_id' => $trendyolProduct['productMainId'] ?? $trendyolProduct['id'] ?? null,
                    ])
                    ->log('trendyol_product_synced');

                $synced++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to sync product: {$e->getMessage()}");

                activity()
                    ->causedBy(null)
                    ->performedOn($integration)
                    ->withProperties([
                        'error' => $e->getMessage(),
                        'trendyol_product' => $trendyolProduct,
                    ])
                    ->log('trendyol_product_sync_failed');

                $errors++;
            }
        });

        $this->newLine();

        return [
            'synced' => $synced,
            'errors' => $errors,
        ];
    }
}
