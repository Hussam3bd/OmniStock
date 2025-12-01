<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\OrderMapper;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncShopifyOrders implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // 2 minutes per order

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Integration $integration,
        public array $orderData
    ) {}

    /**
     * Execute the job.
     */
    public function handle(OrderMapper $mapper): void
    {
        // Skip if batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $mapper->mapOrder($this->orderData);
        } catch (\Exception $e) {
            // Log error but don't fail the entire batch
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'order_id' => $this->orderData['id'] ?? null,
                    'order_number' => $this->orderData['order_number'] ?? $this->orderData['name'] ?? null,
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_order_sync_failed');

            // Re-throw to retry
            throw $e;
        }
    }
}
