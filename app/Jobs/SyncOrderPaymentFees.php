<?php

namespace App\Jobs;

use App\Models\Order\Order;
use App\Services\Payment\PaymentCostSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncOrderPaymentFees implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     * Reuses PaymentCostSyncService (same as ResyncPaymentCostAction)
     */
    public function handle(PaymentCostSyncService $service): void
    {
        // Use existing service - same logic as ResyncPaymentCostAction
        $service->syncPaymentCosts($this->order);
    }
}
