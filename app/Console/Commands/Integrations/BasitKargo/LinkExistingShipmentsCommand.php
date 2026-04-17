<?php

namespace App\Console\Commands\Integrations\BasitKargo;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use Illuminate\Console\Command;

class LinkExistingShipmentsCommand extends Command
{
    protected $signature = 'basitkargo:link-existing-shipments
                            {--dry-run : Show what would be linked without updating orders}
                            {--order= : Limit to a single order id}';

    protected $description = 'Link Shopify orders that are missing shipping_aggregator_shipment_id to their existing BasitKargo shipment (found by Shopify foreignCode).';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $singleOrderId = $this->option('order');

        $integration = Integration::query()
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            $this->error('No active BasitKargo integration found.');

            return Command::FAILURE;
        }

        $adapter = new BasitKargoAdapter($integration);

        $query = Order::query()
            ->where('channel', OrderChannel::SHOPIFY)
            ->whereNull('shipping_aggregator_shipment_id')
            ->whereIn('fulfillment_status', [
                FulfillmentStatus::UNFULFILLED,
                FulfillmentStatus::AWAITING_SHIPMENT,
            ])
            ->whereNotNull('order_date');

        if ($singleOrderId) {
            $query->where('id', $singleOrderId);
        }

        $orders = $query->with('platformMappings')->get();

        if ($orders->isEmpty()) {
            $this->info('No orders need linking.');

            return Command::SUCCESS;
        }

        $this->info("Checking {$orders->count()} order(s)...");

        $linked = 0;
        $rows = [];

        foreach ($orders as $order) {
            $shopifyGid = $order->platformMappings
                ->firstWhere('platform', OrderChannel::SHOPIFY->value)
                ?->platform_id;

            if (! $shopifyGid) {
                $rows[] = [$order->id, $order->order_number, '-', 'skip (no Shopify GID)'];

                continue;
            }

            try {
                $existing = $adapter->findShipmentByForeignCode($shopifyGid, $order->order_date);
            } catch (\Exception $e) {
                $rows[] = [$order->id, $order->order_number, '-', 'error: '.$e->getMessage()];

                continue;
            }

            if (! $existing || empty($existing['id'])) {
                $rows[] = [$order->id, $order->order_number, '-', 'no match'];

                continue;
            }

            $barcode = $existing['barcode'] ?? '';
            $rows[] = [$order->id, $order->order_number, $existing['id'], $barcode ?: '(no barcode)'];

            if ($dryRun) {
                continue;
            }

            $updates = ['shipping_aggregator_integration_id' => $integration->id];

            if ($barcode) {
                $updates['shipping_aggregator_shipment_id'] = $existing['id'];
                $updates['shipping_tracking_number'] = $barcode;
            }

            $order->update($updates);

            activity()
                ->performedOn($order)
                ->withProperties([
                    'integration_id' => $integration->id,
                    'shipment_id' => $existing['id'],
                    'barcode' => $barcode,
                    'source' => 'backfill_command',
                ])
                ->log('basitkargo_existing_shipment_linked');

            $linked++;
        }

        $this->table(['Order ID', 'Order #', 'Shipment ID', 'Barcode / Note'], $rows);

        if ($dryRun) {
            $this->warn('DRY RUN — no orders were updated.');
        } else {
            $this->info("Linked {$linked} order(s).");
        }

        return Command::SUCCESS;
    }
}
