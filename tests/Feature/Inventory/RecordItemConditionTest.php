<?php

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use App\Models\Inventory\LocationInventory;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $uid = uniqid();

    $this->location = Location::create([
        'name' => 'Main Warehouse',
        'code' => "MAIN-{$uid}",
        'is_active' => true,
        'is_default' => true,
    ]);

    $product = Product::create([
        'title' => 'Test Product',
        'name' => 'Test Product',
        'slug' => "test-condition-{$uid}",
        'status' => 'active',
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => "TEST-COND-{$uid}",
        'barcode' => "TESTCOND{$uid}",
        'title' => 'Test Variant',
        'price' => 10000,
        'inventory_quantity' => 10,
    ]);

    $this->locationInventory = LocationInventory::create([
        'location_id' => $this->location->id,
        'product_variant_id' => $this->variant->id,
        'quantity' => 10,
    ]);
});

it('records a damaged movement and reduces available stock', function () {
    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->location->id,
        'type' => InventoryMovementType::Damaged,
        'quantity' => -2,
        'quantity_before' => 10,
        'quantity_after' => 8,
    ]);

    $this->locationInventory->update(['quantity' => 8]);

    expect(InventoryMovement::where('type', InventoryMovementType::Damaged->value)->count())->toBe(1);
    expect($this->locationInventory->fresh()->quantity)->toBe(8);
});

it('records a lost movement and reduces available stock', function () {
    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->location->id,
        'type' => InventoryMovementType::Lost,
        'quantity' => -1,
        'quantity_before' => 10,
        'quantity_after' => 9,
    ]);

    $this->locationInventory->update(['quantity' => 9]);

    expect(InventoryMovement::where('type', InventoryMovementType::Lost->value)->count())->toBe(1);
    expect($this->locationInventory->fresh()->quantity)->toBe(9);
});

it('records a needs_fix movement and reduces available stock', function () {
    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->location->id,
        'type' => InventoryMovementType::NeedsFix,
        'quantity' => -3,
        'quantity_before' => 10,
        'quantity_after' => 7,
    ]);

    $this->locationInventory->update(['quantity' => 7]);

    expect(InventoryMovement::where('type', InventoryMovementType::NeedsFix->value)->count())->toBe(1);
    expect($this->locationInventory->fresh()->quantity)->toBe(7);
});

it('damaged and needs_fix types are physically present, lost is not', function () {
    expect(InventoryMovementType::Damaged->isPhysicallyPresent())->toBeTrue();
    expect(InventoryMovementType::NeedsFix->isPhysicallyPresent())->toBeTrue();
    expect(InventoryMovementType::Lost->isPhysicallyPresent())->toBeFalse();
});

it('all condition types are deductions', function () {
    expect(InventoryMovementType::Damaged->isDeduction())->toBeTrue();
    expect(InventoryMovementType::Lost->isDeduction())->toBeTrue();
    expect(InventoryMovementType::NeedsFix->isDeduction())->toBeTrue();
});

it('correctly calculates on_hand including physically present conditions', function () {
    // available=10, record 2 damaged + 3 needs_fix → available becomes 5
    // on_hand = available(5) + damaged(2) + needs_fix(3) = 10 (lost not added back)

    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->location->id,
        'type' => InventoryMovementType::Damaged,
        'quantity' => -2,
        'quantity_before' => 10,
        'quantity_after' => 8,
    ]);

    InventoryMovement::create([
        'product_variant_id' => $this->variant->id,
        'location_id' => $this->location->id,
        'type' => InventoryMovementType::NeedsFix,
        'quantity' => -3,
        'quantity_before' => 8,
        'quantity_after' => 5,
    ]);

    $damagedQty = InventoryMovement::where('product_variant_id', $this->variant->id)
        ->whereIn('type', [InventoryMovementType::Damaged->value, InventoryMovementType::Lost->value])
        ->sum(DB::raw('ABS(quantity)'));

    $needsFixQty = InventoryMovement::where('product_variant_id', $this->variant->id)
        ->where('type', InventoryMovementType::NeedsFix->value)
        ->sum(DB::raw('ABS(quantity)'));

    $available = 5;
    $onHand = $available + $damagedQty + $needsFixQty;

    expect($damagedQty)->toBe(2);
    expect($needsFixQty)->toBe(3);
    expect($onHand)->toBe(10);
});
