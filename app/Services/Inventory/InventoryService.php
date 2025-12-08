<?php

namespace App\Services\Inventory;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use App\Models\Inventory\LocationInventory;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Models\Product\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    /**
     * Deduct inventory for a single order item
     */
    public function deductInventoryForOrderItem(\App\Models\Order\OrderItem $orderItem): void
    {
        DB::transaction(function () use ($orderItem) {
            $variant = $orderItem->productVariant;
            $order = $orderItem->order;

            if (! $variant || ! $order) {
                return;
            }

            // Get location from order's integration or fall back to default
            $location = $this->getLocationForOrder($order, $variant);

            if (! $location) {
                Log::warning('No location found for inventory deduction', [
                    'order_id' => $order->id,
                    'order_item_id' => $orderItem->id,
                    'variant_id' => $variant->id,
                ]);

                return;
            }

            // Deduct quantity
            $quantityToDeduct = $orderItem->quantity;

            $this->adjustInventory(
                variant: $variant,
                location: $location,
                quantity: -$quantityToDeduct, // Negative for deduction
                type: InventoryMovementType::Sale,
                orderId: $order->id,
                reference: "Order #{$order->order_number}",
                notes: "Order item created - deducted {$quantityToDeduct} units"
            );
        });
    }

    /**
     * Deduct inventory when an order is created
     *
     * @deprecated Use OrderItemObserver instead
     */
    public function deductInventoryForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                $variant = $item->productVariant;

                if (! $variant) {
                    Log::warning('Product variant not found for order item', [
                        'order_id' => $order->id,
                        'item_id' => $item->id,
                    ]);

                    continue;
                }

                // Get location from order's integration or fall back to default
                $location = $this->getLocationForOrder($order, $variant);

                if (! $location) {
                    Log::warning('No location found for inventory deduction', [
                        'order_id' => $order->id,
                        'variant_id' => $variant->id,
                    ]);

                    continue;
                }

                // Check if movement already exists (idempotency)
                $existingMovement = InventoryMovement::where('order_id', $order->id)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', InventoryMovementType::Sale->value)
                    ->first();

                if ($existingMovement) {
                    Log::info('Inventory movement already exists, skipping', [
                        'order_id' => $order->id,
                        'variant_id' => $variant->id,
                    ]);

                    continue;
                }

                // Deduct quantity
                $quantityToDeduct = $item->quantity;

                $this->adjustInventory(
                    variant: $variant,
                    location: $location,
                    quantity: -$quantityToDeduct, // Negative for deduction
                    type: InventoryMovementType::Sale,
                    orderId: $order->id,
                    reference: "Order #{$order->order_number}",
                    notes: "Order created - deducted {$quantityToDeduct} units"
                );
            }
        });
    }

    /**
     * Restore inventory when a return is completed
     */
    public function restoreInventoryForReturn(OrderReturn $return): void
    {
        DB::transaction(function () use ($return) {
            foreach ($return->items as $returnItem) {
                $variant = $returnItem->orderItem?->productVariant;

                if (! $variant) {
                    Log::warning('Product variant not found for return item', [
                        'return_id' => $return->id,
                        'return_item_id' => $returnItem->id,
                    ]);

                    continue;
                }

                // Find the original movement to get the location
                $originalMovement = InventoryMovement::where('order_id', $return->order_id)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', InventoryMovementType::Sale->value)
                    ->first();

                $location = $originalMovement?->location ?? $this->getDefaultLocation($variant);

                if (! $location) {
                    Log::warning('No location found for inventory restoration', [
                        'return_id' => $return->id,
                        'variant_id' => $variant->id,
                    ]);

                    continue;
                }

                // Check if movement already exists (idempotency)
                $existingMovement = InventoryMovement::where('order_id', $return->order_id)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', InventoryMovementType::Return->value)
                    ->where('reference', 'LIKE', "%{$return->return_number}%")
                    ->first();

                if ($existingMovement) {
                    Log::info('Return inventory movement already exists, skipping', [
                        'return_id' => $return->id,
                        'variant_id' => $variant->id,
                    ]);

                    continue;
                }

                // Restore quantity
                $quantityToRestore = $returnItem->quantity;

                $this->adjustInventory(
                    variant: $variant,
                    location: $location,
                    quantity: $quantityToRestore, // Positive for restoration
                    type: InventoryMovementType::Return,
                    orderId: $return->order_id,
                    reference: "Return #{$return->return_number}",
                    notes: "Return completed - restored {$quantityToRestore} units"
                );
            }
        });
    }

    /**
     * Restore inventory when an order is cancelled
     */
    public function restoreInventoryForCancellation(Order $order): void
    {
        DB::transaction(function () use ($order) {
            foreach ($order->items as $item) {
                $variant = $item->productVariant;

                if (! $variant) {
                    continue;
                }

                // Find the original movement to get the location
                $originalMovement = InventoryMovement::where('order_id', $order->id)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', InventoryMovementType::Sale->value)
                    ->first();

                if (! $originalMovement) {
                    // No original deduction, nothing to restore
                    continue;
                }

                $location = $originalMovement->location;

                if (! $location) {
                    continue;
                }

                // Check if movement already exists (idempotency)
                $existingMovement = InventoryMovement::where('order_id', $order->id)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', InventoryMovementType::Cancellation->value)
                    ->first();

                if ($existingMovement) {
                    continue;
                }

                // Restore quantity
                $quantityToRestore = $item->quantity;

                $this->adjustInventory(
                    variant: $variant,
                    location: $location,
                    quantity: $quantityToRestore, // Positive for restoration
                    type: InventoryMovementType::Cancellation,
                    orderId: $order->id,
                    reference: "Order #{$order->order_number} cancelled",
                    notes: "Order cancelled - restored {$quantityToRestore} units"
                );
            }
        });
    }

    /**
     * Core method to adjust inventory and create movement record
     */
    protected function adjustInventory(
        ProductVariant $variant,
        Location $location,
        int $quantity,
        InventoryMovementType $type,
        ?int $orderId = null,
        ?string $reference = null,
        ?string $notes = null
    ): void {
        // Get or create location inventory record
        $locationInventory = LocationInventory::firstOrCreate(
            [
                'location_id' => $location->id,
                'product_variant_id' => $variant->id,
            ],
            ['quantity' => 0]
        );

        // Lock the row for update to prevent race conditions
        $locationInventory = LocationInventory::where('id', $locationInventory->id)
            ->lockForUpdate()
            ->first();

        $quantityBefore = $locationInventory->quantity;
        $quantityAfter = $quantityBefore + $quantity;

        // Update inventory
        $locationInventory->update(['quantity' => $quantityAfter]);

        // Create movement record
        InventoryMovement::create([
            'product_variant_id' => $variant->id,
            'location_id' => $location->id,
            'order_id' => $orderId,
            'type' => $type->value,
            'quantity' => $quantity,
            'quantity_before' => $quantityBefore,
            'quantity_after' => $quantityAfter,
            'reference' => $reference,
            'notes' => $notes,
        ]);

        // Log activity
        activity()
            ->performedOn($locationInventory)
            ->withProperties([
                'variant_id' => $variant->id,
                'variant_sku' => $variant->sku,
                'location_id' => $location->id,
                'location_name' => $location->name,
                'type' => $type->value,
                'quantity_change' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'order_id' => $orderId,
            ])
            ->log("inventory_{$type->value}");

        // Warn if stock is low or negative
        if ($quantityAfter < 0) {
            Log::warning('Negative inventory after adjustment', [
                'variant_id' => $variant->id,
                'variant_sku' => $variant->sku,
                'location_id' => $location->id,
                'quantity_after' => $quantityAfter,
                'type' => $type->value,
            ]);
        }

        // Sync the variant's inventory_quantity with the total across all locations
        $variant->syncInventoryQuantity();
    }

    /**
     * Get location for an order based on integration configuration
     * If order has integration with location_id, use that
     * Otherwise fall back to default location strategy
     */
    protected function getLocationForOrder(Order $order, ProductVariant $variant): ?Location
    {
        // Check if order has an integration with a configured location
        if ($order->integration_id && $order->integration) {
            $locationId = $order->integration->location_id;

            if ($locationId) {
                $location = Location::find($locationId);

                if ($location) {
                    return $location;
                }

                Log::warning('Integration location not found, falling back to default', [
                    'order_id' => $order->id,
                    'integration_id' => $order->integration_id,
                    'location_id' => $locationId,
                ]);
            }
        }

        // Fall back to default location strategy
        return $this->getDefaultLocation($variant);
    }

    /**
     * Get the default location for a variant
     * Strategy: First location, or location with most stock
     */
    protected function getDefaultLocation(ProductVariant $variant): ?Location
    {
        // Try to find location with existing inventory
        $locationWithStock = LocationInventory::where('product_variant_id', $variant->id)
            ->where('quantity', '>', 0)
            ->orderBy('quantity', 'desc')
            ->first();

        if ($locationWithStock) {
            return $locationWithStock->location;
        }

        // Fall back to first location
        return Location::first();
    }
}
