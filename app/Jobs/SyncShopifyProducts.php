<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ProductMapper;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncShopifyProducts implements ShouldQueue
{
    use Batchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes per product

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Integration $integration,
        public array $productData,
        public bool $syncImages = false,
        public bool $syncInventory = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ProductMapper $mapper): void
    {
        // Skip if batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $mapper->mapProduct($this->productData, $this->syncImages, $this->syncInventory);
        } catch (\Exception $e) {
            // Log error but don't fail the entire batch
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'product_id' => $this->productData['id'] ?? null,
                    'product_title' => $this->productData['title'] ?? null,
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_product_sync_failed');

            // Re-throw to retry
            throw $e;
        }
    }
}
