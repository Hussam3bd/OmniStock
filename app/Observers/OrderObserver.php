<?php

namespace App\Observers;

use App\Enums\Order\OrderStatus;
use App\Events\Order\OrderCancelled;
use App\Models\Order\Order;

class OrderObserver
{
    /**
     * Handle the Order "updated" event.
     * Dispatch event to queue inventory restoration if order is cancelled or rejected
     */
    public function updated(Order $order): void
    {
        // Check if order status changed to cancelled or rejected
        // REJECTED is used for COD rejections where customer refused delivery
        // Both cases require inventory restoration since items are returned
        if ($order->isDirty('order_status') &&
            ($order->order_status === OrderStatus::CANCELLED ||
             $order->order_status === OrderStatus::REJECTED)) {
            OrderCancelled::dispatch($order);
        }
    }
}
