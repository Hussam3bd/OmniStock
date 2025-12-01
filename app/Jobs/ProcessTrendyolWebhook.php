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

        // Find integration by matching API key from webhook headers
        $headers = $this->webhookCall->headers ?? [];
        $providedApiKey = $headers['x-api-key'][0] ?? $headers['X-Api-Key'][0] ?? null;

        if (! $providedApiKey) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No API key found in webhook headers',
                ])
                ->log('trendyol_webhook_no_api_key');

            return;
        }

        // Search for integration with matching API key
        $integration = Integration::where('type', 'sales_channel')
            ->where('provider', 'trendyol')
            ->where('is_active', true)
            ->get()
            ->first(function ($integration) use ($providedApiKey) {
                return ($integration->settings['api_key'] ?? null) === $providedApiKey;
            });

        if (! $integration) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No matching Trendyol integration found for provided API key',
                    'provided_key_prefix' => substr($providedApiKey, 0, 8).'...',
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
                    'integration_name' => $integration->name,
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
