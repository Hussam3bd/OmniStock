<?php

namespace App\Jobs;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessTrendyolWebhook extends ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        if (! isset($payload['id'])) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'Missing order ID in webhook payload',
                ])
                ->log('trendyol_webhook_invalid');

            return;
        }

        $integration = Integration::where('type', 'sales_channel')
            ->where('provider', 'trendyol')
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No active Trendyol integration found',
                ])
                ->log('trendyol_webhook_no_integration');

            return;
        }

        try {
            $mapper = app(OrderMapper::class);
            $order = $mapper->mapOrder($payload);

            activity()
                ->performedOn($order)
                ->withProperties([
                    'integration_id' => $integration->id,
                    'trendyol_package_id' => $payload['id'],
                    'order_number' => $payload['orderNumber'] ?? null,
                    'status' => $payload['status'] ?? null,
                ])
                ->log('trendyol_webhook_processed');
        } catch (\Exception $e) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                    'trace' => $e->getTraceAsString(),
                ])
                ->log('trendyol_webhook_failed');

            throw $e;
        }
    }
}
