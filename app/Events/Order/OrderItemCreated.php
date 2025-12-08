<?php

namespace App\Events\Order;

use App\Models\Order\OrderItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderItemCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public OrderItem $orderItem
    ) {}
}
