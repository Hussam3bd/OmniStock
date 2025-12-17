<?php

namespace App\Services\Integrations\SalesChannels\Trendyol;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
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

    public function fetchOrders(?Carbon $since = null, int $page = 0, int $size = 200, ?array $statuses = null): Collection
    {
        $params = [
            'page' => $page,
            'size' => $size,
        ];

        if ($since) {
            $params['startDate'] = $since->timestamp * 1000; // Trendyol uses milliseconds
            $params['endDate'] = now()->timestamp * 1000;
        }

        // Add status filter if provided
        if ($statuses && ! empty($statuses)) {
            $params['status'] = implode(',', $statuses);
        }

        $url = "{$this->baseUrl}/{$this->integration->settings['supplier_id']}/orders";

        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->get($url, $params);

        if (! $response->successful()) {
            return collect();
        }

        $data = $response->json();

        return collect([
            'content' => $data['content'] ?? [],
            'totalPages' => $data['totalPages'] ?? 0,
            'totalElements' => $data['totalElements'] ?? 0,
            'page' => $data['page'] ?? 0,
            'size' => $data['size'] ?? 0,
        ]);
    }

    public function fetchAllOrders(?Carbon $since = null, ?array $statuses = null): Collection
    {
        // Default to all statuses if none provided
        if ($statuses === null) {
            $statuses = [
                'Created',
                'Picking',
                'Invoiced',
                'Shipped',
                'Cancelled',
                'Delivered',
                'UnDelivered',
                'Returned',
                'Unsupplied',
                'Awaiting',
                'Unpacked',
                'AtCollectionPoint',
                'Verified',
            ];
        }

        // If no since date, fetch last 12 months in 2-week chunks
        if ($since === null) {
            return $this->fetchOrdersInTwoWeekChunks(Carbon::now()->subMonths(12), now(), $statuses);
        }

        // Always use 2-week chunks to respect Trendyol's 14-day limitation
        return $this->fetchOrdersInTwoWeekChunks($since, now(), $statuses);
    }

    protected function fetchOrdersInTwoWeekChunks(Carbon $startDate, Carbon $endDate, array $statuses): Collection
    {
        $allOrders = collect();

        $currentStart = $startDate->copy();

        while ($currentStart->lt($endDate)) {
            // End at 14 days (2 weeks) from current start
            $currentEnd = $currentStart->copy()->addDays(14);

            // Don't go beyond the requested end date
            if ($currentEnd->gt($endDate)) {
                $currentEnd = $endDate->copy();
            }

            $orders = $this->fetchOrdersForDateRange($currentStart, $statuses, $currentEnd);
            $allOrders = $allOrders->merge($orders);

            // Move to the next 2-week period
            $currentStart = $currentStart->copy()->addDays(14);
        }

        return $allOrders;
    }

    protected function fetchOrdersForDateRange(?Carbon $since, array $statuses, ?Carbon $until = null): Collection
    {
        $allOrders = collect();
        $page = 0;
        $size = 200;

        do {
            // Use until date if provided, otherwise now()
            $endDate = $until ?? now();

            $params = [
                'page' => $page,
                'size' => $size,
                'status' => implode(',', $statuses),
            ];

            if ($since) {
                $params['startDate'] = $since->timestamp * 1000;
                $params['endDate'] = $endDate->timestamp * 1000;
            }

            $url = "{$this->baseUrl}/{$this->integration->settings['supplier_id']}/orders";

            $response = Http::withBasicAuth(
                $this->integration->settings['api_key'],
                $this->integration->settings['api_secret']
            )->get($url, $params);

            if (! $response->successful()) {
                break;
            }

            $data = $response->json();
            $orders = collect($data['content'] ?? []);

            if ($orders->isEmpty()) {
                break;
            }

            $allOrders = $allOrders->merge($orders);

            $page++;

            $totalPages = $data['totalPages'] ?? 0;

            // If we got fewer orders than the page size, we're on the last page
            if ($orders->count() < $size) {
                break;
            }

            // Break if we've reached the last page
            if ($totalPages > 0 && $page >= $totalPages) {
                break;
            }

            // Safety limit
            if ($page > 1000) {
                break;
            }
        } while (true);

        return $allOrders;
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

    public function fetchAllClaims(int $size = 50): Collection
    {
        $allClaims = collect();
        $page = 0;

        do {
            $response = Http::withBasicAuth(
                $this->integration->settings['api_key'],
                $this->integration->settings['api_secret']
            )->get("https://apigw.trendyol.com/integration/order/sellers/{$this->integration->settings['supplier_id']}/claims", [
                'size' => $size,
                'page' => $page,
            ]);

            if (! $response->successful()) {
                break;
            }

            $data = $response->json();
            $claims = collect($data['content'] ?? []);

            if ($claims->isEmpty()) {
                break;
            }

            $allClaims = $allClaims->merge($claims);

            $page++;
            $totalPages = $data['totalPages'] ?? 1;

            // Break if we've fetched all pages
            if ($page >= $totalPages) {
                break;
            }

            // Safety limit
            if ($page > 100) {
                break;
            }
        } while (true);

        return $allClaims;
    }

    public function updateInventory(ProductVariant $variant): bool
    {
        $mapping = $variant->platformMappings()
            ->where('platform', OrderChannel::TRENDYOL->value)
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
            ->where('platform', OrderChannel::TRENDYOL->value)
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
        $webhookUrl = route('webhook-client-trendyol');

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
            ?? $request->header('x-api-key')
            ?? $request->input('apiKey')
            ?? $request->bearerToken();

        return $providedApiKey === $apiKey;
    }

    protected function createWebhook(string $url): bool
    {
        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->post("https://apigw.trendyol.com/integration/webhook/sellers/{$this->integration->settings['supplier_id']}/webhooks", [
            'url' => $url,
            'username' => '',
            'password' => '',
            'authenticationType' => 'API_KEY',
            'apiKey' => $this->integration->settings['api_key'],
            'subscribedStatuses' => [
                'CREATED',
                'PICKING',
                'INVOICED',
                'SHIPPED',
                'CANCELLED',
                'DELIVERED',
                'UNDELIVERED',
                'RETURNED',
                'UNSUPPLIED',
                'AWAITING',
                'UNPACKED',
                'AT_COLLECTION_POINT',
                'VERIFIED',
            ],
        ]);

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                ])
                ->log('trendyol_webhook_creation_failed');

            return false;
        }

        $webhookData = $response->json();
        $webhookData['created_at'] = now()->toIso8601String();
        $webhookData['webhook_url'] = $url;

        $config = $this->integration->config ?? [];
        $config['webhook'] = $webhookData;
        $this->integration->update(['config' => $config]);

        activity()
            ->performedOn($this->integration)
            ->withProperties([
                'webhook_id' => $webhookData['id'] ?? null,
                'webhook_url' => $url,
            ])
            ->log('trendyol_webhook_created');

        return true;
    }

    protected function updateWebhook(string $webhookId, string $url): bool
    {
        $response = Http::withBasicAuth(
            $this->integration->settings['api_key'],
            $this->integration->settings['api_secret']
        )->put("https://apigw.trendyol.com/integration/webhook/sellers/{$this->integration->settings['supplier_id']}/webhooks/{$webhookId}", [
            'url' => $url,
            'username' => '',
            'password' => '',
            'authenticationType' => 'API_KEY',
            'apiKey' => $this->integration->settings['api_key'],
            'subscribedStatuses' => [
                'CREATED',
                'PICKING',
                'INVOICED',
                'SHIPPED',
                'CANCELLED',
                'DELIVERED',
                'UNDELIVERED',
                'RETURNED',
                'UNSUPPLIED',
                'AWAITING',
                'UNPACKED',
                'AT_COLLECTION_POINT',
                'VERIFIED',
            ],
        ]);

        if ($response->successful()) {
            $config = $this->integration->config ?? [];
            $config['webhook']['webhook_url'] = $url;
            $config['webhook']['updated_at'] = now()->toIso8601String();
            $this->integration->update(['config' => $config]);

            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'webhook_id' => $webhookId,
                    'webhook_url' => $url,
                ])
                ->log('trendyol_webhook_updated');
        } else {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                    'webhook_id' => $webhookId,
                ])
                ->log('trendyol_webhook_update_failed');
        }

        return $response->successful();
    }

    public function hasWebhook(): bool
    {
        return isset($this->integration->config['webhook']['id']);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->integration->config['webhook'] ?? null;
    }

    /**
     * Update return (claim) status in Trendyol
     *
     * Trendyol API: https://developers.trendyol.com/docs/marketplace/siparis-iptal-ve-iade-yonetimi/iade-onayla-reddet
     */
    public function updateReturn(OrderReturn $return): bool
    {
        try {
            $supplierId = $this->integration->settings['supplier_id'];

            // Determine action based on status
            $action = match ($return->status) {
                ReturnStatus::Approved => 'approve',
                ReturnStatus::Rejected => 'reject',
                default => null,
            };

            if (! $action) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'status' => $return->status->value,
                        'reason' => 'status_not_actionable_for_trendyol',
                    ])
                    ->log('trendyol_claim_update_skipped');

                return true; // Not an error, just not actionable
            }

            // Trendyol requires claim items to be included
            $claimItemIds = [];
            foreach ($return->items as $returnItem) {
                // Extract Trendyol claim item ID from platform_data
                $externalItemId = $returnItem->external_item_id
                    ?? $returnItem->platform_data['claim_item_id']
                    ?? null;

                if ($externalItemId) {
                    $claimItemIds[] = $externalItemId;
                }
            }

            if (empty($claimItemIds)) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'reason' => 'no_claim_item_ids_found',
                    ])
                    ->log('trendyol_claim_update_failed');

                return false;
            }

            $endpoint = $action === 'approve'
                ? "/claims/approve/{$return->external_return_id}"
                : "/claims/reject/{$return->external_return_id}";

            $payload = ['claimItemIds' => $claimItemIds];

            // Add reject reason if rejecting
            if ($action === 'reject' && $return->internal_note) {
                $payload['rejectReasonDetail'] = $return->internal_note;
            }

            $response = Http::withBasicAuth(
                $this->integration->settings['api_key'],
                $this->integration->settings['api_secret']
            )->put("https://api.trendyol.com/sapigw/suppliers/{$supplierId}{$endpoint}", $payload);

            if ($response->successful()) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'external_return_id' => $return->external_return_id,
                        'action' => $action,
                        'claim_item_ids' => $claimItemIds,
                    ])
                    ->log('trendyol_claim_updated');

                return true;
            }

            activity()
                ->performedOn($return)
                ->withProperties([
                    'external_return_id' => $return->external_return_id,
                    'action' => $action,
                    'status_code' => $response->status(),
                    'error' => $response->json(),
                ])
                ->log('trendyol_claim_update_failed');

            return false;
        } catch (\Exception $e) {
            activity()
                ->performedOn($return)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('trendyol_claim_update_exception');

            return false;
        }
    }

    /**
     * Cancel an order in Trendyol
     *
     * Trendyol API: https://developers.trendyol.com/docs/marketplace/siparis-iptal-ve-iade-yonetimi/siparis-iptal
     */
    public function cancelOrder(Order $order, string $reason): bool
    {
        try {
            if (! $order->external_id) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'reason' => 'no_external_id',
                    ])
                    ->log('trendyol_order_cancel_skipped');

                return false;
            }

            $supplierId = $this->integration->settings['supplier_id'];

            $response = Http::withBasicAuth(
                $this->integration->settings['api_key'],
                $this->integration->settings['api_secret']
            )->put("https://api.trendyol.com/sapigw/suppliers/{$supplierId}/orders/{$order->external_id}/cancel");

            if ($response->successful()) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'external_id' => $order->external_id,
                        'reason' => $reason,
                    ])
                    ->log('trendyol_order_cancelled');

                return true;
            }

            activity()
                ->performedOn($order)
                ->withProperties([
                    'external_id' => $order->external_id,
                    'status_code' => $response->status(),
                    'error' => $response->json(),
                ])
                ->log('trendyol_order_cancel_failed');

            return false;
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('trendyol_order_cancel_exception');

            return false;
        }
    }
}
