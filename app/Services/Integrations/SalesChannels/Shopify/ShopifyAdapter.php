<?php

namespace App\Services\Integrations\SalesChannels\Shopify;

use App\Enums\Order\OrderChannel;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\Contracts\SalesChannelAdapter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ShopifyAdapter implements SalesChannelAdapter
{
    protected Integration $integration;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    protected function getBaseUrl(): string
    {
        $shopDomain = $this->integration->settings['shop_domain'];

        // Ensure domain has .myshopify.com suffix if not already present
        if (! str_contains($shopDomain, '.myshopify.com')) {
            $shopDomain .= '.myshopify.com';
        }

        $apiVersion = $this->integration->settings['api_version'] ?? '2025-10';

        return "https://{$shopDomain}/admin/api/{$apiVersion}";
    }

    protected function makeRequest(string $method, string $endpoint, array $params = []): \Illuminate\Http\Client\Response
    {
        $url = $this->getBaseUrl().$endpoint;

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->integration->settings['access_token'],
            'Content-Type' => 'application/json',
        ])->{$method}($url, $params);
    }

    public function authenticate(): bool
    {
        try {
            $response = $this->makeRequest('get', '/shop.json');

            return $response->successful();
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_authentication_failed');

            return false;
        }
    }

    public function fetchOrders(?Carbon $since = null): Collection
    {
        $params = [
            'status' => 'any',
            'limit' => 250, // Shopify max is 250
        ];

        if ($since) {
            $params['created_at_min'] = $since->toIso8601String();
        }

        $response = $this->makeRequest('get', '/orders.json', $params);

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                ])
                ->log('shopify_fetch_orders_failed');

            return collect();
        }

        return collect($response->json('orders', []));
    }

    public function fetchOrderWithTransactions(string $orderId): ?array
    {
        $response = $this->makeRequest('get', "/orders/{$orderId}.json");

        if (! $response->successful()) {
            return null;
        }

        $order = $response->json('order');
        $order['transactions'] = $this->fetchOrderTransactions($orderId);

        return $order;
    }

    protected function fetchOrderTransactions(string $orderId): array
    {
        try {
            $response = $this->makeRequest('get', "/orders/{$orderId}/transactions.json");

            if ($response->successful()) {
                return $response->json('transactions', []);
            }
        } catch (\Exception $e) {
            // Log but don't fail the order sync if transactions can't be fetched
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_fetch_transactions_failed');
        }

        return [];
    }

    public function fetchAllOrders(?Carbon $since = null): Collection
    {
        $allOrders = collect();
        $params = [
            'status' => 'any',
            'limit' => 250,
        ];

        if ($since) {
            $params['created_at_min'] = $since->toIso8601String();
        }

        $pageInfo = null;

        do {
            $headers = [];

            if ($pageInfo) {
                // Use Shopify's cursor-based pagination
                $headers['link'] = $pageInfo;
            }

            $response = $this->makeRequest('get', '/orders.json', $params);

            if (! $response->successful()) {
                break;
            }

            $orders = collect($response->json('orders', []));

            if ($orders->isEmpty()) {
                break;
            }

            $allOrders = $allOrders->merge($orders);

            // Check for Link header with pagination info
            $linkHeader = $response->header('Link');
            if ($linkHeader && str_contains($linkHeader, 'rel="next"')) {
                $pageInfo = $linkHeader;
            } else {
                $pageInfo = null;
            }

            // Safety limit
            if ($allOrders->count() > 10000) {
                break;
            }
        } while ($pageInfo);

        return $allOrders;
    }

    public function fetchOrder(string $externalId): ?array
    {
        $response = $this->makeRequest('get', "/orders/{$externalId}.json");

        if (! $response->successful()) {
            return null;
        }

        return $response->json('order');
    }

    public function fetchProducts(int $limit = 250): Collection
    {
        $params = [
            'limit' => min($limit, 250), // Shopify max is 250
        ];

        $response = $this->makeRequest('get', '/products.json', $params);

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                ])
                ->log('shopify_fetch_products_failed');

            return collect();
        }

        return collect($response->json('products', []));
    }

    public function fetchAllProducts(): Collection
    {
        $allProducts = collect();
        $params = [
            'limit' => 250,
        ];

        $pageInfo = null;

        do {
            $response = $this->makeRequest('get', '/products.json', $params);

            if (! $response->successful()) {
                break;
            }

            $products = collect($response->json('products', []));

            if ($products->isEmpty()) {
                break;
            }

            $allProducts = $allProducts->merge($products);

            // Check for Link header with pagination info
            $linkHeader = $response->header('Link');
            if ($linkHeader && str_contains($linkHeader, 'rel="next"')) {
                // Extract page_info from Link header
                if (preg_match('/page_info=([^&>]+)/', $linkHeader, $matches)) {
                    $params['page_info'] = $matches[1];
                } else {
                    $pageInfo = null;
                }
            } else {
                $pageInfo = null;
            }

            // Safety limit
            if ($allProducts->count() > 10000) {
                break;
            }
        } while (isset($params['page_info']));

        return $allProducts;
    }

    public function updateInventory(ProductVariant $variant): bool
    {
        $mapping = $variant->platformMappings()
            ->where('platform', OrderChannel::SHOPIFY->value)
            ->first();

        if (! $mapping || ! isset($mapping->platform_data['inventory_item_id'])) {
            return false;
        }

        $inventoryItemId = $mapping->platform_data['inventory_item_id'];
        $locationId = $this->integration->settings['location_id'] ?? null;

        if (! $locationId) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => 'No location_id configured for Shopify integration',
                ])
                ->log('shopify_update_inventory_failed');

            return false;
        }

        // Update inventory level
        $response = $this->makeRequest('post', '/inventory_levels/set.json', [
            'location_id' => $locationId,
            'inventory_item_id' => $inventoryItemId,
            'available' => $variant->stock_quantity ?? 0,
        ]);

        if ($response->successful()) {
            activity()
                ->performedOn($variant)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'inventory_item_id' => $inventoryItemId,
                    'quantity' => $variant->stock_quantity,
                ])
                ->log('shopify_inventory_updated');
        }

        return $response->successful();
    }

    public function fulfillOrder(Order $order, array $trackingInfo): bool
    {
        $mapping = $order->platformMappings()
            ->where('platform', OrderChannel::SHOPIFY->value)
            ->first();

        if (! $mapping) {
            return false;
        }

        $orderId = $mapping->platform_id;

        // Create fulfillment
        $fulfillmentData = [
            'fulfillment' => [
                'notify_customer' => $trackingInfo['notify_customer'] ?? false,
            ],
        ];

        if (isset($trackingInfo['tracking_number'])) {
            $fulfillmentData['fulfillment']['tracking_info'] = [
                'number' => $trackingInfo['tracking_number'],
                'url' => $trackingInfo['tracking_url'] ?? null,
                'company' => $trackingInfo['tracking_company'] ?? null,
            ];
        }

        $response = $this->makeRequest('post', "/orders/{$orderId}/fulfillments.json", $fulfillmentData);

        if ($response->successful()) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'order_id' => $orderId,
                    'tracking_info' => $trackingInfo,
                ])
                ->log('shopify_order_fulfilled');
        }

        return $response->successful();
    }

    public function fetchOrderRefunds(string $orderId): Collection
    {
        $response = $this->makeRequest('get', "/orders/{$orderId}/refunds.json");

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'order_id' => $orderId,
                    'error' => $response->body(),
                    'status' => $response->status(),
                ])
                ->log('shopify_fetch_refunds_failed');

            return collect();
        }

        return collect($response->json('refunds', []));
    }

    public function fetchRefundsForOrders(Collection $orderIds): Collection
    {
        $allRefunds = collect();

        foreach ($orderIds as $orderId) {
            $refunds = $this->fetchOrderRefunds($orderId);

            foreach ($refunds as $refund) {
                // Add order_id to each refund for mapping
                $refund['order_id'] = $orderId;
                $allRefunds->push($refund);
            }
        }

        return $allRefunds;
    }

    public function syncCustomer(Customer $customer): bool
    {
        // Shopify customer sync can be implemented if needed
        return true;
    }

    public function registerWebhooks(): bool
    {
        $webhookUrl = route('webhook-client-shopify');

        $webhookConfig = $this->integration->config['webhook'] ?? null;

        // Check if webhooks are already registered
        if ($webhookConfig && isset($webhookConfig['topics'])) {
            return $this->updateWebhooks($webhookUrl);
        }

        return $this->createWebhooks($webhookUrl);
    }

    protected function createWebhooks(string $url): bool
    {
        $topics = [
            'orders/create',
            'orders/updated',
            'orders/cancelled',
            'orders/fulfilled',
            'orders/paid',
            'refunds/create',
        ];

        $webhooks = [];
        $success = true;

        foreach ($topics as $topic) {
            $response = $this->makeRequest('post', '/webhooks.json', [
                'webhook' => [
                    'topic' => $topic,
                    'address' => $url,
                    'format' => 'json',
                ],
            ]);

            if ($response->successful()) {
                $webhooks[] = $response->json('webhook');
            } else {
                $success = false;
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'topic' => $topic,
                        'error' => $response->body(),
                        'status' => $response->status(),
                    ])
                    ->log('shopify_webhook_creation_failed');
            }
        }

        if (! empty($webhooks)) {
            $config = $this->integration->config ?? [];
            $config['webhook'] = [
                'topics' => $topics,
                'webhooks' => $webhooks,
                'webhook_url' => $url,
                'created_at' => now()->toIso8601String(),
            ];
            $this->integration->update(['config' => $config]);

            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'topics' => $topics,
                    'webhook_url' => $url,
                ])
                ->log('shopify_webhooks_created');
        }

        return $success;
    }

    protected function updateWebhooks(string $url): bool
    {
        // For Shopify, we'll delete and recreate webhooks
        $this->deleteWebhooks();

        return $this->createWebhooks($url);
    }

    protected function deleteWebhooks(): bool
    {
        $webhookConfig = $this->integration->config['webhook'] ?? null;

        if (! $webhookConfig || ! isset($webhookConfig['webhooks'])) {
            return true;
        }

        foreach ($webhookConfig['webhooks'] as $webhook) {
            if (isset($webhook['id'])) {
                $this->makeRequest('delete', "/webhooks/{$webhook['id']}.json");
            }
        }

        return true;
    }

    public function verifyWebhook(Request $request): bool
    {
        $hmac = $request->header('X-Shopify-Hmac-SHA256');
        $data = $request->getContent();
        $secret = $this->integration->settings['api_secret'] ?? $this->integration->settings['access_token'];

        if (! $hmac || ! $secret) {
            return false;
        }

        $calculatedHmac = base64_encode(hash_hmac('sha256', $data, $secret, true));

        return hash_equals($calculatedHmac, $hmac);
    }

    public function hasWebhook(): bool
    {
        return isset($this->integration->config['webhook']['webhooks']);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->integration->config['webhook'] ?? null;
    }
}
