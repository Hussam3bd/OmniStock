<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ReturnsMapper;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncShopifyReturns implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 2 minutes per refund

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Integration $integration,
        public array $refundData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ReturnsMapper $mapper): void
    {
        // Skip if batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $mapper->mapReturn($this->refundData);

            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'refund_id' => $this->refundData['id'] ?? null,
                    'order_id' => $this->refundData['order_id'] ?? null,
                ])
                ->log('shopify_refund_synced');
        } catch (\Exception $e) {
            // Log error but don't fail the entire batch
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'refund_id' => $this->refundData['id'] ?? null,
                    'order_id' => $this->refundData['order_id'] ?? null,
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_refund_sync_failed');

            // Re-throw to retry
            throw $e;
        }
    }
}
