<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ReturnRequestMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncShopifyReturnRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300; // 5 minutes

    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Integration $integration
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ShopifyAdapter $adapter, ReturnRequestMapper $mapper): void
    {
        // Set the integration for the adapter
        $adapter = new ShopifyAdapter($this->integration);

        try {
            // Fetch all orders with return requests from Shopify
            $ordersWithReturns = $adapter->fetchReturnRequests();

            $synced = 0;
            $failed = 0;

            foreach ($ordersWithReturns as $orderData) {
                // Each order may have multiple returns
                $returns = $orderData['returns']['nodes'] ?? [];

                foreach ($returns as $returnData) {
                    try {
                        $mapper->mapReturn($returnData);
                        $synced++;
                    } catch (\Exception $e) {
                        $failed++;

                        activity()
                            ->performedOn($this->integration)
                            ->withProperties([
                                'return_id' => $returnData['id'] ?? null,
                                'order_id' => $orderData['id'] ?? null,
                                'error' => $e->getMessage(),
                            ])
                            ->log('shopify_return_request_sync_failed');
                    }
                }
            }

            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'synced' => $synced,
                    'failed' => $failed,
                    'total' => $synced + $failed,
                ])
                ->log('shopify_return_requests_sync_completed');
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_return_requests_sync_failed');

            throw $e;
        }
    }
}
