<?php

namespace App\Observers;

use App\Models\Purchase\PurchaseOrder;

class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "saving" event.
     */
    public function saving(PurchaseOrder $purchaseOrder): void
    {
        // Initialize money fields if null (new record)
        $subtotal = $purchaseOrder->subtotal ?? money(0);
        $tax = $purchaseOrder->tax ?? money(0);
        $shippingCost = $purchaseOrder->shipping_cost ?? money(0);

        // If shipping_cost changed, recalculate total
        if ($purchaseOrder->isDirty('shipping_cost')) {
            $purchaseOrder->total = $subtotal
                ->add($tax)
                ->add($shippingCost);
        }
    }
}
