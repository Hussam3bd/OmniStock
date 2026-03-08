<?php

namespace App\Filament\Widgets;

use App\Enums\Order\FulfillmentStatus;
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
     * Returns oversold variants grouped as:
     * product_title → color → [ size => needed_qty ]
     */
    public function getGroupedItems(): Collection
    {
        return ProductVariant::query()
            ->with(['product', 'optionValues'])
            ->addSelect([
                // Live available stock from location_inventory (source of truth)
                'available_quantity' => LocationInventory::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_variant_id', 'product_variants.id'),

                // Committed = units in pending unfulfilled orders
                'committed_quantity' => OrderItem::query()
                    ->selectRaw('COALESCE(SUM(order_items.quantity), 0)')
                    ->join('orders', 'orders.id', '=', 'order_items.order_id')
                    ->whereColumn('order_items.product_variant_id', 'product_variants.id')
                    ->whereIn('orders.fulfillment_status', [
                        FulfillmentStatus::UNFULFILLED->value,
                        FulfillmentStatus::AWAITING_SHIPMENT->value,
                    ]),
            ])
            ->get()
            ->filter(function (ProductVariant $variant): bool {
                $available = max(0, (int) ($variant->available_quantity ?? 0));
                $committed = (int) ($variant->committed_quantity ?? 0);

                return $committed > $available;
            })
            ->sortBy(fn (ProductVariant $v) => $v->product->title)
            ->groupBy(fn (ProductVariant $v) => $v->product->title)
            ->map(function (Collection $variants): Collection {
                return $variants
                    ->groupBy(fn (ProductVariant $v) => $v->optionValues->first()?->getTranslation('value', 'tr') ?? '—')
                    ->map(function (Collection $colorVariants): Collection {
                        return $colorVariants->mapWithKeys(function (ProductVariant $v): array {
                            $available = max(0, (int) ($v->available_quantity ?? 0));
                            $committed = (int) ($v->committed_quantity ?? 0);
                            $size = $v->optionValues->skip(1)->first()?->getTranslation('value', 'tr') ?? '—';

                            return [$size => $committed - $available];
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
