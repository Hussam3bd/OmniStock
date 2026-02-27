<?php

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Invoice\InvoiceType;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
use App\Events\Order\OrderReturnCompleted;
use App\Listeners\Invoice\CancelInvoiceForReturn;
use App\Models\Currency;
use App\Models\Integration\Integration;
use App\Models\Invoice\Invoice;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use Illuminate\Support\Facades\Http;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
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

    $this->orderReturn = OrderReturn::create([
        'order_id' => $this->order->id,
        'return_number' => 'RET-TEST-001',
        'channel' => OrderChannel::PORTAL,
        'status' => ReturnStatus::Completed,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);
});

test('cancels issued invoices when order return is completed', function () {
    $invoice = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-12345',
        'external_id' => 'INV-12345',
        'invoice_uuid' => 'test-uuid-123',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $event = new OrderReturnCompleted($this->orderReturn);
    $listener = app(CancelInvoiceForReturn::class);
    $listener->handle($event);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::CANCELLED)
        ->and($invoice->fresh()->cancelled_at)->not->toBeNull()
        ->and($invoice->fresh()->cancel_reason)->toBe('Order return completed');
});

test('skips orders without invoices', function () {
    Http::fake();

    $event = new OrderReturnCompleted($this->orderReturn);
    $listener = app(CancelInvoiceForReturn::class);
    $listener->handle($event);

    Http::assertNothingSent();
});

test('skips already cancelled invoices', function () {
    Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-12345',
        'external_id' => 'INV-12345',
        'invoice_uuid' => 'test-uuid-123',
        'status' => InvoiceStatus::CANCELLED,
        'issued_at' => now(),
        'cancelled_at' => now(),
        'cancel_reason' => 'Previously cancelled',
    ]);

    Http::fake();

    $event = new OrderReturnCompleted($this->orderReturn);
    $listener = app(CancelInvoiceForReturn::class);
    $listener->handle($event);

    Http::assertNothingSent();
});

test('throws exception when cancellation fails', function () {
    Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-12345',
        'external_id' => 'INV-12345',
        'invoice_uuid' => 'test-uuid-123',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['error' => 'Service unavailable'],
            500
        ),
    ]);

    $event = new OrderReturnCompleted($this->orderReturn);
    $listener = app(CancelInvoiceForReturn::class);
    $listener->handle($event);
})->throws(\Exception::class);

test('cancels multiple issued invoices for the same order', function () {
    $invoice1 = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-001',
        'external_id' => 'INV-001',
        'invoice_uuid' => 'uuid-001',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);

    $invoice2 = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-002',
        'external_id' => 'INV-002',
        'invoice_uuid' => 'uuid-002',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $event = new OrderReturnCompleted($this->orderReturn);
    $listener = app(CancelInvoiceForReturn::class);
    $listener->handle($event);

    expect($invoice1->fresh()->status)->toBe(InvoiceStatus::CANCELLED)
        ->and($invoice2->fresh()->status)->toBe(InvoiceStatus::CANCELLED);
});
