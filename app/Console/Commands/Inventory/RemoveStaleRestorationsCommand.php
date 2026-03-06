<?php

namespace App\Console\Commands\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\LocationInventory;
use App\Models\Order\Order;
use App\Models\Product\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RemoveStaleRestorationsCommand extends Command
{
    protected $signature = 'inventory:remove-stale-restorations
                            {--order= : Only process a specific order ID or order number}
                            {--dry-run : Show what would be removed without making changes}';

    protected $description = 'Remove cancellation inventory movements for orders that are no longer cancelled (e.g. UnDelivered → Delivered transitions)';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $orderFilter = $this->option('order');

        $this->info('Scanning for stale cancellation movements on non-cancelled orders...');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }

        $this->newLine();

        // Find cancellation movements where the linked order is NOT cancelled
        $query = DB::table('inventory_movements as im')
            ->join('orders as o', 'im.order_id', '=', 'o.id')
            ->where('im.type', InventoryMovementType::Cancellation->value)
            ->where('o.order_status', '!=', 'cancelled')
            ->whereNotNull('im.order_id')
            ->select('im.id as movement_id', 'im.product_variant_id', 'im.location_id', 'im.quantity', 'im.created_at', 'o.id as order_id', 'o.order_number', 'o.order_status');

        if ($orderFilter) {
            $order = Order::where('order_number', $orderFilter)->orWhere('id', $orderFilter)->first();

            if (! $order) {
                $this->error("Order not found: {$orderFilter}");

                return self::FAILURE;
            }

            $query->where('im.order_id', $order->id);
        }

        $staleMovements = $query->orderBy('im.created_at')->get();

        if ($staleMovements->isEmpty()) {
            $this->info('No stale cancellation movements found.');

            return self::SUCCESS;
        }

        $this->warn("Found {$staleMovements->count()} stale cancellation movement(s):");
        $this->newLine();

        $this->table(
            ['Movement ID', 'Order #', 'Order Status', 'Variant ID', 'Qty Wrongly Restored', 'Created At'],
            $staleMovements->map(fn ($m) => [
                $m->movement_id,
                $m->order_number,
                $m->order_status,
                $m->product_variant_id,
                '+'.$m->quantity,
                $m->created_at,
            ])
        );

        $this->newLine();

        if ($dryRun) {
            $this->info('Run without --dry-run to remove these movements and recalculate stock.');

            return self::SUCCESS;
        }

        $affectedVariantIds = $staleMovements->pluck('product_variant_id')->unique()->values()->toArray();
        $movementIds = $staleMovements->pluck('movement_id')->toArray();

        DB::transaction(function () use ($movementIds, $affectedVariantIds) {
            $deleted = InventoryMovement::whereIn('id', $movementIds)->delete();
            $this->info("Deleted {$deleted} stale cancellation movement(s).");
            $this->newLine();

            $this->info('Recalculating inventory for '.count($affectedVariantIds).' affected variant(s)...');

            foreach ($affectedVariantIds as $variantId) {
                $this->recalculateVariant($variantId);
            }
        });

        $this->newLine();
        $this->info('Done. Run "php artisan inventory:recalculate-history" to also fix the before/after values in the movement log.');

        return self::SUCCESS;
    }

    private function recalculateVariant(int $variantId): void
    {
        $locationInventories = LocationInventory::where('product_variant_id', $variantId)->get();

        foreach ($locationInventories as $li) {
            $quantity = InventoryMovement::where('product_variant_id', $variantId)
                ->where('location_id', $li->location_id)
                ->orderBy('created_at')
                ->sum('quantity');

            $li->update(['quantity' => $quantity]);
        }

        $variant = ProductVariant::find($variantId);

        if ($variant) {
            $variant->syncInventoryQuantity();
            $this->line("  Variant #{$variantId} ({$variant->sku}): stock recalculated to {$variant->fresh()->inventory_quantity}");
        }
    }
}
