<?php

namespace App\Filament\Widgets;

use App\Enums\Inventory\InventoryMovementType;
use App\Enums\Order\FulfillmentStatus;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\LocationInventory;
use App\Models\Order\OrderItem;
use App\Models\Product\ProductVariant;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class OversoldItemsWidget extends Widget
{
    protected string $view = 'filament.widgets.oversold-items-widget';

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    /**
     * Returns variants where on-hand stock cannot cover pending commitments,
     * grouped as: product_title → color → [ size => shortfall ]
     *
     * Matches the InventoryReport formula:
     *   on_hand    = physical_stock + committed + needs_fix + damaged
     *   shortfall  = max(0, committed - on_hand)
     *             = max(0, -(physical_stock + needs_fix + damaged))
     */
    public function getGroupedItems(): Collection
    {
        return ProductVariant::query()
            ->with(['product', 'optionValues'])
            ->addSelect([
                'physical_stock' => LocationInventory::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_variant_id', 'product_variants.id'),

                'committed_quantity' => OrderItem::query()
                    ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                    ->whereColumn('order_items.product_variant_id', 'product_variants.id')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereIn('orders.fulfillment_status', [
                        FulfillmentStatus::UNFULFILLED->value,
                        FulfillmentStatus::AWAITING_SHIPMENT->value,
                    ]),

                'needs_fix_quantity' => InventoryMovement::query()
                    ->selectRaw('COALESCE(SUM(ABS(quantity)), 0)')
                    ->whereColumn('product_variant_id', 'product_variants.id')
                    ->where('type', InventoryMovementType::NeedsFix->value),

                'damaged_quantity' => InventoryMovement::query()
                    ->selectRaw('COALESCE(SUM(ABS(quantity)), 0)')
                    ->whereColumn('product_variant_id', 'product_variants.id')
                    ->whereIn('type', [
                        InventoryMovementType::Damaged->value,
                        InventoryMovementType::Lost->value,
                    ]),
            ])
            ->get()
            ->map(function (ProductVariant $v): ProductVariant {
                $physicalPlusCondition = (int) $v->physical_stock
                    + (int) $v->needs_fix_quantity
                    + (int) $v->damaged_quantity;

                $v->shortfall = max(0, -$physicalPlusCondition);

                return $v;
            })
            ->filter(fn (ProductVariant $v) => $v->shortfall > 0)
            ->sortBy(fn (ProductVariant $v) => $v->product->title)
            ->groupBy(fn (ProductVariant $v) => $v->product->title)
            ->map(function (Collection $variants): Collection {
                return $variants
                    ->groupBy(fn (ProductVariant $v) => $v->optionValues->first()?->getTranslation('value', 'tr') ?? '—')
                    ->map(function (Collection $colorVariants): Collection {
                        return $colorVariants->mapWithKeys(function (ProductVariant $v): array {
                            $size = $v->optionValues->skip(1)->first()?->getTranslation('value', 'tr') ?? '—';

                            return [$size => $v->shortfall];
                        });
                    });
            });
    }

    public function getMessageText(): string
    {
        $grouped = $this->getGroupedItems();

        if ($grouped->isEmpty()) {
            return '';
        }

        $lines = [];

        foreach ($grouped as $productTitle => $colorGroups) {
            $lines[] = $productTitle;

            foreach ($colorGroups as $color => $sizes) {
                $lines[] = $color;

                foreach ($sizes as $size => $needed) {
                    $lines[] = " - {$size}: {$needed}";
                }
            }

            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }
}
