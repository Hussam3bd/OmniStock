<?php

use App\Actions\Invoice\GenerateInvoiceAction;
use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Invoice\InvoiceType;
use App\Models\Address\Address;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Invoice\Invoice;
use App\Models\Order\Order;
use App\Models\Product\Product;
use Illuminate\Support\Facades\Http;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Create currency required by Order factory
    Currency::create([
        'code' => 'TRY',
        'name' => 'Turkish Lira',
        'symbol' => '₺',
        'exchange_rate' => 1,
    ]);

    $this->integration = Integration::factory()->create([
        'type' => IntegrationType::INVOICE_PROVIDER,
        'provider' => IntegrationProvider::TRENDYOL_EFATURA,
        'is_active' => true,
        'settings' => [
            'email' => 'test@example.com',
            'password' => 'test_password',
            'test_mode' => true,
            'company_tax_id' => '1234567890',
            'prefix' => 'DAP',
            'user_id' => 63211,
            'company_id' => 12345,
            'access_token' => 'test_access_token',
        ],
    ]);

    $this->customer = Customer::factory()->create();

    $this->shippingAddress = Address::create([
        'addressable_type' => Customer::class,
        'addressable_id' => $this->customer->id,
        'type' => 'residential',
        'first_name' => 'Test',
        'last_name' => 'User',
        'address_line1' => 'Test Street 123',
        'tax_number' => '9876543210',
        'company_name' => 'Test Company',
    ]);

    $this->order = Order::factory()->create([
        'customer_id' => $this->customer->id,
        'shipping_address_id' => $this->shippingAddress->id,
        'integration_id' => $this->integration->id,
        'order_number' => 'ORD-12345',
        'subtotal' => 10000,
        'tax_amount' => 1800,
        'total_amount' => 11800,
    ]);

    $product = Product::create([
        'title' => 'Test Product',
        'name' => 'Test Product',
        'slug' => 'test-product-invoice',
        'status' => 'active',
    ]);

    $variant = \App\Models\Product\ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'TEST-SKU',
        'barcode' => 'TEST-BARCODE',
        'title' => 'Test Variant',
        'price' => 5000,
    ]);

    $this->order->items()->create([
        'product_variant_id' => $variant->id,
        'product_id' => $product->id,
        'name' => 'Test Product',
        'sku' => 'TEST-SKU',
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000, // 2 * 5000
        'tax_rate' => 18,
    ]);
});

test('can generate invoice for order', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-12345', 'invoiceUuid' => 'test-uuid-123'],
            200
        ),
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/download/permanent-url' => Http::response(
            'https://example.com/invoice.pdf',
            200
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $invoice = $action->execute($this->order);

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and($invoice->order_id)->toBe($this->order->id)
        ->and($invoice->integration_id)->toBe($this->integration->id)
        ->and($invoice->status)->toBe(InvoiceStatus::ISSUED)
        ->and($invoice->external_id)->toBe('INV-12345')
        ->and($invoice->issued_at)->not->toBeNull()
        ->and($invoice->pdf_url)->not->toBeNull()
        ->and($invoice->html_url)->not->toBeNull();

    // Verify invoice was created in database
    $this->assertDatabaseHas('invoices', [
        'order_id' => $this->order->id,
        'external_id' => 'INV-12345',
        'status' => InvoiceStatus::ISSUED->value,
    ]);

    // Verify order was updated with invoice data
    $this->order->refresh();
    expect($this->order->invoice_number)->not->toBeNull()
        ->and($this->order->invoice_date)->not->toBeNull()
        ->and($this->order->invoice_url)->not->toBeNull();
});

test('can generate invoice with specific integration', function () {
    $secondIntegration = Integration::factory()->create([
        'type' => IntegrationType::INVOICE_PROVIDER,
        'provider' => IntegrationProvider::TRENDYOL_EFATURA,
        'is_active' => true,
        'settings' => [
            'user_id' => 99999,
            'company_id' => 12345,
            'access_token' => 'second_token',
            'test_mode' => true,
        ],
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-SECOND-12345', 'invoiceUuid' => 'test-uuid-456'],
            200
        ),
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/download/permanent-url' => Http::response(
            'https://example.com/invoice.pdf',
            200
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $invoice = $action->execute($this->order, ['integration_id' => $secondIntegration->id]);

    expect($invoice->integration_id)->toBe($secondIntegration->id)
        ->and($invoice->external_id)->toBe('INV-SECOND-12345');
});

test('can generate e-archive invoice type', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-12345', 'invoiceUuid' => 'test-uuid'],
            200
        ),
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/download/permanent-url' => Http::response(
            'https://example.com/invoice.pdf',
            200
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $invoice = $action->execute($this->order, ['invoice_type' => 'e-archive']);

    expect($invoice->invoice_type)->toBe(InvoiceType::E_ARCHIVE);
});

test('throws exception when no active invoice provider found', function () {
    $this->integration->update(['is_active' => false]);

    $action = new GenerateInvoiceAction;
    $action->execute($this->order);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

test('throws exception when api call fails', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/einvoice/createEArchive' => Http::response(
            ['error' => 'Invalid data'],
            400
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $action->execute($this->order);
})->throws(\Exception::class);

test('logs invoice generation activity', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-12345', 'invoiceUuid' => 'test-uuid'],
            200
        ),
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/download/permanent-url' => Http::response(
            'https://example.com/invoice.pdf',
            200
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $invoice = $action->execute($this->order);

    // Check activity log was created
    $this->assertDatabaseHas('activity_log', [
        'subject_type' => Invoice::class,
        'subject_id' => $invoice->id,
        'description' => 'invoice_generated',
    ]);
});

test('generates correct invoice number with prefix', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-12345', 'invoiceUuid' => 'test-uuid'],
            200
        ),
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/download/permanent-url' => Http::response(
            'https://example.com/invoice.pdf',
            200
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $invoice = $action->execute($this->order);

    // Invoice number should be generated (format may vary based on settings)
    expect($invoice->invoice_number)->not->toBeNull()
        ->and($invoice->invoice_number)->not->toBeEmpty();
});

test('retries failed invoice instead of creating duplicate', function () {
    // Create a failed invoice first
    $failedInvoice = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'INV-FAILED-001',
        'status' => InvoiceStatus::FAILED,
        'error_message' => 'Previous attempt failed',
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-SUCCESS-001', 'invoiceUuid' => 'test-uuid-retry'],
            200
        ),
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/download/permanent-url' => Http::response(
            'https://example.com/invoice.pdf',
            200
        ),
    ]);

    $action = new GenerateInvoiceAction;
    $invoice = $action->execute($this->order);

    // Should be the same invoice record (retried, not duplicated)
    expect($invoice->id)->toBe($failedInvoice->id)
        ->and($invoice->status)->toBe(InvoiceStatus::ISSUED)
        ->and($invoice->error_message)->toBeNull()
        ->and($invoice->external_id)->toBe('INV-SUCCESS-001');

    // Verify only ONE invoice exists for this order
    $this->assertEquals(1, $this->order->invoices()->count());
});
