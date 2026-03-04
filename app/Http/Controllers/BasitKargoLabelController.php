<?php

namespace App\Http\Controllers;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use Illuminate\Http\Response;

class BasitKargoLabelController extends Controller
{
    public function show(Order $order): Response
    {
        abort_unless($order->channel === OrderChannel::SHOPIFY, 404);
        abort_if(empty($order->shipping_tracking_number), 422, 'Tracking number is not yet available for this order.');

        $order->load([
            'shippingAddress.province',
            'shippingAddress.district',
            'customer',
            'items.productVariant.product',
            'items.productVariant.optionValues.variantOption',
        ]);

        $carrierLabel = $order->shipping_carrier
            ? $order->shipping_carrier->getLabel().' Kargo'
            : 'Kargo';

        return response()->view('orders.shipping-label', [
            'order' => $order,
            'trackingNumber' => $order->shipping_tracking_number,
            'note' => 'Pazaryeri - Gemen Bilgi Teknolojileri olarak işleme alınmalıdır!',
            'carrierLabel' => $carrierLabel,
        ]);
    }
}
