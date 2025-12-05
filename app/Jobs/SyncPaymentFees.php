<?php

namespace App\Jobs;

use App\Models\Order\Order;
use App\Services\Payment\PaymentCostSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncPaymentFees implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(PaymentCostSyncService $service): void
    {
        try {
            $service->syncPaymentCosts($this->order);
        } catch (\Exception $e) {
            // Log the error
            activity()
                ->performedOn($this->order)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                ])
                ->log('payment_fee_sync_job_failed');

            // Re-throw so the job is marked as failed in the batch
            throw $e;
        }
    }
}
