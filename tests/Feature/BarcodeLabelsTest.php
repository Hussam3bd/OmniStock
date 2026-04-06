<?php

use App\Filament\Pages\PrintBarcodeLabels;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $admin = User::factory()->create();
    $admin->assignRole(
        Role::create(['name' => 'super_admin', 'guard_name' => 'web'])
    );
    $this->actingAs($admin);
});

function makeTestProduct(int $variantCount = 2): Product
{
    $product = Product::create([
        'title' => 'Test Shoe',
        'model_code' => 'TST-001',
    ]);

    for ($i = 0; $i < $variantCount; $i++) {
        ProductVariant::create([
            'product_id' => $product->id,
            'sku' => fake()->unique()->bothify('SKU-###'),
            'barcode' => fake()->unique()->ean13(),
            'price' => 100_00,
            'inventory_quantity' => 5,
        ]);
    }

    return $product->load('variants');
}

it('renders the print barcode labels page', function () {
    Livewire::test(PrintBarcodeLabels::class)
        ->assertSuccessful();
});

it('shows add product and add variants header actions', function () {
    Livewire::test(PrintBarcodeLabels::class)
        ->assertActionExists('addProduct')
        ->assertActionExists('addVariants');
});

it('can add all variants of a product', function () {
    $product = makeTestProduct(3);

    $test = Livewire::test(PrintBarcodeLabels::class)
        ->call('addProduct', $product->id);

    foreach ($product->variants as $variant) {
        $test->assertSet("selectedVariants.{$variant->id}", 5);
    }
});

it('can add a single variant', function () {
    $product = makeTestProduct();
    $variant = $product->variants->first();

    Livewire::test(PrintBarcodeLabels::class)
        ->call('addVariant', $variant->id)
        ->assertSet("selectedVariants.{$variant->id}", 5);
});

it('can remove a variant', function () {
    $product = makeTestProduct();
    $variant = $product->variants->first();

    Livewire::test(PrintBarcodeLabels::class)
        ->call('addVariant', $variant->id)
        ->call('removeVariant', $variant->id)
        ->assertSet('selectedVariants', []);
});

it('can clear all variants', function () {
    $product = makeTestProduct(3);

    Livewire::test(PrintBarcodeLabels::class)
        ->call('addProduct', $product->id)
        ->call('clearAll')
        ->assertSet('selectedVariants', []);
});

it('can update variant quantity', function () {
    $product = makeTestProduct();
    $variant = $product->variants->first();

    Livewire::test(PrintBarcodeLabels::class)
        ->call('addVariant', $variant->id)
        ->call('updateQuantity', $variant->id, 10)
        ->assertSet("selectedVariants.{$variant->id}", 10);
});

it('defaults quantity to stock value', function () {
    $product = Product::create(['title' => 'Stock Test', 'model_code' => 'STK']);
    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'STK-001',
        'barcode' => fake()->ean13(),
        'price' => 100_00,
        'inventory_quantity' => 12,
    ]);

    Livewire::test(PrintBarcodeLabels::class)
        ->call('addVariant', $variant->id)
        ->assertSet("selectedVariants.{$variant->id}", 12);
});

it('renders the print page with labels', function () {
    $product = makeTestProduct();
    $variant = $product->variants->first();

    $variants = json_encode([$variant->id => 2]);

    $this->get(route('admin.barcode-labels.print', [
        'variants' => $variants,
        'width' => 76,
        'height' => 25.4,
    ]))
        ->assertSuccessful()
        ->assertSee($variant->sku)
        ->assertSee('JsBarcode');
});

it('rejects print request with no variants', function () {
    $this->get(route('admin.barcode-labels.print', [
        'variants' => '{}',
        'width' => 76,
        'height' => 25.4,
    ]))
        ->assertStatus(422);
});

it('updates label preset and dimensions', function () {
    Livewire::test(PrintBarcodeLabels::class)
        ->set('labelPreset', '50x25')
        ->assertSet('labelWidth', 50.0)
        ->assertSet('labelHeight', 25.0);
});
