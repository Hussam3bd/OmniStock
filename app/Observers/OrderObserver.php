<?php

namespace App\Observers;

use App\Enums\Order\OrderStatus;
use App\Events\Order\OrderCancelled;
use App\Models\Order\Order;

class OrderObserver
{
    /**
     * Handle the Order "updated" event.
     * Dispatch event to queue inventory restoration if order is cancelled
     */
    public function updated(Order $order): void
    {
        // Check if order status changed to cancelled
        if ($order->isDirty('order_status') && $order->order_status === OrderStatus::CANCELLED) {
            OrderCancelled::dispatch($order);
        }
    }
}
