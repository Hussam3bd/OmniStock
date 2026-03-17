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
     * Returns variants where committed (pending orders) exceeds physical stock,
     * grouped as: product_title → color → [ size => shortfall ]
     */
    public function getGroupedItems(): Collection
    {
        $pendingStatuses = [
            FulfillmentStatus::UNFULFILLED->value,
            FulfillmentStatus::AWAITING_SHIPMENT->value,
        ];

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
                    ->whereIn('orders.fulfillment_status', $pendingStatuses),
            ])
            ->get()
            ->filter(fn (ProductVariant $v) => (int) ($v->committed_quantity ?? 0) > (int) ($v->physical_stock ?? 0))
            ->map(function (ProductVariant $v): ProductVariant {
                $v->shortfall = (int) $v->committed_quantity - (int) $v->physical_stock;

                return $v;
            })
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
