<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\PaymentStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\ExchangeRate;
use App\Models\Order\Order;
use App\Models\Purchase\PurchaseOrder;
use App\Models\Supplier\Supplier;
use Cknow\Money\Money;

beforeEach(function () {
    // Create currencies
    $this->usd = Currency::create([
        'code' => 'USD',
        'name' => 'US Dollar',
        'symbol' => '$',
        'decimal_places' => 2,
        'is_default' => false,
        'is_active' => true,
    ]);

    $this->try = Currency::create([
        'code' => 'TRY',
        'name' => 'Turkish Lira',
        'symbol' => '₺',
        'decimal_places' => 2,
        'is_default' => true,
        'is_active' => true,
    ]);

    $this->eur = Currency::create([
        'code' => 'EUR',
        'name' => 'Euro',
        'symbol' => '€',
        'decimal_places' => 2,
        'is_default' => false,
        'is_active' => true,
    ]);

    // Create exchange rates
    ExchangeRate::create([
        'from_currency_id' => $this->usd->id,
        'to_currency_id' => $this->try->id,
        'rate' => 42.55,
        'effective_date' => now()->toDateString(),
    ]);

    ExchangeRate::create([
        'from_currency_id' => $this->eur->id,
        'to_currency_id' => $this->try->id,
        'rate' => 37.20,
        'effective_date' => now()->toDateString(),
    ]);

    // Create TRY account with zero balance
    $this->account = Account::create([
        'name' => 'Test Bank Account (TRY)',
        'type' => 'bank',
        'currency_id' => $this->try->id,
        'balance' => 0,
    ]);
});

it('creates transaction with correct currency conversion when USD purchase order is received', function () {
    // Create supplier
    $supplier = Supplier::create([
        'name' => 'Test Supplier',
        'code' => 'TEST-SUP-'.uniqid(),
    ]);

    // Create Purchase Order in USD for $100.00
    $purchaseOrder = PurchaseOrder::create([
        'order_number' => 'PO-TEST-001',
        'supplier_id' => $supplier->id,
        'currency_id' => $this->usd->id,
        'exchange_rate' => 42.55, // USD to TRY
        'status' => PurchaseOrderStatus::Ordered,
        'order_date' => now(),
        'subtotal' => 10000, // $100.00 in cents
        'tax' => 0,
        'shipping_cost' => 0,
        'total' => 10000, // $100.00 in cents
    ]);

    // Record initial account balance
    $initialBalance = $this->account->balance->getAmount();

    // Mark Purchase Order as received
    $purchaseOrder->update([
        'status' => PurchaseOrderStatus::Received,
        'received_date' => now(),
    ]);

    // Assert transaction was created
    $transaction = Transaction::where('transactionable_type', PurchaseOrder::class)
        ->where('transactionable_id', $purchaseOrder->id)
        ->first();
    expect($transaction)->not->toBeNull();

    // Assert transaction has correct type and category
    expect($transaction->type)->toBe(TransactionType::EXPENSE);
    expect($transaction->category)->toBe(ExpenseCategory::PRODUCT_PURCHASE->value);

    // Assert transaction has correct currency fields
    expect($transaction->currency)->toBe('USD');
    expect($transaction->currency_id)->toBe($this->usd->id);
    expect($transaction->exchange_rate)->toBe('42.55000000');

    // Assert transaction amount is correct (in USD)
    expect((int) $transaction->amount->getAmount())->toBe(10000); // $100.00 in cents

    // Calculate expected converted amount
    // $100.00 (10000 cents) × 42.55 = ₺4,255.00 (425500 cents)
    $expectedConvertedAmount = 10000 * 42.55;

    // Assert account balance was updated correctly (decreased by converted amount)
    $this->account->refresh();
    $expectedBalance = $initialBalance - (int) round($expectedConvertedAmount);
    expect((int) $this->account->balance->getAmount())->toBe($expectedBalance);

    // Verify it's -₺4,255.00 (expense reduces balance)
    expect((int) $this->account->balance->getAmount())->toBe(-425500);
});

it('calculates correct converted amount using helper methods', function () {
    $supplier = Supplier::create([
        'name' => 'Test Supplier',
        'code' => 'TEST-SUP-'.uniqid(),
    ]);

    // Create PO in USD for $50.00
    $purchaseOrder = PurchaseOrder::create([
        'order_number' => 'PO-TEST-002',
        'supplier_id' => $supplier->id,
        'currency_id' => $this->usd->id,
        'exchange_rate' => 42.55,
        'status' => PurchaseOrderStatus::Ordered,
        'order_date' => now(),
        'subtotal' => 5000, // $50.00
        'tax' => 0,
        'shipping_cost' => 0,
        'total' => 5000,
    ]);

    // Mark as received to trigger observer
    $purchaseOrder->update([
        'status' => PurchaseOrderStatus::Received,
        'received_date' => now(),
    ]);

    $transaction = Transaction::where('transactionable_type', PurchaseOrder::class)
        ->where('transactionable_id', $purchaseOrder->id)
        ->first();

    // Test getAmountInAccountCurrency()
    $convertedAmount = $transaction->getAmountInAccountCurrency();
    expect($convertedAmount)->toBeInstanceOf(Money::class);
    expect((int) $convertedAmount->getAmount())->toBe(212750); // 5000 × 42.55 = 212,750 TRY cents = ₺2,127.50
    expect($convertedAmount->getCurrency()->getCode())->toBe('TRY');

    // Test hasCurrencyConversion()
    expect($transaction->hasCurrencyConversion())->toBeTrue();

    // Test getFormattedAmount()
    $formattedOriginal = $transaction->getFormattedAmount();
    expect($formattedOriginal)->toContain('50'); // Should show $50

    // Test getFormattedAmountInAccountCurrency()
    $formattedConverted = $transaction->getFormattedAmountInAccountCurrency();
    expect($formattedConverted)->toContain('2,127.50'); // Should show ₺2,127.50
});

it('creates transaction with correct currency conversion when EUR purchase order is received', function () {
    $supplier = Supplier::create([
        'name' => 'Test Supplier',
        'code' => 'TEST-SUP-'.uniqid(),
    ]);

    $initialBalance = $this->account->balance->getAmount();

    // Create Purchase Order in EUR for €75.00
    $purchaseOrder = PurchaseOrder::create([
        'order_number' => 'PO-TEST-003',
        'supplier_id' => $supplier->id,
        'currency_id' => $this->eur->id,
        'exchange_rate' => 37.20, // EUR to TRY
        'status' => PurchaseOrderStatus::Ordered,
        'order_date' => now(),
        'subtotal' => 7500, // €75.00 in cents
        'tax' => 0,
        'shipping_cost' => 0,
        'total' => 7500,
    ]);

    // Mark as received
    $purchaseOrder->update([
        'status' => PurchaseOrderStatus::Received,
        'received_date' => now(),
    ]);

    $transaction = Transaction::where('transactionable_type', PurchaseOrder::class)
        ->where('transactionable_id', $purchaseOrder->id)
        ->first();

    // Assert transaction has EUR currency
    expect($transaction->currency)->toBe('EUR');
    expect($transaction->currency_id)->toBe($this->eur->id);
    expect($transaction->exchange_rate)->toBe('37.20000000');

    // Calculate expected amount: €75.00 (7500 cents) × 37.20 = ₺2,790.00 (279000 cents)
    $expectedConvertedAmount = 7500 * 37.20;

    // Assert account balance updated correctly
    $this->account->refresh();
    $expectedBalance = $initialBalance - (int) round($expectedConvertedAmount);
    expect((int) $this->account->balance->getAmount())->toBe($expectedBalance);
    expect((int) $this->account->balance->getAmount())->toBe(-279000);
});

it('does not apply currency conversion when transaction currency matches account currency', function () {
    // Create TRY Purchase Order
    $supplier = Supplier::create([
        'name' => 'Test Supplier',
        'code' => 'TEST-SUP-'.uniqid(),
    ]);

    $purchaseOrder = PurchaseOrder::create([
        'order_number' => 'PO-TEST-004',
        'supplier_id' => $supplier->id,
        'currency_id' => $this->try->id,
        'exchange_rate' => 1.0, // Same currency, rate is 1.0
        'status' => PurchaseOrderStatus::Ordered,
        'order_date' => now(),
        'subtotal' => 10000, // ₺100.00
        'tax' => 0,
        'shipping_cost' => 0,
        'total' => 10000,
    ]);

    // Mark as received to trigger observer
    $purchaseOrder->update([
        'status' => PurchaseOrderStatus::Received,
        'received_date' => now(),
    ]);

    $transaction = Transaction::where('transactionable_type', PurchaseOrder::class)
        ->where('transactionable_id', $purchaseOrder->id)
        ->first();

    // Assert same currency
    expect($transaction->currency)->toBe('TRY');
    expect($transaction->currency_id)->toBe($this->try->id);
    expect($transaction->exchange_rate)->toBe('1.00000000');

    // Assert no conversion needed
    expect($transaction->hasCurrencyConversion())->toBeFalse();

    // Assert balance updated by exact amount (no conversion)
    $this->account->refresh();
    expect((int) $this->account->balance->getAmount())->toBe(-10000); // -₺100.00
});

it('creates transaction with correct currency conversion when USD order is paid', function () {
    // Create customer
    $customer = Customer::create([
        'first_name' => 'Test',
        'last_name' => 'Customer',
        'email' => 'test@example.com',
    ]);

    // Create Order in USD for $200.00
    $order = Order::create([
        'customer_id' => $customer->id,
        'order_number' => 'ORD-TEST-001',
        'channel' => OrderChannel::SHOPIFY,
        'currency' => 'USD',
        'currency_id' => $this->usd->id,
        'exchange_rate' => 42.55,
        'order_status' => 'pending',
        'payment_status' => PaymentStatus::PENDING,
        'fulfillment_status' => 'unfulfilled',
        'subtotal' => 20000, // $200.00
        'tax_amount' => 0,
        'shipping_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 20000,
        'payment_gateway_fee' => 500, // $5.00 fee
        'payment_gateway_commission_rate' => 2.5,
        'payment_gateway_commission_amount' => 500,
        'payment_payout_amount' => 19500, // $195.00 after fees
        'order_date' => now(),
    ]);

    $initialBalance = $this->account->balance->getAmount();

    // Mark order as paid
    $order->update([
        'payment_status' => PaymentStatus::PAID,
        'payment_transaction_id' => 'TXN-TEST-001',
    ]);

    // Assert income transaction was created
    $transaction = Transaction::where('transactionable_type', Order::class)
        ->where('transactionable_id', $order->id)
        ->first();
    expect($transaction)->not->toBeNull();

    // Assert transaction type and category
    expect($transaction->type)->toBe(TransactionType::INCOME);
    expect($transaction->category)->toBe(IncomeCategory::SALES_SHOPIFY->value);

    // Assert currency fields
    expect($transaction->currency)->toBe('USD');
    expect($transaction->currency_id)->toBe($this->usd->id);
    expect($transaction->exchange_rate)->toBe('42.55000000');

    // Assert amount is payment payout (after fees)
    expect((int) $transaction->amount->getAmount())->toBe(19500); // $195.00

    // Calculate expected: $195.00 (19500 cents) × 42.55 = ₺8,297.25 (829725 cents)
    $expectedConvertedAmount = 19500 * 42.55;

    // Assert account balance increased (income adds to balance)
    $this->account->refresh();
    $expectedBalance = $initialBalance + (int) round($expectedConvertedAmount);
    expect((int) $this->account->balance->getAmount())->toBe($expectedBalance);
    expect((int) $this->account->balance->getAmount())->toBe(829725);
});

it('correctly reverses currency conversion when transaction is deleted', function () {
    $supplier = Supplier::create([
        'name' => 'Test Supplier',
        'code' => 'TEST-SUP-'.uniqid(),
    ]);

    // Create and receive PO
    $purchaseOrder = PurchaseOrder::create([
        'order_number' => 'PO-TEST-005',
        'supplier_id' => $supplier->id,
        'currency_id' => $this->usd->id,
        'exchange_rate' => 42.55,
        'status' => PurchaseOrderStatus::Ordered,
        'order_date' => now(),
        'subtotal' => 5000, // $50.00
        'tax' => 0,
        'shipping_cost' => 0,
        'total' => 5000,
    ]);

    // Mark as received to trigger observer
    $purchaseOrder->update([
        'status' => PurchaseOrderStatus::Received,
        'received_date' => now(),
    ]);

    $transaction = Transaction::where('transactionable_type', PurchaseOrder::class)
        ->where('transactionable_id', $purchaseOrder->id)
        ->first();

    // Record balance after transaction
    $this->account->refresh();
    $balanceAfterExpense = (int) $this->account->balance->getAmount();
    expect($balanceAfterExpense)->toBe(-212750); // -₺2,127.50

    // Delete the transaction
    $transaction->delete();

    // Assert balance was reversed correctly
    $this->account->refresh();
    expect((int) $this->account->balance->getAmount())->toBe(0); // Back to zero
});
