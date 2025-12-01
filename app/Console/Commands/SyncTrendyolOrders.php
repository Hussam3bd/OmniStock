<?php

namespace App\Console\Commands;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncTrendyolOrders extends Command
{
    protected $signature = 'trendyol:sync-orders
                            {--integration= : Specific integration ID to sync}
                            {--since= : Sync orders since this date (Y-m-d format)}
                            {--all : Sync all orders without date filter}
                            {--force : Force sync even if integration is inactive}';

    protected $description = 'Sync orders from Trendyol marketplace';

    public function handle(): int
    {
        $integrationId = $this->option('integration');
        $since = $this->option('since');
        $all = $this->option('all');
        $force = $this->option('force');

        $integrations = $this->getIntegrations($integrationId, $force);

        if ($integrations->isEmpty()) {
            $this->error('No active Trendyol integrations found.');

            return self::FAILURE;
        }

        if ($all) {
            $sinceDate = null;
            $this->info('Syncing ALL orders (no date filter)');
        } else {
            $sinceDate = $since ? Carbon::parse($since) : Carbon::now()->subDays(7);
            $this->info("Syncing orders since {$sinceDate->format('Y-m-d H:i:s')}");
        }

        $totalSynced = 0;
        $totalErrors = 0;

        foreach ($integrations as $integration) {
            $this->info("Processing integration: {$integration->name} (ID: {$integration->id})");

            try {
                $result = $this->syncIntegration($integration, $sinceDate);
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
                    ->log('trendyol_sync_failed');
            }
        }

        $this->newLine();
        $this->info("Sync completed: {$totalSynced} orders synced, {$totalErrors} errors");

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

    protected function syncIntegration(Integration $integration, ?Carbon $since): array
    {
        $adapter = new TrendyolAdapter($integration);
        $mapper = app(OrderMapper::class);

        if (! $adapter->authenticate()) {
            throw new \Exception('Authentication failed');
        }

        $this->info('Fetching orders from Trendyol...');
        $orders = $adapter->fetchAllOrders($since);
        $this->info("Found {$orders->count()} orders to sync");

        $synced = 0;
        $errors = 0;

        $this->withProgressBar($orders, function ($trendyolOrder) use ($mapper, $integration, &$synced, &$errors) {
            try {
                $order = $mapper->mapOrder($trendyolOrder);

                activity()
                    ->causedBy(null)
                    ->performedOn($order)
                    ->withProperties([
                        'integration_id' => $integration->id,
                        'trendyol_package_id' => $trendyolOrder['id'],
                        'order_number' => $trendyolOrder['orderNumber'] ?? null,
                    ])
                    ->log('trendyol_order_synced');

                $synced++;
            } catch (\Exception $e) {
                $this->newLine();
                $this->error("Failed to sync order {$trendyolOrder['id']}: {$e->getMessage()}");

                activity()
                    ->causedBy(null)
                    ->performedOn($integration)
                    ->withProperties([
                        'error' => $e->getMessage(),
                        'trendyol_order' => $trendyolOrder,
                    ])
                    ->log('trendyol_order_sync_failed');

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
