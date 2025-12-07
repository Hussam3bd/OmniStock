<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Models\User;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\ReturnRequestMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Filament\Notifications\Notification;
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
        public Integration $integration,
        public ?int $userId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ShopifyAdapter $adapter, ReturnRequestMapper $mapper): void
    {
        // Set the integration for the adapter
        $adapter = new ShopifyAdapter($this->integration);

        // Get user for notifications (may be null if dispatched from console/webhook)
        $user = $this->userId ? User::find($this->userId) : null;

        try {
            // Fetch all orders with return requests from Shopify (optimized query with filters)
            $ordersWithReturns = $adapter->fetchReturnRequests();

            $ordersCount = $ordersWithReturns->count();
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
                    'orders_count' => $ordersCount,
                    'synced' => $synced,
                    'failed' => $failed,
                    'total' => $synced + $failed,
                ])
                ->log('shopify_return_requests_sync_completed');

            // Send notification to user based on results (only if user is available)
            if ($user) {
                if ($ordersCount === 0) {
                    // No orders with returns found
                    Notification::make()
                        ->title(__('Return sync completed'))
                        ->body(__('No new return requests found in Shopify. All existing returns are already synced.'))
                        ->info()
                        ->sendToDatabase($user);
                } elseif ($failed === 0) {
                    // All succeeded
                    Notification::make()
                        ->title(__('Return sync completed'))
                        ->body(__('Successfully synced :count return(s) from :orders Shopify order(s)', [
                            'count' => $synced,
                            'orders' => $ordersCount,
                        ]))
                        ->success()
                        ->sendToDatabase($user);
                } else {
                    // Some failures
                    Notification::make()
                        ->title(__('Return sync completed with errors'))
                        ->body(__('Synced :success of :total return(s) from :orders Shopify order(s). :failed failed.', [
                            'success' => $synced,
                            'total' => $synced + $failed,
                            'orders' => $ordersCount,
                            'failed' => $failed,
                        ]))
                        ->warning()
                        ->sendToDatabase($user);
                }
            }
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_return_requests_sync_failed');

            // Send error notification to user (only if user is available)
            if ($user) {
                Notification::make()
                    ->title(__('Return sync failed'))
                    ->body(__('An error occurred during return sync from Shopify: :error', [
                        'error' => $e->getMessage(),
                    ]))
                    ->danger()
                    ->sendToDatabase($user);
            }

            throw $e;
        }
    }
}
