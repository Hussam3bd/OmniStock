<?php

namespace App\Filament\Widgets;

use App\Models\Inventory\LocationInventory;
use App\Models\Product\ProductVariant;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

class OversoldItemsWidget extends Widget
{
    protected string $view = 'filament.widgets.oversold-items-widget';

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    /**
     * Returns variants with negative available stock grouped as:
     * product_title → color → [ size => abs(available) ]
     */
    public function getGroupedItems(): Collection
    {
        return ProductVariant::query()
            ->with(['product', 'optionValues'])
            ->addSelect([
                'available_quantity' => LocationInventory::query()
                    ->selectRaw('COALESCE(SUM(quantity), 0)')
                    ->whereColumn('product_variant_id', 'product_variants.id'),
            ])
            ->get()
            ->filter(fn (ProductVariant $v) => (int) ($v->available_quantity ?? 0) < 0)
            ->sortBy(fn (ProductVariant $v) => $v->product->title)
            ->groupBy(fn (ProductVariant $v) => $v->product->title)
            ->map(function (Collection $variants): Collection {
                return $variants
                    ->groupBy(fn (ProductVariant $v) => $v->optionValues->first()?->getTranslation('value', 'tr') ?? '—')
                    ->map(function (Collection $colorVariants): Collection {
                        return $colorVariants->mapWithKeys(function (ProductVariant $v): array {
                            $size = $v->optionValues->skip(1)->first()?->getTranslation('value', 'tr') ?? '—';

                            return [$size => abs((int) ($v->available_quantity ?? 0))];
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
