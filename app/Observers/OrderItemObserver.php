<?php

namespace App\Observers;

use App\Events\Order\OrderItemCreated;
use App\Models\Order\OrderItem;

class OrderItemObserver
{
    /**
     * Handle the OrderItem "created" event.
     * Dispatch event to queue inventory deduction
     */
    public function created(OrderItem $orderItem): void
    {
        OrderItemCreated::dispatch($orderItem);
    }
}
