<?php

namespace App\Console\Commands\Payment;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use App\Services\Integrations\AdapterFactory;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Illuminate\Console\Command;

class ResyncShopifyPaymentTransactionIds extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'payment:resync-shopify-transaction-ids
                            {--integration= : Specific Shopify integration ID to process}
                            {--limit=100 : Maximum number of orders to process}
                            {--dry-run : Show what would be synced without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Resync payment transaction IDs from Shopify for orders that are missing them';

    protected int $processed = 0;

    protected int $synced = 0;

    protected int $failed = 0;

    protected int $skipped = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting Shopify payment transaction ID resync...');
        $this->newLine();

        $limit = (int) $this->option('limit');
        $integrationId = $this->option('integration');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Running in DRY RUN mode - no changes will be made');
            $this->newLine();
        }

        // Query orders without payment transaction IDs
        $query = Order::query()
            ->where('channel', OrderChannel::SHOPIFY)
            ->whereNotNull('integration_id')
            ->whereNull('payment_transaction_id')
            ->whereNotNull('payment_gateway')
            ->with('integration');

        if ($integrationId) {
            $query->where('integration_id', $integrationId);
        }

        $ordersCount = $query->count();
        $this->info("Found {$ordersCount} Shopify orders without payment transaction IDs");

        if ($ordersCount === 0) {
            $this->info('No orders to process');

            return self::SUCCESS;
        }

        $ordersToProcess = min($ordersCount, $limit);
        $this->info("Processing {$ordersToProcess} orders...");
        $this->newLine();

        $progressBar = $this->output->createProgressBar($ordersToProcess);
        $progressBar->start();

        $query->limit($limit)->chunkById(50, function ($orders) use ($dryRun, $progressBar) {
            foreach ($orders as $order) {
                $this->processed++;

                try {
                    if (! $order->integration) {
                        $this->skipped++;
                        $progressBar->advance();

                        continue;
                    }

                    /** @var ShopifyAdapter $adapter */
                    $adapter = AdapterFactory::make($order->integration);

                    // Get Shopify order ID from platform mapping
                    $shopifyOrderId = $order->platformMappings()
                        ->where('platform', OrderChannel::SHOPIFY->value)
                        ->value('platform_id');

                    if (! $shopifyOrderId) {
                        $this->skipped++;
                        $progressBar->advance();

                        continue;
                    }

                    // Fetch order with transactions
                    $shopifyOrder = $adapter->fetchOrderWithTransactions($shopifyOrderId);

                    if (! $shopifyOrder) {
                        $this->failed++;
                        $progressBar->advance();

                        continue;
                    }

                    // Extract payment transaction ID
                    $transactionId = $this->extractPaymentTransactionId($shopifyOrder);

                    if (! $transactionId) {
                        $this->skipped++;
                        $progressBar->advance();

                        continue;
                    }

                    // Update order with transaction ID (unless dry run)
                    if (! $dryRun) {
                        $order->update([
                            'payment_transaction_id' => $transactionId,
                        ]);

                        // Note: OrderObserver will automatically trigger payment cost sync
                    }

                    $this->synced++;
                } catch (\Exception $e) {
                    $this->failed++;
                    activity()
                        ->performedOn($order)
                        ->withProperties([
                            'error' => $e->getMessage(),
                            'command' => 'payment:resync-shopify-transaction-ids',
                        ])
                        ->log('payment_transaction_id_sync_failed');
                }

                $progressBar->advance();
            }
        });

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->info('Resync completed!');
        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $this->processed],
                ['Synced', $this->synced],
                ['Skipped (No transaction)', $this->skipped],
                ['Failed', $this->failed],
            ]
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a DRY RUN - no changes were made');
            $this->info('Run without --dry-run to actually update the orders');
        } elseif ($this->synced > 0) {
            $this->newLine();
            $this->info('Payment costs will be automatically synced for orders with transaction IDs');
        }

        return self::SUCCESS;
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
