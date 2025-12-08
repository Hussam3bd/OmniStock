<?php

namespace App\Observers;

use App\Enums\Order\ReturnStatus;
use App\Events\Order\OrderReturnCompleted;
use App\Models\Order\OrderReturn;

class OrderReturnObserver
{
    /**
     * Handle the OrderReturn "updated" event.
     * Dispatch event to queue inventory restoration when return is completed
     */
    public function updated(OrderReturn $orderReturn): void
    {
        // Check if return status changed to completed
        if ($orderReturn->isDirty('status') && $orderReturn->status === ReturnStatus::Completed) {
            OrderReturnCompleted::dispatch($orderReturn);
        }
    }
}
