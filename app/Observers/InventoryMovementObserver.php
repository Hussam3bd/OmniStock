<?php

namespace App\Observers;

use App\Models\Inventory\InventoryMovement;

class InventoryMovementObserver
{
    public function created(InventoryMovement $inventoryMovement): void
    {
        $inventoryMovement->productVariant?->syncInventoryQuantity();
    }

    public function deleted(InventoryMovement $inventoryMovement): void
    {
        $inventoryMovement->productVariant?->syncInventoryQuantity();
    }

    public function forceDeleted(InventoryMovement $inventoryMovement): void
    {
        $inventoryMovement->productVariant?->syncInventoryQuantity();
    }
}
