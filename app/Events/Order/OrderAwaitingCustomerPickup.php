<?php

namespace App\Events\Order;

use App\Models\Order\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderAwaitingCustomerPickup
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Order $order,
        public ?string $distributionCenterName = null,
        public ?string $distributionCenterLocation = null
    ) {
        //
    }
}
