<?php

namespace App\Console\Commands\Integrations\BasitKargo;

use App\Jobs\ProcessBasitKargoWebhook;
use App\Models\Order\Order;
use Illuminate\Console\Command;
use Spatie\WebhookClient\Models\WebhookCall;

class SyncPendingShipmentsCommand extends Command
{
    protected $signature = 'basitkargo:sync-pending-shipments
                            {--dry-run : Show what would be replayed without dispatching jobs}';

    protected $description = 'Replay READY_TO_SHIP BasitKargo webhook_calls for orders that still have no barcode';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no jobs will be dispatched.');
        }

        // All Shopify pending orders with no tracking number
        $pendingOrderNumbers = Order::where('channel', 'shopify')
            ->whereNull('shipping_tracking_number')
            ->whereIn('fulfillment_status', ['unfulfilled', 'awaiting_shipment'])
            ->pluck('order_number')
            ->map(fn ($n) => (string) $n)
            ->all();

        if (empty($pendingOrderNumbers)) {
            $this->info('No pending orders without barcodes found.');

            return Command::SUCCESS;
        }

        $this->info('Found '.count($pendingOrderNumbers).' orders without barcodes.');

        // Find READY_TO_SHIP webhook_calls whose orderNumber matches a pending order
        $webhooks = WebhookCall::where('name', 'basitkargo')
            ->whereNull('exception')
            ->get()
            ->filter(function (WebhookCall $call) use ($pendingOrderNumbers): bool {
                $payload = is_array($call->payload) ? $call->payload : json_decode($call->payload, true);
                $status = $payload['status'] ?? null;
                $orderNumber = (string) ($payload['orderNumber'] ?? '');

                return $status === 'READY_TO_SHIP' && in_array($orderNumber, $pendingOrderNumbers, true);
            })
            // Keep only the latest webhook per order number
            ->sortByDesc('created_at')
            ->unique(fn (WebhookCall $call) => $call->payload['orderNumber'] ?? $call->id);

        if ($webhooks->isEmpty()) {
            $this->warn('No READY_TO_SHIP webhook_calls found for pending orders.');

            return Command::SUCCESS;
        }

        $rows = [];

        foreach ($webhooks as $call) {
            $payload = is_array($call->payload) ? $call->payload : json_decode($call->payload, true);
            $orderNumber = $payload['orderNumber'] ?? '?';
            $barcode = $payload['barcode'] ?? '?';

            $rows[] = [$call->id, $orderNumber, $barcode, $call->created_at];

            if (! $dryRun) {
                ProcessBasitKargoWebhook::dispatch($call);
            }
        }

        $this->table(['Webhook ID', 'Order #', 'Barcode', 'Received At'], $rows);
        $this->newLine();

        if ($dryRun) {
            $this->info(count($rows).' webhook(s) would be replayed.');
        } else {
            $this->info(count($rows).' webhook(s) dispatched.');
        }

        return Command::SUCCESS;
    }
}
