<?php

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Address\Address;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Product\Product;
use App\Services\Integrations\InvoiceProviders\TrendyolEFatura\TrendyolEFaturaAdapter;
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
        'settings' => [
            'email' => 'test@example.com',
            'password' => 'test_password',
            'test_mode' => true,
            'company_tax_id' => '1234567890',
            'prefix' => 'DAP',
        ],
    ]);
});

test('can authenticate with valid credentials', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/auth/signin' => Http::response(
            63211,
            200,
            [
                'x-access-token' => 'test_access_token',
                'x-refresh-token' => 'test_refresh_token',
            ]
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $result = $adapter->authenticate();

    expect($result)->toBeTrue()
        ->and($this->integration->fresh()->settings['access_token'])->toBe('test_access_token')
        ->and($this->integration->fresh()->settings['refresh_token'])->toBe('test_refresh_token')
        ->and($this->integration->fresh()->settings['user_id'])->toBe(63211);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://stage-apigateway.trendyolefaturam.com/api/auth/signin'
            && $request['email'] === 'test@example.com'
            && $request['password'] === 'test_password';
    });
});

test('can authenticate and extract user_id and company_id from object response', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/auth/signin' => Http::response(
            ['id' => 63211, 'companyId' => 12345],
            200,
            [
                'x-access-token' => 'test_access_token',
                'x-refresh-token' => 'test_refresh_token',
            ]
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $result = $adapter->authenticate();

    expect($result)->toBeTrue()
        ->and($this->integration->fresh()->settings['user_id'])->toBe(63211)
        ->and($this->integration->fresh()->settings['company_id'])->toBe(12345);
});

test('authentication fails with invalid credentials', function () {
    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/auth/signin' => Http::response(
            ['message' => 'Invalid credentials'],
            401
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $result = $adapter->authenticate();

    expect($result)->toBeFalse()
        ->and($this->integration->fresh()->settings)->not->toHaveKey('access_token');
});

test('uses production url when test_mode is false', function () {
    $this->integration->update([
        'settings' => array_merge($this->integration->settings, ['test_mode' => false]),
    ]);

    Http::fake([
        'https://apigateway.trendyolecozum.com/api/auth/signin' => Http::response(
            63211,
            200,
            [
                'x-access-token' => 'test_access_token',
                'x-refresh-token' => 'test_refresh_token',
            ]
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $adapter->authenticate();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://apigateway.trendyolecozum.com/api/auth/signin';
    });
});

test('prepares invoice data for domestic order correctly', function () {
    $customer = Customer::factory()->create();

    $shippingAddress = Address::create([
        'addressable_type' => Customer::class,
        'addressable_id' => $customer->id,
        'type' => 'residential',
        'first_name' => 'Test',
        'last_name' => 'User',
        'address_line1' => 'Test Street 123',
        'tax_number' => '9876543210',
        'company_name' => 'Test Company',
    ]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'shipping_address_id' => $shippingAddress->id,
        'integration_id' => $this->integration->id,
        'order_number' => 'ORD-12345',
        'subtotal' => 10000, // 100.00 TRY in cents
        'tax_amount' => 1800, // 18.00 TRY in cents
        'total_amount' => 11800, // 118.00 TRY in cents
    ]);

    $product = Product::create([
        'title' => 'Test Product',
        'name' => 'Test Product',
        'slug' => 'test-product-adapter',
        'status' => 'active',
    ]);

    $variant = \App\Models\Product\ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'TEST-SKU',
        'barcode' => 'TEST-BARCODE',
        'title' => 'Test Variant',
        'price' => 5000,
    ]);

    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_id' => $product->id,
        'name' => 'Test Product',
        'sku' => 'TEST-SKU',
        'quantity' => 2,
        'unit_price' => 5000, // 50.00 TRY in cents
        'total_price' => 10000, // 2 * 5000
        'tax_rate' => 18,
    ]);

    $this->integration->update([
        'settings' => array_merge($this->integration->settings, [
            'user_id' => 63211,
            'company_id' => 12345,
            'access_token' => 'test_token',
        ]),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-12345', 'invoiceUuid' => 'test-uuid-123'],
            200
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $result = $adapter->generateInvoice($order);

    expect($result)->toBeArray()
        ->and($result['invoice_id'])->toBe('INV-12345')
        ->and($result['invoice_uuid'])->toBe('test-uuid-123')
        ->and($result['invoice_number'])->toBe('INV-12345');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->url() === 'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive'
            && $data['userId'] === 63211
            && $data['companyId'] === 12345
            && $data['source'] === 'PARTNER'
            && $data['targetAlias'] === ''
            && isset($data['recipientInfo'])
            && isset($data['bankInfo'])
            && isset($data['currencyInfo'])
            && $data['invoiceInfo']['invoiceType'] === 'EARSIVFATURA'
            && $data['invoiceTotal']['payableAmount'] === 11800; // Amount in cents (kuruş)
    });
});

test('prepares invoice data for export order with tax exemption', function () {
    $customer = Customer::factory()->create();

    // Create a US country for export order
    $usCountry = \App\Models\Address\Country::create([
        'name' => 'United States',
        'iso2' => 'US',
        'iso3' => 'USA',
    ]);

    $shippingAddress = Address::create([
        'addressable_type' => Customer::class,
        'addressable_id' => $customer->id,
        'type' => 'residential',
        'first_name' => 'Test',
        'last_name' => 'User',
        'address_line1' => '123 Main St',
        'country_id' => $usCountry->id,
    ]);

    $order = Order::factory()->create([
        'customer_id' => $customer->id,
        'shipping_address_id' => $shippingAddress->id,
        'integration_id' => $this->integration->id,
        'order_number' => 'ORD-EXP-001',
        'subtotal' => 20000, // 200.00 USD in cents
        'tax_amount' => 0, // No tax for export
        'total_amount' => 20000,
    ]);

    $product = Product::create([
        'title' => 'Export Product',
        'name' => 'Export Product',
        'slug' => 'export-product-adapter',
        'status' => 'active',
    ]);

    $variant = \App\Models\Product\ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'EXP-SKU',
        'barcode' => 'EXP-BARCODE',
        'title' => 'Export Variant',
        'price' => 20000,
    ]);

    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_id' => $product->id,
        'name' => 'Export Product',
        'sku' => 'EXP-SKU',
        'quantity' => 1,
        'unit_price' => 20000,
        'total_price' => 20000, // 1 * 20000
        'tax_rate' => 0,
    ]);

    $this->integration->update([
        'settings' => array_merge($this->integration->settings, [
            'user_id' => 63211,
            'access_token' => 'test_token',
        ]),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive' => Http::response(
            ['invoiceId' => 'INV-EXP-001', 'invoiceUuid' => 'test-uuid-exp'],
            200
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $result = $adapter->generateInvoice($order);

    expect($result)->toBeArray()
        ->and($result['invoice_id'])->toBe('INV-EXP-001');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $data['invoiceInfo']['invoiceTypeCode'] === 'ISTISNA'
            && isset($data['invoiceLines'][0]['exportInfo'])
            && $data['invoiceLines'][0]['exportInfo']['delivery']['country'] === 'US'
            && isset($data['invoiceLines'][0]['totalTax']['subTotalTaxes'][0]['taxExemptionReason'])
            && $data['invoiceLines'][0]['totalTax']['subTotalTaxes'][0]['taxExemptionReason'] === '11/1-a Mal İhracatı';
    });
});

test('can cancel invoice', function () {
    $this->integration->update([
        'settings' => array_merge($this->integration->settings, [
            'access_token' => 'test_token',
            'company_id' => 12345,
        ]),
    ]);

    Http::fake([
        'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel' => Http::response(
            ['success' => true],
            200
        ),
    ]);

    $adapter = new TrendyolEFaturaAdapter($this->integration);
    $result = $adapter->cancelInvoice('test-uuid-123');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://stage-apigateway.trendyolefaturam.com/api/invoice/documents/earchive/cancel'
            && $request['invoiceUuid'] === 'test-uuid-123'
            && $request['companyId'] === 12345;
    });
});
