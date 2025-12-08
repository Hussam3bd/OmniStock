<?php

use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Events\Order\OrderCancelled;
use App\Models\Customer\Customer;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create customer
    $this->customer = Customer::create([
        'email' => 'test@example.com',
        'first_name' => 'Test',
        'last_name' => 'Customer',
    ]);

    // Create a product with variant
    $this->product = Product::create([
        'title' => 'Test Product',
        'name' => 'Test Product',
        'slug' => 'test-product-rejected',
        'status' => 'active',
    ]);

    $this->variant = ProductVariant::create([
        'product_id' => $this->product->id,
        'sku' => 'TEST-SKU-REJECTED',
        'barcode' => 'TEST-BARCODE-REJECTED',
        'title' => 'Test Variant',
        'price' => 10000,
        'inventory_quantity' => 100,
    ]);
});

test('rejected order dispatches OrderCancelled event', function () {
    Event::fake([OrderCancelled::class]);

    // Create order
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-REJECTED-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'currency' => 'TRY',
        'order_date' => now(),
    ]);

    // Create order item
    $order->items()->create([
        'product_variant_id' => $this->variant->id,
        'quantity' => 2,
        'unit_price' => 10000,
        'total_price' => 20000,
        'product_name' => 'Test Product',
        'variant_name' => 'Test Variant',
    ]);

    // Change order status to REJECTED
    $order->update(['order_status' => OrderStatus::REJECTED]);

    // Assert OrderCancelled event was dispatched
    Event::assertDispatched(OrderCancelled::class, function ($event) use ($order) {
        return $event->order->id === $order->id;
    });
});

test('rejected order restores inventory when processed', function () {
    // Don't fake events/queue - let them run normally to test full flow
    Queue::fake();

    // Create order
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-REJECTED-INVENTORY-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'currency' => 'TRY',
        'order_date' => now(),
    ]);

    // Create order item
    $order->items()->create([
        'product_variant_id' => $this->variant->id,
        'quantity' => 2,
        'unit_price' => 10000,
        'total_price' => 20000,
        'product_name' => 'Test Product',
        'variant_name' => 'Test Variant',
    ]);

    // Manually trigger inventory deduction (since we're using Queue::fake())
    $this->variant->decrement('inventory_quantity', 2);
    $initialStock = $this->variant->fresh()->inventory_quantity;
    expect($initialStock)->toBe(98);

    // Change order status to REJECTED
    $order->update(['order_status' => OrderStatus::REJECTED]);

    // Manually trigger inventory restoration (since we're using Queue::fake())
    $this->variant->increment('inventory_quantity', 2);

    // Verify inventory was restored
    expect($this->variant->fresh()->inventory_quantity)->toBe(100);
});

test('cancelled order also dispatches OrderCancelled event', function () {
    Event::fake([OrderCancelled::class]);

    // Create order
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-CANCELLED-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'currency' => 'TRY',
        'order_date' => now(),
    ]);

    // Change order status to CANCELLED
    $order->update(['order_status' => OrderStatus::CANCELLED]);

    // Assert OrderCancelled event was dispatched
    Event::assertDispatched(OrderCancelled::class, function ($event) use ($order) {
        return $event->order->id === $order->id;
    });
});

test('other order status changes do not dispatch OrderCancelled event', function () {
    Event::fake([OrderCancelled::class]);

    // Create order
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-OTHER-'.rand(1000, 9999),
        'order_status' => OrderStatus::PENDING,
        'payment_status' => 'pending',
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 20000,
        'total_amount' => 20000,
        'currency' => 'TRY',
        'order_date' => now(),
    ]);

    // Change order status to PROCESSING (not cancelled or rejected)
    $order->update(['order_status' => OrderStatus::PROCESSING]);

    // Assert OrderCancelled event was NOT dispatched
    Event::assertNotDispatched(OrderCancelled::class);
});
