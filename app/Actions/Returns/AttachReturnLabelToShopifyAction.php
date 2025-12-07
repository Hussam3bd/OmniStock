<?php

namespace App\Actions\Returns;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Models\Integration\Integration;
use App\Models\Order\OrderReturn;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;

class AttachReturnLabelToShopifyAction extends BaseReturnAction
{
    /**
     * Execute the action to attach return label to Shopify
     *
     * @param  array  $options  Optional parameters:
     *                          - 'label_url' => Public URL to the label file (required)
     *                          - 'tracking_number' => Tracking number (optional)
     *                          - 'tracking_url' => Tracking URL (optional)
     *                          - 'notify_customer' => Whether to notify customer (default: true)
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return must be from Shopify and have label generated');
        }

        try {
            // Get Shopify integration by provider (orders don't have a direct relationship to sales channel integration)
            $integration = Integration::where('provider', IntegrationProvider::SHOPIFY)
                ->where('is_active', true)
                ->first();

            if (! $integration) {
                throw new \Exception('No active Shopify integration found');
            }

            // Create Shopify adapter
            $adapter = new ShopifyAdapter($integration);

            // Get reverse fulfillment order data from return's platform_data
            $reverseFulfillmentOrders = $return->platform_data['reverseFulfillmentOrders'] ?? [];

            if (empty($reverseFulfillmentOrders)) {
                throw new \Exception('No reverse fulfillment order found in return data. Try re-syncing the return from Shopify.');
            }

            // Get the first reverse fulfillment order (usually only one)
            $reverseFulfillmentOrder = $reverseFulfillmentOrders['edges'][0]['node'] ?? null;

            if (! $reverseFulfillmentOrder) {
                throw new \Exception('Invalid reverse fulfillment order data');
            }

            $reverseFulfillmentOrderId = $reverseFulfillmentOrder['id'];

            // Build line items array for the mutation
            $lineItems = $this->buildLineItems($return, $reverseFulfillmentOrder);

            if (empty($lineItems)) {
                throw new \Exception('No line items found for reverse delivery');
            }

            // Get label URL (required)
            $labelUrl = $options['label_url'] ?? null;

            if (! $labelUrl) {
                throw new \Exception('Label URL is required');
            }

            // Attach label to Shopify
            $result = $adapter->attachReturnLabel(
                reverseFulfillmentOrderId: $reverseFulfillmentOrderId,
                lineItems: $lineItems,
                labelUrl: $labelUrl,
                trackingNumber: $options['tracking_number'] ?? $return->return_tracking_number,
                trackingUrl: $options['tracking_url'] ?? $return->return_tracking_url,
                notifyCustomer: $options['notify_customer'] ?? true
            );

            if (! $result) {
                throw new \Exception('Failed to attach label to Shopify');
            }

            // Log success
            $this->logAction($return, 'shopify_return_label_attached', [
                'integration_id' => $integration->id,
                'reverse_fulfillment_order_id' => $reverseFulfillmentOrderId,
                'reverse_delivery_id' => $result['id'] ?? null,
                'label_url' => $labelUrl,
                'tracking_number' => $options['tracking_number'] ?? $return->return_tracking_number,
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'shopify_return_label_attachment', $e, [
                'integration_id' => $integration->id ?? null,
                'label_url' => $options['label_url'] ?? null,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if label can be attached to Shopify
     */
    public function validate(OrderReturn $return): bool
    {
        // Return must be from Shopify
        if ($return->channel !== OrderChannel::SHOPIFY) {
            return false;
        }

        // Return must have label generated
        if ($return->status->value < ReturnStatus::LabelGenerated->value) {
            return false;
        }

        // Must have tracking number
        if (! $return->return_tracking_number) {
            return false;
        }

        return true;
    }

    /**
     * Build line items array for reverse delivery mutation
     * Maps our return items to Shopify's reverse fulfillment order line items
     */
    protected function buildLineItems(OrderReturn $return, array $reverseFulfillmentOrder): array
    {
        $lineItems = [];
        $reverseFulfillmentLineItems = $reverseFulfillmentOrder['lineItems']['edges'] ?? [];

        // For each return item, find matching reverse fulfillment order line item
        foreach ($return->items as $returnItem) {
            $variant = $returnItem->orderItem->productVariant;

            // Get Shopify variant ID from platform mapping (not external_id which may be null)
            $platformMapping = $variant->platformMappings()
                ->where('platform', OrderChannel::SHOPIFY->value)
                ->first();

            if (! $platformMapping) {
                continue; // Skip items without Shopify mapping
            }

            // Build GraphQL ID format: gid://shopify/ProductVariant/{id}
            $variantGraphQLId = 'gid://shopify/ProductVariant/'.$platformMapping->platform_id;

            // Find matching line item in reverse fulfillment order
            foreach ($reverseFulfillmentLineItems as $edge) {
                $lineItem = $edge['node'] ?? null;

                if (! $lineItem) {
                    continue;
                }

                // Match by variant GraphQL ID
                $lineItemVariantId = $lineItem['fulfillmentLineItem']['lineItem']['variant']['id'] ?? null;

                if ($lineItemVariantId === $variantGraphQLId) {
                    $lineItems[] = [
                        'reverseFulfillmentOrderLineItemId' => $lineItem['id'],
                        'quantity' => $returnItem->quantity,
                    ];
                    break;
                }
            }
        }

        return $lineItems;
    }
}
