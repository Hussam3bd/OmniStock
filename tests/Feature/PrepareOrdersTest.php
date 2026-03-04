<?php

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Filament\Pages\PrepareOrders;
use App\Models\Currency;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Currency::create(['code' => 'TRY', 'name' => 'Turkish Lira', 'symbol' => '₺']);

    $admin = User::factory()->create();
    $admin->assignRole(
        Role::create(['name' => 'super_admin', 'guard_name' => 'web'])
    );
    $this->actingAs($admin);
});

// --- Helpers ---
function makeVariant(): ProductVariant
{
    $product = Product::create(['title' => 'Test Product']);

    return ProductVariant::create([
        'product_id' => $product->id,
        'sku' => fake()->unique()->bothify('SKU-###'),
        'barcode' => fake()->unique()->ean13(),
        'price' => 99.99,
    ]);
}

function makeOrderWithItems(int $itemCount = 1, array $orderAttributes = []): Order
{
    $order = Order::factory()->create(array_merge(
        ['fulfillment_status' => FulfillmentStatus::UNFULFILLED],
        $orderAttributes
    ));

    $variant = makeVariant();

    for ($i = 0; $i < $itemCount; $i++) {
        OrderItem::create([
            'order_id' => $order->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => 10000,
            'total_price' => 10000,
        ]);
    }

    return $order->load('items');
}

// --- Tests ---

it('renders the page', function () {
    Livewire::test(PrepareOrders::class)
        ->assertSuccessful();
});

it('shows empty state when no pending orders exist', function () {
    Livewire::test(PrepareOrders::class)
        ->assertSet('orderIds', [])
        ->assertSee('All caught up');
});

it('loads pending orders on mount', function () {
    $unfulfilled = Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED, 'channel' => OrderChannel::TRENDYOL]);
    $awaiting = Order::factory()->create(['fulfillment_status' => FulfillmentStatus::AWAITING_SHIPMENT, 'channel' => OrderChannel::SHOPIFY]);
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::FULFILLED]);

    $component = Livewire::test(PrepareOrders::class);

    expect($component->get('orderIds'))
        ->toHaveCount(2)
        ->toContain($unfulfilled->id)
        ->toContain($awaiting->id);
});

it('excludes refunded, cancelled, and returned orders from the queue', function () {
    $active = Order::factory()->create([
        'fulfillment_status' => FulfillmentStatus::UNFULFILLED,
        'order_status' => OrderStatus::PROCESSING,
    ]);

    foreach ([OrderStatus::REFUNDED, OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::REJECTED, OrderStatus::FAILED] as $status) {
        Order::factory()->create([
            'fulfillment_status' => FulfillmentStatus::UNFULFILLED,
            'order_status' => $status,
        ]);
    }

    expect(Livewire::test(PrepareOrders::class)->get('orderIds'))
        ->toHaveCount(1)
        ->toContain($active->id);
});

it('starts at index 0', function () {
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    Livewire::test(PrepareOrders::class)
        ->assertSet('currentIndex', 0);
});

it('toggles an item as checked', function () {
    $order = makeOrderWithItems(1);
    $itemId = $order->items->first()->id;

    $component = Livewire::test(PrepareOrders::class);

    expect($component->get("checkedItems.{$itemId}"))->toBeFalsy();

    $component->call('toggleItem', $itemId);

    expect($component->get("checkedItems.{$itemId}"))->toBeTrue();
});

it('toggles an item back to unchecked', function () {
    $order = makeOrderWithItems(1);
    $itemId = $order->items->first()->id;

    Livewire::test(PrepareOrders::class)
        ->call('toggleItem', $itemId)
        ->call('toggleItem', $itemId)
        ->assertSet("checkedItems.{$itemId}", false);
});

it('does not advance to next order without all items checked', function () {
    makeOrderWithItems(2);
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    Livewire::test(PrepareOrders::class)
        ->set('confirmed', true)
        ->call('nextOrder')
        ->assertSet('currentIndex', 0);
});

it('does not advance to next order without confirmation', function () {
    $order = makeOrderWithItems(1);
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    $itemId = $order->items->first()->id;

    Livewire::test(PrepareOrders::class)
        ->call('toggleItem', $itemId)
        ->call('nextOrder')
        ->assertSet('currentIndex', 0);
});

it('advances to next order when all items checked and confirmed', function () {
    $order = makeOrderWithItems(1);
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    $itemId = $order->items->first()->id;

    Livewire::test(PrepareOrders::class)
        ->call('toggleItem', $itemId)
        ->set('confirmed', true)
        ->call('nextOrder')
        ->assertSet('currentIndex', 1)
        ->assertSet('confirmed', false)
        ->assertSet('checkedItems', []);
});

it('can navigate to previous order', function () {
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    Livewire::test(PrepareOrders::class)
        ->set('currentIndex', 1)
        ->call('previousOrder')
        ->assertSet('currentIndex', 0);
});

it('does not go below index 0 on previous', function () {
    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    Livewire::test(PrepareOrders::class)
        ->call('previousOrder')
        ->assertSet('currentIndex', 0);
});

it('shows done state after completing last order', function () {
    $order = makeOrderWithItems(1);
    $itemId = $order->items->first()->id;

    Livewire::test(PrepareOrders::class)
        ->call('toggleItem', $itemId)
        ->set('confirmed', true)
        ->call('nextOrder')
        ->assertSee('orders prepared');
});

it('reloads the queue on loadQueue call', function () {
    $component = Livewire::test(PrepareOrders::class)
        ->assertSet('orderIds', []);

    Order::factory()->create(['fulfillment_status' => FulfillmentStatus::UNFULFILLED]);

    $component->call('loadQueue')
        ->assertCount('orderIds', 1);
});

it('shows the page in the Sales navigation group', function () {
    expect(PrepareOrders::getNavigationGroup())->toBe('Sales');
});
