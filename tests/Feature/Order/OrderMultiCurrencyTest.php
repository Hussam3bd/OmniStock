<?php

use App\Enums\Order\OrderChannel;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\ExchangeRate;
use App\Models\Order\Order;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    // Ensure default currency exists (TRY)
    $this->tryCurrency = Currency::firstOrCreate(
        ['code' => 'TRY'],
        [
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'decimal_places' => 2,
            'is_default' => true,
            'is_active' => true,
        ]
    );

    // Create USD currency
    $this->usdCurrency = Currency::firstOrCreate(
        ['code' => 'USD'],
        [
            'name' => 'US Dollar',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_default' => false,
            'is_active' => true,
        ]
    );

    // Create EUR currency
    $this->eurCurrency = Currency::firstOrCreate(
        ['code' => 'EUR'],
        [
            'name' => 'Euro',
            'symbol' => '€',
            'decimal_places' => 2,
            'is_default' => false,
            'is_active' => true,
        ]
    );

    // Create exchange rates for testing
    ExchangeRate::create([
        'from_currency_id' => $this->tryCurrency->id,
        'to_currency_id' => $this->usdCurrency->id,
        'rate' => 0.0235, // 1 TRY = 0.0235 USD
        'effective_date' => now()->format('Y-m-d'),
    ]);

    ExchangeRate::create([
        'from_currency_id' => $this->usdCurrency->id,
        'to_currency_id' => $this->tryCurrency->id,
        'rate' => 42.55, // 1 USD = 42.55 TRY
        'effective_date' => now()->format('Y-m-d'),
    ]);

    ExchangeRate::create([
        'from_currency_id' => $this->tryCurrency->id,
        'to_currency_id' => $this->eurCurrency->id,
        'rate' => 0.0202, // 1 TRY = 0.0202 EUR
        'effective_date' => now()->format('Y-m-d'),
    ]);

    // Create test customer
    $this->customer = Customer::create([
        'email' => 'currency-test@example.com',
        'first_name' => 'Currency',
        'last_name' => 'Tester',
    ]);
});

test('order in default currency has exchange rate of 1.0', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-TRY-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000, // 1000.00 TRY in cents
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => 1.0,
        'order_date' => now(),
    ]);

    expect($order->currency)->toBe('TRY')
        ->and($order->currency_id)->toBe($this->tryCurrency->id)
        ->and($order->exchange_rate)->toBe('1.00000000')
        ->and($order->currency()->first()->code)->toBe('TRY');
});

test('order in foreign currency stores correct exchange rate', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-USD-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 10000, // 100.00 USD in cents
        'currency' => 'USD',
        'currency_id' => $this->usdCurrency->id,
        'exchange_rate' => 42.55,
        'order_date' => now(),
    ]);

    expect($order->currency)->toBe('USD')
        ->and($order->currency_id)->toBe($this->usdCurrency->id)
        ->and((float) $order->exchange_rate)->toBe(42.55)
        ->and($order->currency()->first()->code)->toBe('USD');
});

test('getTotalInDefaultCurrency returns correct value for foreign currency order', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-USD-CONVERT-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 10000, // 100.00 USD in cents
        'currency' => 'USD',
        'currency_id' => $this->usdCurrency->id,
        'exchange_rate' => 42.55, // 1 USD = 42.55 TRY
        'order_date' => now(),
    ]);

    // 100 USD × 42.55 = 4255 TRY
    $converted = $order->getTotalInDefaultCurrency();

    expect($converted)->toBe(4255.0);
});

test('getTotalInDefaultCurrency returns same value for default currency order', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-TRY-CONVERT-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000, // 1000.00 TRY in cents
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => 1.0,
        'order_date' => now(),
    ]);

    // 1000 TRY × 1.0 = 1000 TRY
    $converted = $order->getTotalInDefaultCurrency();

    expect($converted)->toBe(1000.0);
});

test('getHistoricalValueInCurrency converts TRY order to USD', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-TRY-TO-USD-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000, // 1000.00 TRY in cents
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => 1.0,
        'order_date' => now(),
    ]);

    $usdValue = $order->getHistoricalValueInCurrency('USD');

    expect($usdValue)->not->toBeNull()
        ->and($usdValue->getCurrency()->getCode())->toBe('USD')
        ->and((int) $usdValue->getAmount())->toBe(2350); // 1000 × 0.0235 = 23.50 USD (in cents)
});

test('getHistoricalValueInCurrency converts TRY order to EUR', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-TRY-TO-EUR-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000, // 1000.00 TRY in cents
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => 1.0,
        'order_date' => now(),
    ]);

    $eurValue = $order->getHistoricalValueInCurrency('EUR');

    expect($eurValue)->not->toBeNull()
        ->and($eurValue->getCurrency()->getCode())->toBe('EUR')
        ->and((int) $eurValue->getAmount())->toBe(2020); // 1000 × 0.0202 = 20.20 EUR (in cents)
});

test('getHistoricalValueInCurrency returns same money for same currency', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-USD-TO-USD-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 10000, // 100.00 USD in cents
        'currency' => 'USD',
        'currency_id' => $this->usdCurrency->id,
        'exchange_rate' => 42.55,
        'order_date' => now(),
    ]);

    $usdValue = $order->getHistoricalValueInCurrency('USD');

    expect($usdValue)->not->toBeNull()
        ->and($usdValue->getCurrency()->getCode())->toBe('USD')
        ->and((int) $usdValue->getAmount())->toBe(10000); // Same as original
});

test('getFormattedHistoricalValue returns formatted string', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-FORMAT-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000, // 1000.00 TRY in cents
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => 1.0,
        'order_date' => now(),
    ]);

    $formatted = $order->getFormattedHistoricalValue('USD');

    expect($formatted)->not->toBeNull()
        ->and($formatted)->toContain('$')
        ->and($formatted)->toContain('23.50'); // 1000 TRY × 0.0235 = $23.50
});

test('convertToDefaultCurrency converts money amount correctly', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-CONVERT-AMT-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 10000, // 100.00 USD in cents
        'currency' => 'USD',
        'currency_id' => $this->usdCurrency->id,
        'exchange_rate' => 42.55,
        'order_date' => now(),
    ]);

    // Convert $50 USD to TRY
    $fiftyUsd = \Cknow\Money\Money::USD(5000); // 50.00 USD in cents
    $converted = $order->convertToDefaultCurrency($fiftyUsd);

    expect($converted)->not->toBeNull()
        ->and($converted->getCurrency()->getCode())->toBe('TRY')
        ->and((int) $converted->getAmount())->toBe(212750); // 50 × 42.55 = 2127.50 TRY (in cents)
});

test('currency relationship works correctly', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::SHOPIFY,
        'order_number' => 'TEST-RELATION-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 10000,
        'currency' => 'USD',
        'currency_id' => $this->usdCurrency->id,
        'exchange_rate' => 42.55,
        'order_date' => now(),
    ]);

    $currencyModel = $order->currency()->first();

    expect($currencyModel)->not->toBeNull()
        ->and($currencyModel->code)->toBe('USD')
        ->and($currencyModel->name)->toBe('US Dollar')
        ->and($currencyModel->symbol)->toBe('$');
});

test('order without exchange rate returns null for conversion', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-NO-RATE-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000,
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => null, // No rate
        'order_date' => now(),
    ]);

    $converted = $order->convertToDefaultCurrency($order->total_amount);

    expect($converted)->toBe($order->total_amount); // Returns original if no rate
});

test('getHistoricalValueInCurrency returns null for invalid currency', function () {
    $order = Order::create([
        'customer_id' => $this->customer->id,
        'channel' => OrderChannel::TRENDYOL,
        'order_number' => 'TEST-INVALID-'.rand(1000, 9999),
        'order_status' => 'pending',
        'payment_status' => 'pending',
        'total_amount' => 100000,
        'currency' => 'TRY',
        'currency_id' => $this->tryCurrency->id,
        'exchange_rate' => 1.0,
        'order_date' => now(),
    ]);

    $value = $order->getHistoricalValueInCurrency('INVALID');

    expect($value)->toBeNull();
});
