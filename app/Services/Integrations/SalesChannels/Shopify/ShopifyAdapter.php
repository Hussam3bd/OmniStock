<?php

namespace App\Services\Integrations\SalesChannels\Shopify;

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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ShopifyAdapter implements SalesChannelAdapter
{
    protected Integration $integration;

    /**
     * Shopify API rate limit: 40 requests per second for REST API
     * GraphQL has a cost-based system, but we'll use a conservative limit
     */
    protected int $maxRequestsPerSecond = 30;

    public function __construct(Integration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Rate limit API requests using cache-based token bucket algorithm
     */
    protected function rateLimit(): void
    {
        $cacheKey = "shopify_rate_limit:{$this->integration->id}";
        $now = microtime(true);

        // Get current bucket state
        $bucket = Cache::get($cacheKey, [
            'tokens' => $this->maxRequestsPerSecond,
            'last_refill' => $now,
        ]);

        // Calculate tokens to add based on time elapsed
        $timePassed = $now - $bucket['last_refill'];
        $tokensToAdd = floor($timePassed * $this->maxRequestsPerSecond);

        if ($tokensToAdd > 0) {
            $bucket['tokens'] = min(
                $this->maxRequestsPerSecond,
                $bucket['tokens'] + $tokensToAdd
            );
            $bucket['last_refill'] = $now;
        }

        // If no tokens available, wait
        if ($bucket['tokens'] < 1) {
            $waitTime = (1 - $bucket['tokens']) / $this->maxRequestsPerSecond;
            usleep((int) ($waitTime * 1000000)); // Convert to microseconds

            // Refill after waiting
            $bucket['tokens'] = 1;
            $bucket['last_refill'] = microtime(true);
        }

        // Consume one token
        $bucket['tokens'] -= 1;

        // Store updated bucket state (expire after 2 seconds of inactivity)
        Cache::put($cacheKey, $bucket, 2);
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

    protected function makeRequest(
        string $method,
        string $endpoint,
        array $params = []
    ): \Illuminate\Http\Client\Response {
        // Apply rate limiting before making request
        $this->rateLimit();

        $url = $this->getBaseUrl().$endpoint;

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->integration->settings['access_token'],
            'Content-Type' => 'application/json',
        ])->{$method}($url, $params);
    }

    /**
     * Make a GraphQL request to Shopify
     */
    protected function makeGraphQLRequest(string $query, array $variables = []): \Illuminate\Http\Client\Response
    {
        // Apply rate limiting before making request
        $this->rateLimit();

        $url = $this->getBaseUrl().'/graphql.json';

        $payload = ['query' => $query];

        // Only include variables if they're provided
        if (! empty($variables)) {
            $payload['variables'] = $variables;
        }

        return Http::withHeaders([
            'X-Shopify-Access-Token' => $this->integration->settings['access_token'],
            'Content-Type' => 'application/json',
        ])->post($url, $payload);
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

        $nextPageUrl = null;

        do {
            // Use next page URL if we have it, otherwise use base endpoint
            if ($nextPageUrl) {
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $this->integration->settings['access_token'],
                    'Content-Type' => 'application/json',
                ])->get($nextPageUrl);
            } else {
                $response = $this->makeRequest('get', '/orders.json', $params);
            }

            if (! $response->successful()) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'error' => $response->body(),
                        'status' => $response->status(),
                        'page' => $allOrders->count() / 250,
                    ])
                    ->log('shopify_fetch_orders_page_failed');
                break;
            }

            $orders = collect($response->json('orders', []));

            if ($orders->isEmpty()) {
                break;
            }

            $allOrders = $allOrders->merge($orders);

            // Parse Link header for next page URL
            $linkHeader = $response->header('Link');
            $nextPageUrl = $this->parseNextPageUrl($linkHeader);

            // Safety limit
            if ($allOrders->count() > 10000) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'orders_fetched' => $allOrders->count(),
                    ])
                    ->log('shopify_fetch_orders_safety_limit_reached');
                break;
            }
        } while ($nextPageUrl);

        return $allOrders;
    }

    /**
     * Parse Shopify's Link header to get the next page URL
     */
    protected function parseNextPageUrl(?string $linkHeader): ?string
    {
        if (! $linkHeader) {
            return null;
        }

        // Shopify's Link header format: <https://...page_info=xxx>; rel="next"
        preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches);

        return $matches[1] ?? null;
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

    /**
     * Fetch returns for orders with return requests
     * Uses GraphQL to query only orders that have returns (optimized query filtering)
     * Sorted by processed date (newest first) for better performance
     */
    public function fetchReturnRequests(): Collection
    {
        $query = <<<'GRAPHQL'
        query($cursor: String) {
          orders(
            first: 5
            after: $cursor
            query: "return_status:IN_PROGRESS OR return_status:RETURNED OR return_status:RETURN_REQUESTED OR return_status:RETURN_FAILED"
            sortKey: PROCESSED_AT
            reverse: true
          ) {
            pageInfo {
              hasNextPage
              endCursor
            }
            nodes {
              id
              legacyResourceId
              name
              processedAt
              createdAt
              returnStatus
              returns(first: 3) {
                nodes {
                  id
                  name
                  status
                  createdAt
                  requestApprovedAt
                  closedAt
                  totalQuantity
                  order {
                    id
                    legacyResourceId
                  }
                  returnLineItems(first: 10) {
                    edges {
                      node {
                        ... on ReturnLineItem {
                          id
                          quantity
                          returnReason
                          returnReasonNote
                          customerNote
                          fulfillmentLineItem {
                            lineItem {
                              id
                              sku
                              name
                              variant {
                                id
                                legacyResourceId
                              }
                            }
                          }
                        }
                      }
                    }
                  }
                  reverseFulfillmentOrders(first: 3) {
                    edges {
                      node {
                        id
                        status
                        reverseDeliveries(first: 10) {
                          edges {
                            node {
                              id
                              deliverable {
                                ... on ReverseDeliveryShippingDeliverable {
                                  tracking {
                                    number
                                    url
                                    carrierName
                                  }
                                  label {
                                    publicFileUrl
                                  }
                                }
                              }
                            }
                          }
                        }
                        lineItems(first: 10) {
                          edges {
                            node {
                              id
                              totalQuantity
                              fulfillmentLineItem {
                                lineItem {
                                  id
                                  variant {
                                    id
                                    legacyResourceId
                                  }
                                }
                              }
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

        return $this->fetchPaginatedGraphQLOrders($query);
    }

    /**
     * Fetch return details by return ID
     */
    public function fetchReturnById(string $returnId): ?array
    {
        $query = <<<'GRAPHQL'
        query($id: ID!) {
          return(id: $id) {
            id
            name
            status
            createdAt
            requestApprovedAt
            closedAt
            totalQuantity
            order {
              id
              legacyResourceId
              name
            }
            decline {
              note
              reason
            }
            returnLineItems(first: 50) {
              edges {
                node {
                  ... on ReturnLineItem {
                    id
                    quantity
                    returnReason
                    returnReasonNote
                    customerNote
                    fulfillmentLineItem {
                      lineItem {
                        id
                        name
                        sku
                        quantity
                        variant {
                          id
                          legacyResourceId
                        }
                      }
                    }
                  }
                }
              }
            }
            refunds(first: 10) {
              edges {
                node {
                  id
                  legacyResourceId
                  createdAt
                }
              }
            }
            reverseFulfillmentOrders(first: 10) {
              edges {
                node {
                  id
                  status
                  reverseDeliveries(first: 10) {
                    edges {
                      node {
                        id
                        deliverable {
                          ... on ReverseDeliveryShippingDeliverable {
                            tracking {
                              number
                              url
                              carrierName
                            }
                            label {
                              publicFileUrl
                            }
                          }
                        }
                      }
                    }
                  }
                  lineItems(first: 50) {
                    edges {
                      node {
                        id
                        totalQuantity
                        fulfillmentLineItem {
                          lineItem {
                            id
                            variant {
                              id
                              legacyResourceId
                            }
                          }
                        }
                      }
                    }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;

        $response = $this->makeGraphQLRequest($query, ['id' => $returnId]);

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                    'return_id' => $returnId,
                ])
                ->log('shopify_fetch_return_failed');

            return null;
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'errors' => $data['errors'],
                    'return_id' => $returnId,
                ])
                ->log('shopify_fetch_return_graphql_errors');

            return null;
        }

        return $data['data']['return'] ?? null;
    }

    /**
     * Approve a return request
     * Changes return status from REQUESTED to OPEN
     */
    public function approveReturn(string $returnId): ?array
    {
        $mutation = <<<'GRAPHQL'
        mutation returnApproveRequest($id: ID!) {
          returnApproveRequest(input: {id: $id}) {
            return {
              id
              name
              status
            }
            userErrors {
              code
              field
              message
            }
          }
        }
        GRAPHQL;

        $response = $this->makeGraphQLRequest($mutation, ['id' => $returnId]);

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                    'return_id' => $returnId,
                ])
                ->log('shopify_approve_return_failed');

            return null;
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'errors' => $data['errors'],
                    'return_id' => $returnId,
                ])
                ->log('shopify_approve_return_graphql_errors');

            return null;
        }

        if (! empty($data['data']['returnApproveRequest']['userErrors'])) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'user_errors' => $data['data']['returnApproveRequest']['userErrors'],
                    'return_id' => $returnId,
                ])
                ->log('shopify_approve_return_user_errors');

            return null;
        }

        return $data['data']['returnApproveRequest']['return'] ?? null;
    }

    /**
     * Attach return label and tracking to a reverse delivery
     * Creates a new reverse delivery with shipping information
     *
     * @param  string  $reverseFulfillmentOrderId  The reverse fulfillment order ID
     * @param  array  $lineItems  Array of line items with reverseFulfillmentOrderLineItemId and quantity
     * @param  string|null  $labelUrl  Public URL to the return label file
     * @param  string|null  $trackingNumber  Tracking number for the return shipment
     * @param  string|null  $trackingUrl  URL to track the shipment
     * @param  bool  $notifyCustomer  Whether to notify customer (default: true)
     */
    public function attachReturnLabel(
        string $reverseFulfillmentOrderId,
        array $lineItems,
        ?string $labelUrl = null,
        ?string $trackingNumber = null,
        ?string $trackingUrl = null,
        bool $notifyCustomer = true
    ): ?array {
        // Build the mutation based on what data is provided
        $mutation = <<<'GRAPHQL'
        mutation reverseDeliveryCreateWithShipping(
          $reverseFulfillmentOrderId: ID!
          $reverseDeliveryLineItems: [ReverseDeliveryLineItemInput!]!
          $trackingInput: ReverseDeliveryTrackingInput
          $labelInput: ReverseDeliveryLabelInput
          $notifyCustomer: Boolean
        ) {
          reverseDeliveryCreateWithShipping(
            reverseFulfillmentOrderId: $reverseFulfillmentOrderId
            reverseDeliveryLineItems: $reverseDeliveryLineItems
            trackingInput: $trackingInput
            labelInput: $labelInput
            notifyCustomer: $notifyCustomer
          ) {
            reverseDelivery {
              id
              deliverable {
                ... on ReverseDeliveryShippingDeliverable {
                  label {
                    publicFileUrl
                  }
                  tracking {
                    number
                    url
                  }
                }
              }
            }
            userErrors {
              code
              field
              message
            }
          }
        }
        GRAPHQL;

        $variables = [
            'reverseFulfillmentOrderId' => $reverseFulfillmentOrderId,
            'reverseDeliveryLineItems' => $lineItems,
            'notifyCustomer' => $notifyCustomer,
        ];

        if ($trackingNumber || $trackingUrl) {
            $variables['trackingInput'] = array_filter([
                'number' => $trackingNumber,
                'url' => $trackingUrl,
            ]);
        }

        if ($labelUrl) {
            $variables['labelInput'] = [
                'fileUrl' => $labelUrl,
            ];
        }

        $response = $this->makeGraphQLRequest($mutation, $variables);

        if (! $response->successful()) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $response->body(),
                    'status' => $response->status(),
                    'reverse_fulfillment_order_id' => $reverseFulfillmentOrderId,
                ])
                ->log('shopify_attach_return_label_failed');

            return null;
        }

        $data = $response->json();

        if (isset($data['errors'])) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'errors' => $data['errors'],
                    'reverse_fulfillment_order_id' => $reverseFulfillmentOrderId,
                ])
                ->log('shopify_attach_return_label_graphql_errors');

            return null;
        }

        if (! empty($data['data']['reverseDeliveryCreateWithShipping']['userErrors'])) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'user_errors' => $data['data']['reverseDeliveryCreateWithShipping']['userErrors'],
                    'reverse_fulfillment_order_id' => $reverseFulfillmentOrderId,
                ])
                ->log('shopify_attach_return_label_user_errors');

            return null;
        }

        return $data['data']['reverseDeliveryCreateWithShipping']['reverseDelivery'] ?? null;
    }

    /**
     * Helper to fetch paginated GraphQL results using nodes format
     * More efficient than edges format for large datasets
     */
    protected function fetchPaginatedGraphQLOrders(string $query, array $baseVariables = []): Collection
    {
        $allResults = collect();
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $variables = $cursor ? array_merge($baseVariables, ['cursor' => $cursor]) : $baseVariables;
            $response = $this->makeGraphQLRequest($query, $variables);

            if (! $response->successful()) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'error' => $response->body(),
                        'status' => $response->status(),
                    ])
                    ->log('shopify_graphql_request_failed');

                break;
            }

            $data = $response->json();

            if (isset($data['errors'])) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'errors' => $data['errors'],
                    ])
                    ->log('shopify_graphql_errors');

                break;
            }

            // Use nodes format directly (more efficient than edges)
            $orders = $data['data']['orders']['nodes'] ?? [];
            $pageInfo = $data['data']['orders']['pageInfo'] ?? [];

            // Nodes are already in the correct format, no need to extract from edges
            foreach ($orders as $order) {
                $allResults->push($order);
            }

            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;

            // Safety break to prevent infinite loops
            if ($allResults->count() > 1000) {
                break;
            }
        }

        return $allResults;
    }

    /**
     * Helper to fetch paginated GraphQL results (legacy edges format)
     */
    protected function fetchPaginatedGraphQL(string $query, array $baseVariables = []): Collection
    {
        $allResults = collect();
        $cursor = null;
        $hasNextPage = true;

        while ($hasNextPage) {
            $variables = $cursor ? array_merge($baseVariables, ['cursor' => $cursor]) : $baseVariables;
            $response = $this->makeGraphQLRequest($query, $variables);

            if (! $response->successful()) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'error' => $response->body(),
                        'status' => $response->status(),
                    ])
                    ->log('shopify_graphql_request_failed');

                break;
            }

            $data = $response->json();

            if (isset($data['errors'])) {
                activity()
                    ->performedOn($this->integration)
                    ->withProperties([
                        'errors' => $data['errors'],
                    ])
                    ->log('shopify_graphql_errors');

                break;
            }

            $orders = $data['data']['orders']['edges'] ?? [];
            $pageInfo = $data['data']['orders']['pageInfo'] ?? [];

            foreach ($orders as $edge) {
                if (isset($edge['node'])) {
                    $allResults->push($edge['node']);
                }
            }

            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;

            // Safety break to prevent infinite loops
            if ($allResults->count() > 1000) {
                break;
            }
        }

        return $allResults;
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
            'returns/request',
            'returns/approve',
            'returns/decline',
            'returns/close',
            'returns/cancel',
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

    /**
     * Update order addresses in Shopify
     */
    public function updateOrderAddresses(Order $order): bool
    {
        try {
            // Get Shopify order ID from platform mapping
            $platformMapping = $order->platformMappings()
                ->where('platform', OrderChannel::SHOPIFY->value)
                ->first();

            if (! $platformMapping) {
                activity()
                    ->performedOn($order)
                    ->log('shopify_update_addresses_no_mapping');

                return false;
            }

            $shopifyOrderId = 'gid://shopify/Order/'.$platformMapping->platform_id;

            // Prepare shipping address
            $shippingAddress = null;
            if ($order->shippingAddress) {
                $addr = $order->shippingAddress;
                $shippingAddress = [
                    'address1' => $addr->address_line1,
                    'address2' => $addr->address_line2,
                    'city' => $addr->province?->name.'/'.$addr->district?->name,
                    'zip' => $addr->postal_code,
                    'country' => $addr->country?->name ?? 'Turkey',
                    'company' => $addr->company_name,
                ];
            }

            // Build the mutation (Note: Shopify's orderUpdate only supports shippingAddress, not billingAddress)
            $mutation = <<<'GQL'
mutation OrderUpdate($input: OrderInput!) {
  orderUpdate(input: $input) {
    order {
      id
      shippingAddress {
        address1
        address2
        city
        zip
        country
        firstName
        lastName
        phone
        company
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

            $input = [
                'id' => $shopifyOrderId,
            ];

            if ($shippingAddress) {
                $input['shippingAddress'] = $shippingAddress;
            }

            $variables = [
                'input' => $input,
            ];

            $response = $this->makeGraphQLRequest($mutation, $variables);

            if (! $response->successful()) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'error' => $response->body(),
                        'status' => $response->status(),
                    ])
                    ->log('shopify_update_addresses_request_failed');

                return false;
            }

            $data = $response->json();

            if (isset($data['errors'])) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'errors' => $data['errors'],
                    ])
                    ->log('shopify_update_addresses_graphql_errors');

                return false;
            }

            if (isset($data['data']['orderUpdate']['userErrors']) &&
                count($data['data']['orderUpdate']['userErrors']) > 0) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'errors' => $data['data']['orderUpdate']['userErrors'],
                    ])
                    ->log('shopify_update_addresses_failed');

                return false;
            }

            activity()
                ->performedOn($order)
                ->withProperties([
                    'shopify_order_id' => $shopifyOrderId,
                ])
                ->log('shopify_update_addresses_success');

            return true;
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_update_addresses_exception');

            return false;
        }
    }

    public function hasWebhook(): bool
    {
        return isset($this->integration->config['webhook']['webhooks']);
    }

    public function getWebhookInfo(): ?array
    {
        return $this->integration->config['webhook'] ?? null;
    }

    /**
     * Update return status in Shopify
     *
     * Shopify API: https://shopify.dev/docs/api/admin-rest/2025-10/resources/return
     */
    public function updateReturn(OrderReturn $return): bool
    {
        try {
            // Map internal status to Shopify return status
            $shopifyStatus = match ($return->status) {
                ReturnStatus::Approved => 'approved',
                ReturnStatus::Rejected => 'declined',
                ReturnStatus::Completed => 'closed',
                default => null,
            };

            if (! $shopifyStatus) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'status' => $return->status->value,
                        'reason' => 'status_not_mappable_to_shopify',
                    ])
                    ->log('shopify_return_update_skipped');

                return true; // Not an error, just not actionable
            }

            $response = $this->makeRequest('put', "/returns/{$return->external_return_id}.json", [
                'return' => [
                    'status' => $shopifyStatus,
                ],
            ]);

            if ($response->successful()) {
                activity()
                    ->performedOn($return)
                    ->withProperties([
                        'external_return_id' => $return->external_return_id,
                        'shopify_status' => $shopifyStatus,
                    ])
                    ->log('shopify_return_updated');

                return true;
            }

            activity()
                ->performedOn($return)
                ->withProperties([
                    'external_return_id' => $return->external_return_id,
                    'status_code' => $response->status(),
                    'error' => $response->json(),
                ])
                ->log('shopify_return_update_failed');

            return false;
        } catch (\Exception $e) {
            activity()
                ->performedOn($return)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_return_update_exception');

            return false;
        }
    }

    /**
     * Cancel an order in Shopify
     *
     * Shopify API: https://shopify.dev/docs/api/admin-rest/2025-10/resources/order#post-orders-order-id-cancel
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
                    ->log('shopify_order_cancel_skipped');

                return false;
            }

            $response = $this->makeRequest('post', "/orders/{$order->external_id}/cancel.json", [
                'reason' => 'customer',
                'email' => false, // Don't send cancellation email
                'restock' => true, // Restock items
            ]);

            if ($response->successful()) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'external_id' => $order->external_id,
                        'reason' => $reason,
                    ])
                    ->log('shopify_order_cancelled');

                return true;
            }

            activity()
                ->performedOn($order)
                ->withProperties([
                    'external_id' => $order->external_id,
                    'status_code' => $response->status(),
                    'error' => $response->json(),
                ])
                ->log('shopify_order_cancel_failed');

            return false;
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('shopify_order_cancel_exception');

            return false;
        }
    }
}
