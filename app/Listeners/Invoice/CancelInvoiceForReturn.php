<?php

namespace App\Listeners\Invoice;

use App\Actions\Invoice\CancelInvoiceAction;
use App\Enums\Invoice\InvoiceStatus;
use App\Events\Order\OrderReturnCompleted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CancelInvoiceForReturn implements ShouldQueue
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
        protected CancelInvoiceAction $cancelInvoiceAction
    ) {}

    /**
     * Handle the event.
     */
    public function handle(OrderReturnCompleted $event): void
    {
        $order = $event->orderReturn->order;

        $invoices = $order->invoices()
            ->where('status', InvoiceStatus::ISSUED)
            ->get();

        if ($invoices->isEmpty()) {
            return;
        }

        foreach ($invoices as $invoice) {
            try {
                $this->cancelInvoiceAction->execute($invoice, [
                    'reason' => 'Order return completed',
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to cancel invoice for return', [
                    'invoice_id' => $invoice->id,
                    'order_id' => $order->id,
                    'return_id' => $event->orderReturn->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
}
