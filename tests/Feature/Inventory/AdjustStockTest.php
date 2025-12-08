<?php

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use App\Models\Inventory\LocationInventory;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Unique code for each test run
    $uniqueId = uniqid();

    // Create locations
    $this->mainWarehouse = Location::create([
        'name' => 'Main Warehouse',
        'code' => "MAIN-{$uniqueId}",
        'is_active' => true,
        'is_default' => true,
    ]);

    $this->secondWarehouse = Location::create([
        'name' => 'Second Warehouse',
        'code' => "SEC-{$uniqueId}",
        'is_active' => true,
        'is_default' => false,
    ]);

    // Create product and variant
    $this->product = Product::create([
        'title' => 'Test Product',
        'name' => 'Test Product',
        'slug' => "test-product-inventory-{$uniqueId}",
        'status' => 'active',
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => "TEST-INV-{$uniqueId}",
        'barcode' => "TESTINV{$uniqueId}",
        'title' => 'Test Variant',
        'price' => 10000,
        'inventory_quantity' => 0,
    ]);

    // Create initial location inventory
    $this->locationInventory = LocationInventory::create([
        'location_id' => $this->mainWarehouse->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 100,
    ]);

    // Sync variant inventory
    $this->variant->syncInventoryQuantity();
});

test('location inventory updates correctly when adding stock', function () {
    $initialQuantity = $this->locationInventory->quantity;

    // Add 50 units using InventoryMovement
    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->mainWarehouse->id,
        'type' => InventoryMovementType::Adjustment,
        'quantity' => 50,
        'quantity_before' => $initialQuantity,
        'quantity_after' => $initialQuantity + 50,
    ]);

    // Update location inventory
    $this->locationInventory->increment('quantity', 50);

    // Sync variant total
    $this->variant->syncInventoryQuantity();

    // Verify location inventory
    expect($this->locationInventory->fresh()->quantity)->toBe(150);

    // Verify variant total inventory
    expect($this->variant->fresh()->inventory_quantity)->toBe(150);
});

test('location inventory updates correctly when reducing stock', function () {
    $initialQuantity = $this->locationInventory->quantity;

    // Reduce 30 units
    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->mainWarehouse->id,
        'type' => InventoryMovementType::Damaged,
        'quantity' => -30,
        'quantity_before' => $initialQuantity,
        'quantity_after' => $initialQuantity - 30,
    ]);

    // Update location inventory
    $this->locationInventory->decrement('quantity', 30);

    // Sync variant total
    $this->variant->syncInventoryQuantity();

    // Verify location inventory
    expect($this->locationInventory->fresh()->quantity)->toBe(70);

    // Verify variant total inventory
    expect($this->variant->fresh()->inventory_quantity)->toBe(70);
});

test('variant inventory quantity is sum of all locations', function () {
    // Add inventory to second location
    $secondLocationInventory = LocationInventory::create([
        'location_id' => $this->secondWarehouse->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 50,
    ]);

    // Sync variant total
    $this->variant->syncInventoryQuantity();

    // Verify variant total is sum of both locations
    expect($this->variant->fresh()->inventory_quantity)->toBe(150); // 100 + 50
});

test('sync inventory quantity recalculates from all locations', function () {
    // Manually set variant inventory_quantity to wrong value
    $this->variant->update(['inventory_quantity' => 999]);

    // Verify it's wrong
    expect($this->variant->inventory_quantity)->toBe(999);

    // Sync should fix it
    $this->variant->syncInventoryQuantity();

    // Verify it's now correct (sum of location inventories)
    expect($this->variant->fresh()->inventory_quantity)->toBe(100);
});

test('inventory movement must have location_id', function () {
    // Create movement with location_id
    $movement = InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->mainWarehouse->id,
        'type' => InventoryMovementType::Adjustment,
        'quantity' => 10,
        'quantity_before' => 100,
        'quantity_after' => 110,
    ]);

    expect($movement->location_id)->toBe($this->mainWarehouse->id);
    expect($movement->location)->toBeInstanceOf(Location::class);
});

test('multiple adjustments at different locations work correctly', function () {
    // Add 25 to main warehouse
    LocationInventory::where('id', $this->locationInventory->id)
        ->update(['quantity' => 125]);

    // Create second location inventory with 75
    LocationInventory::create([
        'location_id' => $this->secondWarehouse->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 75,
    ]);

    // Sync variant total
    $this->variant->syncInventoryQuantity();

    // Verify total
    expect($this->variant->fresh()->inventory_quantity)->toBe(200); // 125 + 75

    // Verify individual locations
    $mainInventory = LocationInventory::where('location_id', $this->mainWarehouse->id)
        ->where('product_variant_id', $this->variant->id)
        ->first();
    expect($mainInventory->quantity)->toBe(125);

    $secondInventory = LocationInventory::where('location_id', $this->secondWarehouse->id)
        ->where('product_variant_id', $this->variant->id)
        ->first();
    expect($secondInventory->quantity)->toBe(75);
});

test('inventory movement records track all changes', function () {
    // Create multiple movements
    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->mainWarehouse->id,
        'type' => InventoryMovementType::PurchaseReceived,
        'quantity' => 50,
        'quantity_before' => 100,
        'quantity_after' => 150,
    ]);

    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->mainWarehouse->id,
        'type' => InventoryMovementType::Sale,
        'quantity' => -10,
        'quantity_before' => 150,
        'quantity_after' => 140,
    ]);

    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->mainWarehouse->id,
        'type' => InventoryMovementType::Return,
        'quantity' => 5,
        'quantity_before' => 140,
        'quantity_after' => 145,
    ]);

    // Verify all movements are recorded
    $movements = InventoryMovement::where('product_variant_id', $this->variant->id)
        ->orderBy('created_at')
        ->get();

    expect($movements)->toHaveCount(3);
    expect($movements[0]->type)->toBe(InventoryMovementType::PurchaseReceived);
    expect($movements[1]->type)->toBe(InventoryMovementType::Sale);
    expect($movements[2]->type)->toBe(InventoryMovementType::Return);

    // Verify all have location_id
    expect($movements->every(fn ($m) => $m->location_id !== null))->toBeTrue();
});

test('negative inventory is allowed but tracked', function () {
    // Reduce inventory below zero
    $this->locationInventory->update(['quantity' => -10]);

    // Sync variant total
    $this->variant->syncInventoryQuantity();

    // Verify negative inventory is allowed
    expect($this->locationInventory->fresh()->quantity)->toBe(-10);
    expect($this->variant->fresh()->inventory_quantity)->toBe(-10);
});
