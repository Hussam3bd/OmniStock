<?php

namespace App\Jobs;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use App\Services\Integrations\AdapterFactory;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncPaymentTransactionId implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Only process Shopify orders
        if ($this->order->channel !== OrderChannel::SHOPIFY) {
            activity()
                ->performedOn($this->order)
                ->withProperties(['reason' => 'not_shopify_order'])
                ->log('sync_payment_transaction_id_skipped');

            return;
        }

        // Skip if order already has transaction ID
        if ($this->order->payment_transaction_id) {
            return;
        }

        // Get integration
        if (! $this->order->integration) {
            activity()
                ->performedOn($this->order)
                ->withProperties(['reason' => 'no_integration'])
                ->log('sync_payment_transaction_id_skipped');

            return;
        }

        try {
            /** @var ShopifyAdapter $adapter */
            $adapter = AdapterFactory::make($this->order->integration);

            // Get Shopify order ID from platform mapping
            $shopifyOrderId = $this->order->platformMappings()
                ->where('platform', OrderChannel::SHOPIFY->value)
                ->value('platform_id');

            if (! $shopifyOrderId) {
                activity()
                    ->performedOn($this->order)
                    ->withProperties(['reason' => 'no_platform_mapping'])
                    ->log('sync_payment_transaction_id_failed');

                return;
            }

            // Fetch order with transactions
            $shopifyOrder = $adapter->fetchOrderWithTransactions($shopifyOrderId);

            if (! $shopifyOrder) {
                activity()
                    ->performedOn($this->order)
                    ->withProperties(['reason' => 'shopify_fetch_failed', 'shopify_order_id' => $shopifyOrderId])
                    ->log('sync_payment_transaction_id_failed');

                return;
            }

            // Extract payment transaction ID
            $transactionId = $this->extractPaymentTransactionId($shopifyOrder);

            if (! $transactionId) {
                activity()
                    ->performedOn($this->order)
                    ->withProperties(['reason' => 'no_transaction_found'])
                    ->log('sync_payment_transaction_id_skipped');

                return;
            }

            // Update order with transaction ID
            $this->order->update([
                'payment_transaction_id' => $transactionId,
            ]);

            activity()
                ->performedOn($this->order)
                ->withProperties([
                    'payment_transaction_id' => $transactionId,
                    'payment_gateway' => $this->order->payment_gateway,
                ])
                ->log('payment_transaction_id_synced');
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->order)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ])
                ->log('sync_payment_transaction_id_failed');

            // Don't fail the job - just log and continue
        }
    }

    protected function extractPaymentTransactionId(array $shopifyOrder): ?string
    {
        if (! isset($shopifyOrder['transactions']) || ! is_array($shopifyOrder['transactions'])) {
            return null;
        }

        foreach ($shopifyOrder['transactions'] as $transaction) {
            if (($transaction['status'] ?? '') === 'success') {
                // Try authorization first, then payment_id, then transaction id
                $transactionId = $transaction['authorization']
                    ?? $transaction['payment_id']
                    ?? $transaction['id']
                    ?? null;

                if ($transactionId) {
                    return (string) $transactionId;
                }
            }
        }

        return null;
    }
}
