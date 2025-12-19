<?php

namespace App\Console\Commands\Payment;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\PaymentGateway;
use App\Jobs\SyncPaymentFees;
use App\Models\Order\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class RecalculatePaymentFees extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payment:recalculate-fees
                            {--channel= : Filter by channel (e.g., SHOPIFY, TRENDYOL)}
                            {--gateway= : Filter by payment gateway (e.g., iyzico, stripe)}
                            {--order-id=* : Specific order IDs to process}
                            {--missing-only : Only process orders with missing fees (null or zero)}
                            {--dry-run : Show what would be processed without actually processing}
                            {--sync : Process synchronously instead of batching (slower but shows immediate results)}
                            {--batch-size=100 : Number of jobs per batch (default: 100)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate payment gateway fees for orders by fetching from payment providers';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🔄 Finding orders that need payment fee recalculation...');
        $this->newLine();

        // Build query to find orders
        $query = $this->buildQuery();

        $totalOrders = $query->count();

        if ($totalOrders === 0) {
            $this->info('✅ No orders found matching the criteria');

            return self::SUCCESS;
        }

        $this->info("Found {$totalOrders} order(s) to process");
        $this->newLine();

        // Show sample orders
        $this->displaySampleOrders($query);

        if ($this->option('dry-run')) {
            $this->warn('🔸 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->info('Run without --dry-run to process these orders');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->confirm('Do you want to proceed with recalculating fees?', true)) {
            $this->warn('❌ Operation cancelled');

            return self::FAILURE;
        }

        if ($this->option('sync')) {
            return $this->processSynchronously($query, $totalOrders);
        }

        return $this->processBatched($query, $totalOrders);
    }

    /**
     * Build the query to find orders
     */
    protected function buildQuery()
    {
        $query = Order::query();

        // Filter by channel
        if ($channel = $this->option('channel')) {
            $channelEnum = OrderChannel::tryFrom(strtolower($channel));
            if (! $channelEnum) {
                $this->error("Invalid channel: {$channel}");
                exit(1);
            }
            $query->where('channel', $channelEnum->value);
        }

        // Filter by payment gateway
        if ($gateway = $this->option('gateway')) {
            $query->where('payment_gateway', 'LIKE', "%{$gateway}%");
        }

        // Filter by specific order IDs
        if ($orderIds = $this->option('order-id')) {
            $query->whereIn('id', $orderIds);
        }

        // Must have transaction ID (required for fee lookup)
        $query->whereNotNull('payment_transaction_id');

        // Only gateways that support automated sync
        $supportedGateways = collect(PaymentGateway::cases())
            ->filter(fn ($gateway) => $gateway->supportsAutomatedSync())
            ->map(fn ($gateway) => $gateway->value)
            ->toArray();

        $query->where(function ($q) use ($supportedGateways) {
            foreach ($supportedGateways as $gateway) {
                $q->orWhere('payment_gateway', 'LIKE', "%{$gateway}%");
            }
        });

        // If --missing-only, filter for orders with null or zero fees
        if ($this->option('missing-only')) {
            $query->where(function ($q) {
                $q->whereNull('payment_gateway_fee')
                    ->orWhere('payment_gateway_fee', 0)
                    ->whereNull('payment_gateway_commission_amount')
                    ->orWhere('payment_gateway_commission_amount', 0);
            });
        }

        return $query->orderBy('id');
    }

    /**
     * Display sample orders that will be processed
     */
    protected function displaySampleOrders($query): void
    {
        $samples = $query->limit(5)->get();

        $this->table(
            ['ID', 'Order #', 'Channel', 'Payment Gateway', 'Transaction ID', 'Current Fee', 'Current Commission'],
            $samples->map(fn ($order) => [
                $order->id,
                $order->order_number,
                $order->channel->getLabel(),
                $order->payment_gateway,
                substr($order->payment_transaction_id, 0, 20).'...',
                $order->payment_gateway_fee ? '₺'.number_format($order->payment_gateway_fee / 100, 2) : 'null',
                $order->payment_gateway_commission_amount ? '₺'.number_format($order->payment_gateway_commission_amount / 100, 2) : 'null',
            ])
        );

        $this->newLine();
    }

    /**
     * Process orders synchronously (one by one)
     */
    protected function processSynchronously($query, int $total): int
    {
        $this->info('🔄 Processing orders synchronously...');
        $this->newLine();

        $progressBar = $this->output->createProgressBar($total);
        $progressBar->start();

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($query->cursor() as $order) {
            try {
                $job = new SyncPaymentFees($order);
                $job->handle(app(\App\Services\Payment\PaymentCostSyncService::class));
                $successCount++;
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'skipped')) {
                    $skippedCount++;
                } else {
                    $failedCount++;
                    $this->newLine();
                    $this->error("Failed to process order #{$order->order_number}: {$e->getMessage()}");
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->displayResults($successCount, $failedCount, $skippedCount);

        return self::SUCCESS;
    }

    /**
     * Process orders in batches (asynchronous)
     */
    protected function processBatched($query, int $total): int
    {
        $batchSize = (int) $this->option('batch-size');

        $this->info("🔄 Creating batch jobs (batch size: {$batchSize})...");
        $this->newLine();

        $jobs = [];
        foreach ($query->cursor() as $order) {
            $jobs[] = new SyncPaymentFees($order);
        }

        $batch = Bus::batch($jobs)
            ->name('Recalculate Payment Fees')
            ->allowFailures()
            ->dispatch();

        $this->info("✅ Batch created with ID: {$batch->id}");
        $this->info("📊 Total jobs dispatched: {$total}");
        $this->newLine();
        $this->info('You can monitor the batch progress in your queue dashboard or logs');
        $this->info('Run: php artisan queue:work to process the jobs');

        return self::SUCCESS;
    }

    /**
     * Display results summary
     */
    protected function displayResults(int $success, int $failed, int $skipped): void
    {
        $this->info('✅ Processing completed!');
        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Success', $success],
                ['Failed', $failed],
                ['Skipped', $skipped],
                ['Total', $success + $failed + $skipped],
            ]
        );
    }
}
