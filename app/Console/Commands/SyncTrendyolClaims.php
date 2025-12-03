<?php

namespace App\Console\Commands;

use App\Enums\Integration\IntegrationProvider;
use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\ClaimsMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncTrendyolClaims extends Command
{
    protected $signature = 'trendyol:sync-claims
                            {--page=0 : Starting page number}
                            {--size=50 : Page size}';

    protected $description = 'Sync Trendyol return claims to the returns system';

    public function handle(ClaimsMapper $mapper): int
    {
        $integration = Integration::where('provider', IntegrationProvider::TRENDYOL)->first();

        if (! $integration) {
            $this->error('No Trendyol integration found');

            return self::FAILURE;
        }

        $page = (int) $this->option('page');
        $size = (int) $this->option('size');

        $this->info("Fetching Trendyol claims (page: {$page}, size: {$size})");

        // Fetch all claims with pagination
        $allClaims = $this->fetchAllClaims($integration, $size);

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

    protected function fetchAllClaims(Integration $integration, int $size): \Illuminate\Support\Collection
    {
        $allClaims = collect();
        $page = 0;

        do {
            $response = Http::withBasicAuth(
                $integration->settings['api_key'],
                $integration->settings['api_secret']
            )->get("https://apigw.trendyol.com/integration/order/sellers/{$integration->settings['supplier_id']}/claims", [
                'size' => $size,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                $this->error("Failed to fetch claims: {$response->body()}");
                break;
            }

            $data = $response->json();
            $claims = collect($data['content'] ?? []);

            if ($claims->isEmpty()) {
                break;
            }

            $allClaims = $allClaims->merge($claims);

            $this->line("Fetched page {$page}: {$claims->count()} claims");

            $page++;
            $totalPages = $data['totalPages'] ?? 1;

            // Break if we've fetched all pages
            if ($page >= $totalPages) {
                break;
            }

            // Safety limit
            if ($page > 100) {
                $this->warn('Reached page limit (100)');
                break;
            }
        } while (true);

        return $allClaims;
    }
}
