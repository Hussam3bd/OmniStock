<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ShopifyRefundMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchAndSyncShopifyRefunds implements ShouldQueue
{
    use Batchable, Queueable;

    public int $timeout = 180; // 3 minutes per order

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Integration $integration,
        public string $shopifyOrderId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ShopifyRefundMapper $mapper): void
    {
        // Skip if batch has been cancelled
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $adapter = new ShopifyAdapter($this->integration);

            // Fetch refunds for this specific order
            $refunds = $adapter->fetchOrderRefunds($this->shopifyOrderId);

            if (empty($refunds)) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'shopify_order_id' => $this->shopifyOrderId,
                    ])
                    ->log('shopify_order_no_refunds');

                return;
            }

            // Sync each refund
            foreach ($refunds as $refund) {
                // Add order_id to refund data for mapping
                $refund['order_id'] = $this->shopifyOrderId;

                try {
                    $mapper->mapReturn($refund);

                    activity()
                        ->performedOn($this->integration)
                        ->withProperties([
                            'refund_id' => $refund['id'] ?? null,
                            'order_id' => $this->shopifyOrderId,
                        ])
                        ->log('shopify_refund_synced');
                } catch (\Exception $e) {
                    // Log error for this specific refund but continue with others
                    activity()
                        ->performedOn($this->integration)
                        ->withProperties([
                            'refund_id' => $refund['id'] ?? null,
                            'order_id' => $this->shopifyOrderId,
                            'error' => $e->getMessage(),
                        ])
                        ->log('shopify_refund_sync_failed');

                    // Don't throw - continue syncing other refunds
                }
            }
        } catch (\Exception $e) {
            // Log error for the entire order
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'shopify_order_id' => $this->shopifyOrderId,
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_order_refunds_fetch_failed');

            // Re-throw to mark job as failed
            throw $e;
        }
    }
}
