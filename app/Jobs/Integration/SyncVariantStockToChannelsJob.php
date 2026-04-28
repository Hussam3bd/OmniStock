<?php

namespace App\Jobs\Integration;

use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncVariantStockToChannelsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public int $variantId
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->variantId))
                ->expireAfter(60)
                ->releaseAfter(10),
        ];
    }

    public function handle(): void
    {
        $variant = ProductVariant::find($this->variantId);

        if (! $variant) {
            return;
        }

        $integrations = Integration::query()
            ->where('type', IntegrationType::SALES_CHANNEL)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Integration $i) => (bool) ($i->settings['auto_sync_stock'] ?? false));

        foreach ($integrations as $integration) {
            $provider = $integration->provider instanceof \BackedEnum
                ? $integration->provider->value
                : (string) $integration->provider;

            $platformKey = match ($provider) {
                'shopify' => OrderChannel::SHOPIFY->value,
                'trendyol' => OrderChannel::TRENDYOL->value,
                default => null,
            };

            if (! $platformKey) {
                continue;
            }

            $hasMapping = $variant->platformMappings()
                ->where('platform', $platformKey)
                ->exists();

            if (! $hasMapping) {
                continue;
            }

            try {
                $adapter = match ($provider) {
                    'shopify' => new ShopifyAdapter($integration),
                    'trendyol' => new TrendyolAdapter($integration),
                    default => null,
                };

                if (! $adapter) {
                    continue;
                }

                $adapter->syncStock(collect([$variant]));
            } catch (\Throwable $e) {
                Log::warning('Auto stock sync failed', [
                    'integration_id' => $integration->id,
                    'provider' => $provider,
                    'variant_id' => $variant->id,
                    'sku' => $variant->sku,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
