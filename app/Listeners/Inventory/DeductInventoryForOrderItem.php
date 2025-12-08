<?php

namespace App\Listeners\Inventory;

use App\Events\Order\OrderItemCreated;
use App\Services\Inventory\InventoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class DeductInventoryForOrderItem implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    public function __construct(
        protected InventoryService $inventoryService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(OrderItemCreated $event): void
    {
        $this->inventoryService->deductInventoryForOrderItem($event->orderItem);
    }
}
