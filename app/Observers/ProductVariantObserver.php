<?php

namespace App\Observers;

use App\Jobs\Integration\SyncVariantStockToChannelsJob;
use App\Models\Product\ProductVariant;

class ProductVariantObserver
{
    public function updated(ProductVariant $variant): void
    {
        if (! $variant->wasChanged('inventory_quantity')) {
            return;
        }

        SyncVariantStockToChannelsJob::dispatch($variant->id)
            ->afterCommit()
            ->delay(now()->addSeconds(5));
    }
}
