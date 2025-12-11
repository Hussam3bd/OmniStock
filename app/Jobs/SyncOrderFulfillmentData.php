<?php

namespace App\Jobs;

use App\Models\Order\Order;
use App\Services\Order\OrderSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncOrderFulfillmentData implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     * Syncs fulfillment data from source channel (Shopify/Trendyol)
     * Then dispatches SyncShippingData job if tracking number is available
     */
    public function handle(OrderSyncService $service): void
    {
        // 1. Sync fulfillment data using OrderSyncService
        $result = $service->syncFulfillmentData($this->order);

        if (! $result['success']) {
            return;
        }

        // 2. Refresh order to get updated tracking number from sync
        $this->order->refresh();

        // 3. If order now has tracking number, dispatch job to sync shipping data from BasitKargo
        if ($this->order->shipping_tracking_number) {
            SyncShippingData::dispatch($this->order);
        }
    }
}
