<?php

namespace App\Observers;

use App\Models\Purchase\PurchaseOrderItem;

class PurchaseOrderItemObserver
{
    /**
     * Handle the PurchaseOrderItem "saving" event.
     */
    public function saving(PurchaseOrderItem $purchaseOrderItem): void
    {
        // Calculate subtotal (quantity × unit cost)
        // unit_cost is a Money object, multiply by quantity
        $subtotal = $purchaseOrderItem->unit_cost->multiply($purchaseOrderItem->quantity_ordered);
        $purchaseOrderItem->subtotal = $subtotal;

        // Calculate tax amount (subtotal × tax rate / 100)
        $taxAmount = $subtotal->multiply($purchaseOrderItem->tax_rate / 100);
        $purchaseOrderItem->tax_amount = $taxAmount;

        // Calculate total (subtotal + tax)
        $purchaseOrderItem->total = $subtotal->add($taxAmount);
    }

    /**
     * Handle the PurchaseOrderItem "saved" event.
     */
    public function saved(PurchaseOrderItem $purchaseOrderItem): void
    {
        // Recalculate the parent purchase order totals
        $this->recalculatePurchaseOrderTotals($purchaseOrderItem);
    }

    /**
     * Handle the PurchaseOrderItem "deleted" event.
     */
    public function deleted(PurchaseOrderItem $purchaseOrderItem): void
    {
        // Recalculate the parent purchase order totals
        $this->recalculatePurchaseOrderTotals($purchaseOrderItem);
    }

    /**
     * Recalculate the purchase order totals based on its items.
     */
    protected function recalculatePurchaseOrderTotals(PurchaseOrderItem $purchaseOrderItem): void
    {
        $purchaseOrder = $purchaseOrderItem->purchaseOrder;

        if (! $purchaseOrder) {
            return;
        }

        // Calculate subtotal from all items (sum returns integer, convert to Money)
        $subtotalAmount = $purchaseOrder->items()->sum('subtotal');
        $purchaseOrder->subtotal = money($subtotalAmount);

        // Calculate tax from all items
        $taxAmount = $purchaseOrder->items()->sum('tax_amount');
        $purchaseOrder->tax = money($taxAmount);

        // Calculate total (subtotal + tax + shipping_cost)
        $shippingCost = $purchaseOrder->shipping_cost ?? money(0);
        $purchaseOrder->total = $purchaseOrder->subtotal
            ->add($purchaseOrder->tax)
            ->add($shippingCost);

        // Save without triggering events to avoid infinite loop
        $purchaseOrder->saveQuietly();
    }
}
