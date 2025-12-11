<?php

namespace App\Jobs;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Shipping\ShippingDataSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncShippingData implements ShouldQueue
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
     * Reuses ShippingDataSyncService (same as ResyncShippingCostAction)
     * Syncs shipping costs, carrier info, desi from BasitKargo
     */
    public function handle(ShippingDataSyncService $service): void
    {
        // Skip if no tracking number
        if (! $this->order->shipping_tracking_number) {
            return;
        }

        // Get active BasitKargo integration (same as ResyncShippingCostAction)
        $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return;
        }

        // Sync shipping data - updates costs, carrier, desi, auto-creates return if needed
        // (same logic as ResyncShippingCostAction)
        $service->syncShippingData($this->order, $integration);
    }
}
