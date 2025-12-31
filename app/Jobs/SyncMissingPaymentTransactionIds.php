<?php

namespace App\Jobs;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMissingPaymentTransactionIds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $limit = 50
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting scheduled sync of missing payment transaction IDs', [
            'limit' => $this->limit,
        ]);

        // Find Shopify orders without payment transaction IDs from the last 30 days
        // Focus on recent orders to avoid overwhelming the system
        $orders = Order::query()
            ->where('channel', OrderChannel::SHOPIFY)
            ->whereNotNull('integration_id')
            ->whereNull('payment_transaction_id')
            ->whereNotNull('payment_gateway')
            ->where('created_at', '>=', now()->subDays(30))
            ->with('integration')
            ->limit($this->limit)
            ->get();

        if ($orders->isEmpty()) {
            Log::info('No orders found requiring payment transaction ID sync');

            return;
        }

        Log::info('Found orders requiring payment transaction ID sync', [
            'count' => $orders->count(),
        ]);

        $dispatched = 0;

        foreach ($orders as $order) {
            // Dispatch individual job for each order
            // Jobs are queued, so they won't overwhelm the Shopify API
            SyncPaymentTransactionId::dispatch($order);
            $dispatched++;
        }

        Log::info('Dispatched payment transaction ID sync jobs', [
            'dispatched' => $dispatched,
        ]);
    }
}
