<?php

namespace App\Jobs;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAddressToShopify implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 60;

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Find Shopify integration
        $integration = Integration::where('type', IntegrationType::SALES_CHANNEL)
            ->where('provider', IntegrationProvider::SHOPIFY)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            activity()
                ->performedOn($this->order)
                ->withProperties([
                    'reason' => 'no_shopify_integration',
                ])
                ->log('shopify_address_sync_job_failed');

            return;
        }

        $adapter = $integration->adapter();
        $success = $adapter->updateOrderAddresses($this->order);

        if (! $success) {
            activity()
                ->performedOn($this->order)
                ->withProperties([
                    'reason' => 'adapter_returned_false',
                ])
                ->log('shopify_address_sync_job_failed');

            // Fail the job so it can be retried
            $this->fail();
        }
    }
}
