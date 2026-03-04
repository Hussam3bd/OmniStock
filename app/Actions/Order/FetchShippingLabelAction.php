<?php

namespace App\Actions\Order;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects\LabelResponse;

class FetchShippingLabelAction
{
    /**
     * Fetch the shipping label for the given order.
     *
     * Note: Trendyol labels are generated as HTML via TrendyolLabelController,
     * not fetched as binary through this action.
     *
     * @throws \RuntimeException when the channel is unsupported or the API call fails
     */
    public function execute(Order $order): LabelResponse
    {
        return match ($order->channel) {
            OrderChannel::SHOPIFY => $this->fetchBasitKargoLabel($order),
            default => throw new \RuntimeException(
                "Shipping label fetching is not supported for {$order->channel->getLabel()} orders via this route."
            ),
        };
    }

    private function fetchBasitKargoLabel(Order $order): LabelResponse
    {
        if (! $order->shipping_aggregator_shipment_id) {
            throw new \RuntimeException('No BasitKargo shipment ID found for this order.');
        }

        $integration = $order->shippingAggregatorIntegration;

        if (! $integration) {
            throw new \RuntimeException('No shipping aggregator integration linked to this order.');
        }

        return (new BasitKargoAdapter($integration))->getReturnLabel(
            $order->shipping_aggregator_shipment_id,
            $order->shipping_tracking_number ?? ''
        );
    }
}
