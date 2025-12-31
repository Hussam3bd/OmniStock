<?php

use App\Actions\Invoice\CancelInvoiceAction;
use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Invoice\InvoiceType;
use App\Models\Currency;
use App\Models\Integration\Integration;
use App\Models\Invoice\Invoice;
use App\Models\Order\Order;
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
            'user_id' => 63211,
            'company_id' => 12345,
            'access_token' => 'test_access_token',
        ],
    ]);

    $this->order = Order::factory()->create();

    $this->invoice = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-12345',
        'external_id' => 'INV-12345',
        'invoice_uuid' => 'test-uuid-123',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);
});

test('can cancel issued invoice', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice, ['reason' => 'Customer request']);

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::CANCELLED)
        ->and($this->invoice->fresh()->cancelled_at)->not->toBeNull()
        ->and($this->invoice->fresh()->cancel_reason)->toBe('Customer request');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel'
            && $request['invoiceUuid'] === 'test-uuid-123';
    });
});

test('can cancel pending invoice', function () {
    $this->invoice->update(['status' => InvoiceStatus::PENDING, 'issued_at' => null]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice);

    expect($this->invoice->fresh()->status)->toBe(InvoiceStatus::CANCELLED);
});

test('cannot cancel already cancelled invoice', function () {
    $this->invoice->update([
        'status' => InvoiceStatus::CANCELLED,
        'cancelled_at' => now(),
    ]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice);
})->throws(\Exception::class, 'Invoice cannot be cancelled');

test('cannot cancel draft invoice', function () {
    $this->invoice->update(['status' => InvoiceStatus::DRAFT]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice);
})->throws(\Exception::class, 'Invoice cannot be cancelled');

test('cannot cancel failed invoice', function () {
    $this->invoice->update(['status' => InvoiceStatus::FAILED]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice);
})->throws(\Exception::class, 'Invoice cannot be cancelled');

test('logs invoice cancellation activity', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice, ['reason' => 'Order refunded']);

    // Check activity log was created
    $this->assertDatabaseHas('activity_log', [
        'subject_type' => Invoice::class,
        'subject_id' => $this->invoice->id,
        'description' => 'invoice_cancelled',
    ]);
});

test('throws exception when api call fails', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['error' => 'Invoice not found'],
            404
        ),
    ]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice);
})->throws(\Exception::class);

test('stores cancel reason in database', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $action = new CancelInvoiceAction;
    $action->execute($this->invoice, ['reason' => 'Product returned by customer']);

    $this->assertDatabaseHas('invoices', [
        'id' => $this->invoice->id,
        'status' => InvoiceStatus::CANCELLED->value,
        'cancel_reason' => 'Product returned by customer',
    ]);
});
