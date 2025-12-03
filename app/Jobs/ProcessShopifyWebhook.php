<?php

namespace App\Jobs;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\OrderMapper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessShopifyWebhook extends ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $payload = $this->webhookCall->payload;
        $headers = $this->webhookCall->headers ?? [];

        // Get webhook topic from header
        $topic = $headers['x-shopify-topic'][0] ?? $headers['X-Shopify-Topic'][0] ?? null;

        if (! $topic) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No topic found in webhook headers',
                ])
                ->log('shopify_webhook_invalid');

            return;
        }

        // Find integration by shop domain
        $shopDomain = $headers['x-shopify-shop-domain'][0] ?? $headers['X-Shopify-Shop-Domain'][0] ?? null;

        if (! $shopDomain) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No shop domain found in webhook headers',
                ])
                ->log('shopify_webhook_no_shop_domain');

            return;
        }

        // Search for integration with matching shop domain
        $integration = Integration::where('type', IntegrationType::SALES_CHANNEL)
            ->where('provider', IntegrationProvider::SHOPIFY)
            ->where('is_active', true)
            ->get()
            ->first(function ($integration) use ($shopDomain) {
                $configuredDomain = $integration->settings['shop_domain'] ?? null;

                // Remove .myshopify.com suffix for comparison
                $configuredDomain = str_replace('.myshopify.com', '', $configuredDomain);
                $requestDomain = str_replace('.myshopify.com', '', $shopDomain);

                return $configuredDomain === $requestDomain;
            });

        if (! $integration) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No matching Shopify integration found for shop domain',
                    'shop_domain' => $shopDomain,
                ])
                ->log('shopify_webhook_no_integration');

            return;
        }

        try {
            // Route to appropriate handler based on topic
            match (true) {
                str_starts_with($topic, 'orders/') => $this->handleOrderWebhook($integration, $topic, $payload),
                str_starts_with($topic, 'products/') => $this->handleProductWebhook($integration, $topic, $payload),
                default => activity()
                    ->performedOn($integration)
                    ->withProperties([
                        'topic' => $topic,
                        'payload' => $payload,
                    ])
                    ->log('shopify_webhook_unhandled_topic'),
            };
        } catch (\Exception $e) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'topic' => $topic,
                    'payload' => $payload,
                    'trace' => $e->getTraceAsString(),
                ])
                ->log('shopify_webhook_failed');

            throw $e;
        }
    }

    protected function handleOrderWebhook(Integration $integration, string $topic, array $payload): void
    {
        $mapper = app(OrderMapper::class);
        $order = $mapper->mapOrder($payload);

        activity()
            ->performedOn($order)
            ->withProperties([
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'shopify_order_id' => $payload['id'] ?? null,
                'order_number' => $payload['order_number'] ?? null,
                'topic' => $topic,
            ])
            ->log('shopify_webhook_processed');
    }

    protected function handleProductWebhook(Integration $integration, string $topic, array $payload): void
    {
        // Product webhooks can be handled later if needed
        activity()
            ->performedOn($integration)
            ->withProperties([
                'topic' => $topic,
                'shopify_product_id' => $payload['id'] ?? null,
            ])
            ->log('shopify_product_webhook_received');
    }
}
