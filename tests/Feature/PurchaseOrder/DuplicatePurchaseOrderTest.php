<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\Currency;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Purchase\PurchaseOrderItem;
use App\Models\Supplier\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->currency = Currency::create([
        'code' => 'TRY',
        'name' => 'Turkish Lira',
        'symbol' => '₺',
        'decimal_places' => 2,
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->supplier = Supplier::create([
        'name' => 'Test Supplier',
        'code' => 'TEST-SUP-'.uniqid(),
    ]);

    $this->product = Product::create([
        'title' => 'Test Product',
        'model_code' => 'TP-001',
        'status' => 'active',
    ]);

    $this->variant1 = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'SKU-001',
        'barcode' => 'BAR-001',
        'title' => 'Variant 1',
        'price' => 5000,
        'cost_price' => 3000,
    ]);

    $this->variant2 = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'SKU-002',
        'barcode' => 'BAR-002',
        'title' => 'Variant 2',
        'price' => 8000,
        'cost_price' => 5000,
    ]);

    $this->purchaseOrder = PurchaseOrder::create([
        'order_number' => 'PO-ORIGINAL-001',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->currency->id,
        'exchange_rate' => 1.0,
        'status' => PurchaseOrderStatus::Ordered,
        'order_date' => now(),
        'expected_delivery_date' => now()->addDays(7),
        'received_date' => now(),
        'subtotal' => 8000,
        'tax' => 1440,
        'shipping_cost' => 500,
        'total' => 9940,
    ]);

    PurchaseOrderItem::create([
        'purchase_order_id' => $this->purchaseOrder->id,
        'product_variant_id' => $this->variant1->id,
        'quantity_ordered' => 10,
        'quantity_received' => 5,
        'unit_cost' => 3000,
        'tax_rate' => 18.00,
        'subtotal' => 30000,
        'tax_amount' => 5400,
        'total' => 35400,
    ]);

    PurchaseOrderItem::create([
        'purchase_order_id' => $this->purchaseOrder->id,
        'product_variant_id' => $this->variant2->id,
        'quantity_ordered' => 5,
        'quantity_received' => 3,
        'unit_cost' => 5000,
        'tax_rate' => 18.00,
        'subtotal' => 25000,
        'tax_amount' => 4500,
        'total' => 29500,
    ]);
});

it('creates a duplicate purchase order with draft status and new order number', function () {
    $original = $this->purchaseOrder;

    $replica = $original->replicate(['order_number', 'status', 'received_date']);
    $replica->order_number = 'PO-'.strtoupper(uniqid());
    $replica->status = PurchaseOrderStatus::Draft;
    $replica->received_date = null;
    $replica->save();

    expect($replica->id)->not->toBe($original->id);
    expect($replica->order_number)->not->toBe($original->order_number);
    expect($replica->order_number)->toStartWith('PO-');
    expect($replica->status)->toBe(PurchaseOrderStatus::Draft);
    expect($replica->received_date)->toBeNull();
    expect($replica->supplier_id)->toBe($original->supplier_id);
    expect($replica->currency_id)->toBe($original->currency_id);
    expect((int) $replica->getRawOriginal('subtotal'))->toBe(8000);
    expect((int) $replica->getRawOriginal('total'))->toBe(9940);
});

it('copies items with quantity_received reset to zero', function () {
    $original = $this->purchaseOrder;

    $replica = $original->replicate(['order_number', 'status', 'received_date']);
    $replica->order_number = 'PO-'.strtoupper(uniqid());
    $replica->status = PurchaseOrderStatus::Draft;
    $replica->received_date = null;
    $replica->save();

    $original->load('items');
    $original->items->each(function ($item) use ($replica) {
        $replica->items()->create([
            'product_variant_id' => $item->product_variant_id,
            'quantity_ordered' => $item->quantity_ordered,
            'quantity_received' => 0,
            'unit_cost' => $item->getRawOriginal('unit_cost'),
            'tax_rate' => $item->tax_rate,
            'subtotal' => $item->getRawOriginal('subtotal'),
            'tax_amount' => $item->getRawOriginal('tax_amount'),
            'total' => $item->getRawOriginal('total'),
        ]);
    });

    $replicaItems = $replica->items()->orderBy('product_variant_id')->get();

    expect($replicaItems)->toHaveCount(2);

    expect($replicaItems[0]->product_variant_id)->toBe($this->variant1->id);
    expect($replicaItems[0]->quantity_ordered)->toBe(10);
    expect($replicaItems[0]->quantity_received)->toBe(0);
    expect((int) $replicaItems[0]->getRawOriginal('unit_cost'))->toBe(3000);

    expect($replicaItems[1]->product_variant_id)->toBe($this->variant2->id);
    expect($replicaItems[1]->quantity_ordered)->toBe(5);
    expect($replicaItems[1]->quantity_received)->toBe(0);
    expect((int) $replicaItems[1]->getRawOriginal('unit_cost'))->toBe(5000);
});

it('does not modify the original purchase order when duplicating', function () {
    $original = $this->purchaseOrder;
    $originalOrderNumber = $original->order_number;
    $originalStatus = $original->status;
    $originalItemCount = $original->items()->count();

    $replica = $original->replicate(['order_number', 'status', 'received_date']);
    $replica->order_number = 'PO-'.strtoupper(uniqid());
    $replica->status = PurchaseOrderStatus::Draft;
    $replica->received_date = null;
    $replica->save();

    $original->items->each(function ($item) use ($replica) {
        $replica->items()->create([
            'product_variant_id' => $item->product_variant_id,
            'quantity_ordered' => $item->quantity_ordered,
            'quantity_received' => 0,
            'unit_cost' => $item->getRawOriginal('unit_cost'),
            'tax_rate' => $item->tax_rate,
            'subtotal' => $item->getRawOriginal('subtotal'),
            'tax_amount' => $item->getRawOriginal('tax_amount'),
            'total' => $item->getRawOriginal('total'),
        ]);
    });

    $original->refresh();

    expect($original->order_number)->toBe($originalOrderNumber);
    expect($original->status)->toBe($originalStatus);
    expect($original->items()->count())->toBe($originalItemCount);

    $originalItems = $original->items()->orderBy('product_variant_id')->get();
    expect($originalItems[0]->quantity_received)->toBe(5);
    expect($originalItems[1]->quantity_received)->toBe(3);
});
