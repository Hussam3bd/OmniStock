<?php

namespace App\Events\Order;

use App\Models\Order\OrderReturn;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderReturnCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public OrderReturn $orderReturn
    ) {}
}
