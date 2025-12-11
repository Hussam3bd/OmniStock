<?php

namespace App\Observers;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Events\Order\OrderCancelled;
use App\Jobs\SyncOrderFulfillmentData;
use App\Jobs\SyncOrderPaymentFees;
use App\Models\Order\Order;

class OrderObserver
{
    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        // 1. Handle inventory restoration for cancelled/rejected orders
        if ($order->isDirty('order_status') &&
            ($order->order_status === OrderStatus::CANCELLED ||
             $order->order_status === OrderStatus::REJECTED)) {
            OrderCancelled::dispatch($order);
        }

        // 2. Auto-sync payment fees when order is paid
        if ($order->isDirty('payment_status') &&
            $order->payment_status === PaymentStatus::PAID &&
            $order->payment_transaction_id) {
            SyncOrderPaymentFees::dispatch($order);
        }

        // 3. Auto-sync shipment data when Shopify order is fulfilled
        if ($order->isDirty('fulfillment_status') &&
            $order->fulfillment_status === FulfillmentStatus::FULFILLED &&
            $order->channel === OrderChannel::SHOPIFY &&
            $order->isExternal()) {
            SyncOrderFulfillmentData::dispatch($order);
        }
    }
}
