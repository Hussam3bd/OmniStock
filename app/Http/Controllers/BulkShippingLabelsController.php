<?php

namespace App\Http\Controllers;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class BulkShippingLabelsController extends Controller
{
    public function show(): Response
    {
        $orders = $this->resolveOrders();

        $orders->load([
            'shippingAddress.province',
            'shippingAddress.district',
            'customer',
            'items.productVariant.product',
            'items.productVariant.optionValues.variantOption',
        ]);

        $trendyolIntegration = Integration::where('provider', 'trendyol')
            ->where('is_active', true)
            ->first();

        $labels = $orders
            ->map(fn (Order $order) => $this->buildLabel($order, $trendyolIntegration))
            ->filter()
            ->values();

        return response()->view('orders.shipping-labels-bulk', compact('labels'));
    }

    private function resolveOrders(): Collection
    {
        // Same query as PrepareOrders::loadQueue()
        return Order::whereIn('fulfillment_status', [
            FulfillmentStatus::UNFULFILLED->value,
            FulfillmentStatus::AWAITING_SHIPMENT->value,
        ])
            ->whereIn('order_status', [
                OrderStatus::PENDING->value,
                OrderStatus::CONFIRMED->value,
                OrderStatus::PROCESSING->value,
            ])
            ->orderBy('created_at')
            ->get();
    }

    private function buildLabel(Order $order, ?Integration $trendyolIntegration): ?array
    {
        $trackingNumber = $order->shipping_tracking_number;

        if ($order->channel === OrderChannel::TRENDYOL) {
            if ((empty($trackingNumber) || $trackingNumber === '0') && $trendyolIntegration) {
                $trackingNumber = $this->refreshTrendyolTracking($order, $trendyolIntegration);
            }

            $note = 'Bu sipariş Trendyol kanalı üzerinden alınmıştır.';
        } else {
            $note = 'Pazaryeri - Gemen Bilgi Teknolojileri olarak işleme alınmalıdır!';
        }

        if (empty($trackingNumber) || $trackingNumber === '0') {
            return null;
        }

        $carrierLabel = $order->shipping_carrier
            ? $order->shipping_carrier->getLabel().' Kargo'
            : ($order->channel === OrderChannel::TRENDYOL ? 'Trendyol' : 'Kargo');

        return [
            'order' => $order,
            'trackingNumber' => $trackingNumber,
            'note' => $note,
            'carrierLabel' => $carrierLabel,
        ];
    }

    private function refreshTrendyolTracking(Order $order, Integration $integration): ?string
    {
        $tracking = (new TrendyolAdapter($integration))->fetchCargoTracking($order->order_number);

        if (! empty($tracking) && $tracking !== '0') {
            $order->update(['shipping_tracking_number' => $tracking]);
        }

        return $tracking ?: null;
    }
}
