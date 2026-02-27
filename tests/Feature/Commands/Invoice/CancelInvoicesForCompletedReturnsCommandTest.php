<?php

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Invoice\InvoiceType;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnStatus;
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
        'return_number' => 'RET-CMD-001',
        'channel' => OrderChannel::PORTAL,
        'status' => ReturnStatus::Completed,
        'requested_at' => now(),
        'completed_at' => now(),
    ]);
});

test('dry run shows invoices without cancelling', function () {
    $invoice = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-DRY-001',
        'external_id' => 'INV-DRY-001',
        'invoice_uuid' => 'dry-uuid-001',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);

    Http::fake();

    $this->artisan('invoice:cancel-for-completed-returns', ['--dry-run' => true])
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::ISSUED);
    Http::assertNothingSent();
});

test('cancels issued invoices for completed returns', function () {
    $invoice = Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-CMD-001',
        'external_id' => 'INV-CMD-001',
        'invoice_uuid' => 'cmd-uuid-001',
        'status' => InvoiceStatus::ISSUED,
        'issued_at' => now(),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $this->artisan('invoice:cancel-for-completed-returns')
        ->expectsConfirmation('Cancel 1 invoice(s)?', 'yes')
        ->assertSuccessful();

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::CANCELLED)
        ->and($invoice->fresh()->cancel_reason)->toBe('Order return completed (backfill)');
});

test('reports nothing to do when no completed returns exist', function () {
    $this->orderReturn->update(['status' => ReturnStatus::Requested]);

    $this->artisan('invoice:cancel-for-completed-returns', ['--no-interaction' => true])
        ->assertSuccessful();
});

test('reports nothing to do when no issued invoices exist', function () {
    $this->artisan('invoice:cancel-for-completed-returns', ['--no-interaction' => true])
        ->assertSuccessful();
});

test('skips already cancelled invoices', function () {
    Invoice::create([
        'order_id' => $this->order->id,
        'integration_id' => $this->integration->id,
        'invoice_type' => InvoiceType::E_ARCHIVE,
        'invoice_number' => 'DAP-SKIP-001',
        'external_id' => 'INV-SKIP-001',
        'invoice_uuid' => 'skip-uuid-001',
        'status' => InvoiceStatus::CANCELLED,
        'issued_at' => now(),
        'cancelled_at' => now(),
    ]);

    Http::fake();

    $this->artisan('invoice:cancel-for-completed-returns', ['--no-interaction' => true])
        ->assertSuccessful();

    Http::assertNothingSent();
});
