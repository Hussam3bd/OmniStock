<?php

namespace App\Services\Integrations\SalesChannels;

use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\Contracts\SalesChannelAdapter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class TrendyolAdapter implements SalesChannelAdapter
{
    protected Integration $integration;

    protected string $baseUrl = 'https://api.trendyol.com/sapigw/suppliers';

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    public function authenticate(): bool
    {
        try {
            $response = Http::withBasicAuth(
                $this->integration->settings['api_key'],
                $this->integration->settings['api_secret']
            )->get("{$this->baseUrl}/{$this->integration->settings['supplier_id']}/orders");

            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function fetchOrders(?Carbon $since = null): Collection
    {
        $params = [
            'page' => 0,
            'size' => 200,
        ];

        if ($since) {
            $params['startDate'] = $since->timestamp * 1000; // Trendyol uses milliseconds
            $params['endDate'] = now()->timestamp * 1000;
        }

        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->get("{$this->baseUrl}/{$this->integration->settings['supplier_id']}/orders", $params);

        if (! $response->successful()) {
            return collect();
        }

        $orders = $response->json('content', []);

        return collect($orders);
    }

    public function fetchProducts(int $page = 0, int $size = 200, ?string $approved = null): Collection
    {
        $params = [
            'page' => $page,
            'size' => $size,
        ];

        if ($approved !== null) {
            $params['approved'] = $approved;
        }

        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->get("https://apigw.trendyol.com/integration/product/sellers/{$this->integration->settings['supplier_id']}/products", $params);

        if (! $response->successful()) {
            return collect();
        }

        return collect($response->json('content', []));
    }

    public function fetchAllProducts(?string $approved = null): Collection
    {
        $allProducts = collect();
        $page = 0;
        $size = 200;

        do {
            $products = $this->fetchProducts($page, $size, $approved);

            if ($products->isEmpty()) {
                break;
            }

            $allProducts = $allProducts->merge($products);
            $page++;

            // Safety limit to prevent infinite loops
            if ($page > 1000) {
                break;
            }
        } while ($products->count() === $size);

        return $allProducts;
    }

    public function fetchOrder(string $externalId): ?array
    {
        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->get("{$this->baseUrl}/{$this->integration->settings['supplier_id']}/orders/{$externalId}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    public function updateInventory(ProductVariant $variant): bool
    {
        $mapping = $variant->platformMappings()
            ->where('platform', 'trendyol')
            ->first();

        if (! $mapping || ! isset($mapping->platform_data['barcode'])) {
            return false;
        }

        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->post("{$this->baseUrl}/{$this->integration->settings['supplier_id']}/products/price-and-inventory", [
            'items' => [
                [
                    'barcode' => $mapping->platform_data['barcode'],
                    'quantity' => $variant->stock_quantity ?? 0,
                    'salePrice' => $variant->price ? $variant->price->getAmount() / 100 : 0,
                    'listPrice' => $variant->compare_at_price ? $variant->compare_at_price->getAmount() / 100 : 0,
                ],
            ],
        ]);

        if ($response->successful()) {
            activity()
                ->performedOn($variant)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'barcode' => $mapping->platform_data['barcode'],
                    'quantity' => $variant->stock_quantity,
                ])
                ->log('trendyol_inventory_updated');
        }

        return $response->successful();
    }

    public function fulfillOrder(Order $order, array $trackingInfo): bool
    {
        $mapping = $order->platformMappings()
            ->where('platform', 'trendyol')
            ->first();

        if (! $mapping) {
            return false;
        }

        $packageId = $mapping->platform_id;

        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->put("{$this->baseUrl}/{$this->integration->settings['supplier_id']}/shipment-packages/{$packageId}/update-tracking-number", [
            'trackingNumber' => $trackingInfo['tracking_number'] ?? '',
            'invoiceNumber' => $trackingInfo['invoice_number'] ?? $order->invoice_number ?? '',
            'invoiceDate' => $trackingInfo['invoice_date'] ?? $order->invoice_date?->timestamp * 1000 ?? now()->timestamp * 1000,
        ]);

        if ($response->successful()) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'package_id' => $packageId,
                    'tracking_info' => $trackingInfo,
                ])
                ->log('trendyol_order_fulfilled');
        }

        return $response->successful();
    }

    public function syncCustomer(Customer $customer): bool
    {
        // Trendyol doesn't support customer sync
        return true;
    }

    public function registerWebhooks(): bool
    {
        $webhookUrl = route('webhooks.trendyol');

        $webhookConfig = $this->integration->config['webhook'] ?? null;

        if ($webhookConfig && isset($webhookConfig['id'])) {
            return $this->updateWebhook($webhookConfig['id'], $webhookUrl);
        }

        return $this->createWebhook($webhookUrl);
    }

    public function verifyWebhook(Request $request): bool
    {
        $apiKey = $this->integration->settings['api_key'] ?? null;

        if (! $apiKey) {
            return false;
        }

        $providedApiKey = $request->header('X-Trendyol-Api-Key')
            ?? $request->input('apiKey')
            ?? $request->bearerToken();

        return $providedApiKey === $apiKey;
    }

    protected function createWebhook(string $url): bool
    {
        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->post("https://stageapigw.trendyol.com/integration/webhook/sellers/{$this->integration->settings['supplier_id']}/webhooks", [
            'url' => $url,
            'username' => '',
            'password' => '',
            'authenticationType' => 'API_KEY',
            'apiKey' => $this->integration->settings['api_key'],
            'subscribedStatuses' => ['CREATED', 'PICKING', 'INVOICED', 'SHIPPED', 'DELIVERED', 'CANCELLED'],
        ]);

        if (! $response->successful()) {
            return false;
        }

        $webhookData = $response->json();

        $config = $this->integration->config ?? [];
        $config['webhook'] = $webhookData;
        $this->integration->update(['config' => $config]);

        return true;
    }

    protected function updateWebhook(string $webhookId, string $url): bool
    {
        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->put("https://stageapigw.trendyol.com/integration/webhook/sellers/{$this->integration->settings['supplier_id']}/webhooks/{$webhookId}", [
            'url' => $url,
            'username' => '',
            'password' => '',
            'authenticationType' => 'API_KEY',
            'apiKey' => $this->integration->settings['api_key'],
            'subscribedStatuses' => ['CREATED', 'PICKING', 'INVOICED', 'SHIPPED', 'DELIVERED', 'CANCELLED'],
        ]);

        return $response->successful();
    }
}
