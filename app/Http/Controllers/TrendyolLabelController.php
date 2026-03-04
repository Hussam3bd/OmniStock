<?php

namespace App\Http\Controllers;

use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Illuminate\Http\Response;

class TrendyolLabelController extends Controller
{
    public function show(Order $order): Response
    {
        abort_unless($order->channel === OrderChannel::TRENDYOL, 404);

        $order->load([
            'shippingAddress.province',
            'shippingAddress.district',
            'customer',
            'items.productVariant.product',
            'items.productVariant.optionValues.variantOption',
        ]);

        $trackingNumber = $order->shipping_tracking_number;

        if (empty($trackingNumber) || $trackingNumber === '0') {
            $trackingNumber = $this->refreshTrackingNumber($order);
        }

        if (empty($trackingNumber) || $trackingNumber === '0') {
            abort(422, 'Tracking number is not yet available for this order. Please try again later.');
        }

        $carrierLabel = $order->shipping_carrier
            ? $order->shipping_carrier->getLabel().' Kargo'
            : 'Trendyol';

        return response()->view('orders.shipping-label', [
            'order' => $order,
            'trackingNumber' => $trackingNumber,
            'note' => 'Bu sipariş Trendyol kanalı üzerinden alınmıştır.',
            'carrierLabel' => $carrierLabel,
        ]);
    }

    private function refreshTrackingNumber(Order $order): ?string
    {
        $integration = Integration::where('provider', 'trendyol')
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return null;
        }

        $tracking = (new TrendyolAdapter($integration))->fetchCargoTracking($order->order_number);

        if (! empty($tracking) && $tracking !== '0') {
            $order->update(['shipping_tracking_number' => $tracking]);
        }

        return $tracking;
    }
}
