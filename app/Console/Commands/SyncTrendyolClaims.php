<?php

namespace App\Console\Commands;

use App\Enums\Integration\IntegrationProvider;
use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\ClaimsMapper;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Illuminate\Console\Command;

class SyncTrendyolClaims extends Command
{
    protected $signature = 'trendyol:sync-claims
                            {--days=2 : Number of days to look back (0 = fetch all)}
                            {--size=50 : Page size}';

    protected $description = 'Sync Trendyol return claims to the returns system';

    public function handle(ClaimsMapper $mapper): int
    {
        $integration = Integration::where('provider', IntegrationProvider::TRENDYOL)->first();

        if (! $integration) {
            $this->error('No Trendyol integration found');

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $size = (int) $this->option('size');

        $since = $days > 0 ? now()->subDays($days) : null;

        if ($since) {
            $this->info("Fetching Trendyol claims from the last {$days} day(s) (since {$since->format('Y-m-d')})");
        } else {
            $this->info('Fetching all Trendyol claims (no date filter)');
        }

        // Fetch claims with optional date window
        $adapter = new TrendyolAdapter($integration);
        $allClaims = $adapter->fetchAllClaims($since, size: $size);

        $this->info("Found {$allClaims->count()} total claims");

        $synced = 0;
        $errors = 0;

        foreach ($allClaims as $claim) {
            try {
                $return = $mapper->mapReturn($claim);

                if ($return) {
                    $this->line("✓ Synced claim {$claim['id']} → Return {$return->return_number}");
                    $synced++;
                } else {
                    $this->warn("⚠ Order not found for claim {$claim['id']} (Order #{$claim['orderNumber']})");
                }
            } catch (\Exception $e) {
                $this->error("✗ Error syncing claim {$claim['id']}: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->newLine();
        $this->info('Sync complete!');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Claims', $allClaims->count()],
                ['Synced Successfully', $synced],
                ['Errors', $errors],
            ]
        );

        return self::SUCCESS;
    }
}
