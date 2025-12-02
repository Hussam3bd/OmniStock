<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\ClaimsMapper;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncTrendyolClaims implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 2 minutes per claim

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Integration $integration,
        public array $claimData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ClaimsMapper $mapper): void
    {
        // Skip if batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $mapper->mapReturn($this->claimData);

            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'claim_id' => $this->claimData['id'] ?? null,
                    'order_number' => $this->claimData['orderNumber'] ?? null,
                ])
                ->log('trendyol_claim_synced');
        } catch (\Exception $e) {
            // Log error but don't fail the entire batch
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'claim_id' => $this->claimData['id'] ?? null,
                    'order_number' => $this->claimData['orderNumber'] ?? null,
                    'error' => $e->getMessage(),
                ])
                ->log('trendyol_claim_sync_failed');

            // Re-throw to retry
            throw $e;
        }
    }
}
