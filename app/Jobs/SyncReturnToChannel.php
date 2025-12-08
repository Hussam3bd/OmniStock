<?php

namespace App\Jobs;

use App\Models\Order\OrderReturn;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncReturnToChannel implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public OrderReturn $return
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Skip if return doesn't have external ID or channel
        if (! $this->return->external_return_id || ! $this->return->channel) {
            activity()
                ->performedOn($this->return)
                ->withProperties([
                    'reason' => 'missing_external_id_or_channel',
                ])
                ->log('return_sync_skipped');

            return;
        }

        // Get the sales channel integration by channel
        $order = $this->return->order;

        // Try order integration first, then fall back to channel-based lookup
        $integration = $order->integration ?? \App\Models\Integration\Integration::where('provider', $this->return->channel->value)
            ->where('type', 'sales_channel')
            ->first();

        if (! $integration) {
            activity()
                ->performedOn($this->return)
                ->withProperties([
                    'reason' => 'no_integration_found',
                    'channel' => $this->return->channel->value,
                ])
                ->log('return_sync_failed');

            return;
        }

        try {
            // Get adapter from integration
            $adapter = $integration->adapter();

            if (! $adapter) {
                activity()
                    ->performedOn($this->return)
                    ->withProperties([
                        'reason' => 'adapter_not_supported',
                        'channel' => $this->return->channel->value,
                        'provider' => $integration->provider?->value,
                    ])
                    ->log('return_sync_skipped');

                return;
            }

            // Sync return status to channel
            $result = $adapter->updateReturn($this->return);

            if ($result) {
                activity()
                    ->performedOn($this->return)
                    ->withProperties([
                        'channel' => $this->return->channel->value,
                        'external_return_id' => $this->return->external_return_id,
                        'status' => $this->return->status->value,
                    ])
                    ->log('return_synced_to_channel');
            } else {
                activity()
                    ->performedOn($this->return)
                    ->withProperties([
                        'channel' => $this->return->channel->value,
                        'external_return_id' => $this->return->external_return_id,
                        'reason' => 'adapter_returned_false',
                    ])
                    ->log('return_sync_failed');
            }
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->return)
                ->withProperties([
                    'channel' => $this->return->channel->value,
                    'external_return_id' => $this->return->external_return_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ])
                ->log('return_sync_error');

            throw $e; // Re-throw for job retry logic
        }
    }
}
