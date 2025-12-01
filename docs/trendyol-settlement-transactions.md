# Trendyol Settlement Transactions Implementation Guide

## Overview

This document outlines the implementation plan for tracking Trendyol financial settlement transactions. These transactions contain detailed fee information needed to calculate net profit after all platform costs.

## Business Requirements

To calculate net profit for Trendyol orders, we need to track:

- **Platform Hizmet Bedeli** (Platform Service Fee)
- **Ceza** (Penalty)
- **Kargo** (Shipping Cost)
- **İade Kargo** (Return Shipping)
- **Yurtdışı Operasyon Bedeli** (International Operation Fee)
- **Uluslararası Hizmet Bedeli** (International Service Fee)

These fees are available through Trendyol's Finance API and need to be stored per order for profit reporting.

## Finance API Details

### Endpoint
```
GET https://apigw.trendyol.com/integration/finance/che/sellers/{sellerId}/settlements
```

### Required Parameters

| Parameter | Type | Description | Constraint |
|-----------|------|-------------|------------|
| transactionType | string | Type of transaction | See transaction types below |
| startDate | long | Start date in milliseconds | Max 15-day range |
| endDate | long | End date in milliseconds | Max 15-day range |
| page | int | Page number | Default: 0 |
| size | int | Results per page | Must be 500 or 1000 |

### Date Range Limitation
- Maximum range: **15 days** between startDate and endDate
- Must implement chunking similar to order sync (14-day chunks recommended)
- Error message if exceeded: "Başlangıç ve bitiş tarihi arasındaki fark 15 günden büyük olamaz"

### Transaction Types

Available transaction types from the API:

- `Sale` - Product sales
- `Return` - Product returns
- `Discount` - Discounts applied
- `DeliveryFee` - Delivery fees
- `CommissionNegative` - Commission adjustments (negative)
- `FastDeliveryFee` - Fast delivery charges
- `Commission` - Platform commission
- `FastDeliveryCommission` - Fast delivery commission
- `ReturnCommission` - Return commission
- `ReturnDeliveryFee` - Return delivery fee
- `FutureDeliveryCommission` - Future delivery commission
- `EftInstructions` - EFT (bank transfer) instructions
- `MarketplaceServiceFee` - Marketplace service fee
- `MarketplaceServiceFeeVat` - VAT on marketplace service fee
- `OverseasOperationalFee` - International operation fee
- `OverseasOperationalFeeVat` - VAT on international operation fee
- `InternationalServiceFee` - International service fee
- `InternationalServiceFeeVat` - VAT on international service fee
- `SubscriptionCourier` - Subscription courier fee
- `SubscriptionCourierDiscount` - Subscription courier discount

### API Response Structure

Example successful response:

```json
{
  "content": [
    {
      "transactionType": "Sale",
      "orderNumber": "10567915914",
      "settlementAmount": 85000,
      "commission": 18275,
      "transactionDate": 1728310860000,
      "sellerRevenue": 66725,
      "paymentDate": 1728396600000,
      "description": "Product Sale",
      "details": {
        "platform_service_fee": 500,
        "penalty": 0,
        "shipping_cost": 1500,
        "return_shipping": 0
      }
    },
    {
      "transactionType": "Commission",
      "orderNumber": "10567915914",
      "settlementAmount": -18275,
      "commission": 18275,
      "transactionDate": 1728310860000
    }
  ],
  "page": 0,
  "size": 500,
  "totalPages": 1,
  "totalElements": 2
}
```

## Proposed Database Schema

### Table: `order_settlement_transactions`

```php
Schema::create('order_settlement_transactions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('order_id')->constrained()->cascadeOnDelete();

    // Transaction identification
    $table->string('transaction_type'); // Sale, Return, Commission, etc.
    $table->string('external_transaction_id')->nullable(); // Trendyol's transaction ID if available

    // Financial data (in minor units - kuruş)
    $table->bigInteger('settlement_amount')->default(0)->comment('Settlement amount in minor units');
    $table->bigInteger('commission')->default(0)->comment('Commission amount in minor units');
    $table->bigInteger('seller_revenue')->default(0)->comment('Net revenue to seller in minor units');

    // Fee breakdown (all in minor units)
    $table->bigInteger('platform_service_fee')->default(0)->comment('Platform Hizmet Bedeli');
    $table->bigInteger('penalty_fee')->default(0)->comment('Ceza');
    $table->bigInteger('shipping_cost')->default(0)->comment('Kargo');
    $table->bigInteger('return_shipping_cost')->default(0)->comment('İade Kargo');
    $table->bigInteger('overseas_operation_fee')->default(0)->comment('Yurtdışı Operasyon Bedeli');
    $table->bigInteger('international_service_fee')->default(0)->comment('Uluslararası Hizmet Bedeli');

    // Dates
    $table->timestamp('transaction_date')->nullable();
    $table->timestamp('payment_date')->nullable();

    // Additional info
    $table->string('currency', 3)->default('TRY');
    $table->text('description')->nullable();
    $table->json('raw_data')->nullable()->comment('Full API response for reference');

    $table->timestamps();

    // Indexes
    $table->index('order_id');
    $table->index('transaction_type');
    $table->index('transaction_date');
});
```

## Implementation Plan

### 1. Create Model: `OrderSettlementTransaction`

**File**: `app/Models/Order/OrderSettlementTransaction.php`

```php
<?php

namespace App\Models\Order;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderSettlementTransaction extends Model
{
    protected $fillable = [
        'order_id',
        'transaction_type',
        'external_transaction_id',
        'settlement_amount',
        'commission',
        'seller_revenue',
        'platform_service_fee',
        'penalty_fee',
        'shipping_cost',
        'return_shipping_cost',
        'overseas_operation_fee',
        'international_service_fee',
        'transaction_date',
        'payment_date',
        'currency',
        'description',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'settlement_amount' => MoneyIntegerCast::class,
            'commission' => MoneyIntegerCast::class,
            'seller_revenue' => MoneyIntegerCast::class,
            'platform_service_fee' => MoneyIntegerCast::class,
            'penalty_fee' => MoneyIntegerCast::class,
            'shipping_cost' => MoneyIntegerCast::class,
            'return_shipping_cost' => MoneyIntegerCast::class,
            'overseas_operation_fee' => MoneyIntegerCast::class,
            'international_service_fee' => MoneyIntegerCast::class,
            'transaction_date' => 'datetime',
            'payment_date' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
```

### 2. Update Order Model Relationship

**File**: `app/Models/Order/Order.php`

Add relationship method:

```php
public function settlementTransactions(): HasMany
{
    return $this->hasMany(OrderSettlementTransaction::class);
}
```

Add helper methods for financial summary:

```php
/**
 * Get total platform service fees for this order
 */
public function getTotalPlatformServiceFee(): Money
{
    $total = $this->settlementTransactions()
        ->sum('platform_service_fee');

    return money($total, $this->currency);
}

/**
 * Get total penalties for this order
 */
public function getTotalPenalties(): Money
{
    $total = $this->settlementTransactions()
        ->sum('penalty_fee');

    return money($total, $this->currency);
}

/**
 * Get total shipping costs for this order
 */
public function getTotalShippingCosts(): Money
{
    $total = $this->settlementTransactions()
        ->sum('shipping_cost');

    return money($total, $this->currency);
}

/**
 * Get total return shipping costs for this order
 */
public function getTotalReturnShippingCosts(): Money
{
    $total = $this->settlementTransactions()
        ->sum('return_shipping_cost');

    return money($total, $this->currency);
}

/**
 * Get net profit after all fees
 */
public function getNetProfit(): Money
{
    $revenue = $this->total_amount;

    // Subtract all fees
    $fees = $this->total_commission
        ->add($this->getTotalPlatformServiceFee())
        ->add($this->getTotalPenalties())
        ->add($this->getTotalShippingCosts())
        ->add($this->getTotalReturnShippingCosts())
        ->add($this->getTotalOverseasOperationFee())
        ->add($this->getTotalInternationalServiceFee());

    return $revenue->subtract($fees);
}
```

### 3. Create Finance Adapter

**File**: `app/Services/Integrations/SalesChannels/TrendyolFinanceAdapter.php`

```php
<?php

namespace App\Services\Integrations\SalesChannels;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class TrendyolFinanceAdapter
{
    protected string $baseUrl;
    protected string $apiKey;
    protected string $apiSecret;
    protected string $supplierId;

    public function __construct()
    {
        $this->baseUrl = config('services.trendyol.api_url');
        $this->apiKey = config('services.trendyol.api_key');
        $this->apiSecret = config('services.trendyol.api_secret');
        $this->supplierId = config('services.trendyol.supplier_id');
    }

    /**
     * Fetch settlement transactions for a date range
     *
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param string|null $transactionType
     * @return Collection
     */
    public function fetchSettlements(
        Carbon $startDate,
        Carbon $endDate,
        ?string $transactionType = null
    ): Collection {
        // If no transaction type specified, fetch all types
        if ($transactionType === null) {
            return $this->fetchAllTransactionTypes($startDate, $endDate);
        }

        return $this->fetchSettlementsInTwoWeekChunks($startDate, $endDate, $transactionType);
    }

    /**
     * Fetch all transaction types for a date range
     */
    protected function fetchAllTransactionTypes(Carbon $startDate, Carbon $endDate): Collection
    {
        $transactionTypes = [
            'Sale', 'Return', 'Discount', 'DeliveryFee', 'Commission',
            'ReturnCommission', 'ReturnDeliveryFee', 'MarketplaceServiceFee',
            'OverseasOperationalFee', 'InternationalServiceFee'
        ];

        $allTransactions = collect();

        foreach ($transactionTypes as $type) {
            $transactions = $this->fetchSettlementsInTwoWeekChunks($startDate, $endDate, $type);
            $allTransactions = $allTransactions->merge($transactions);
        }

        return $allTransactions;
    }

    /**
     * Fetch settlements in 14-day chunks (API limit is 15 days)
     */
    protected function fetchSettlementsInTwoWeekChunks(
        Carbon $startDate,
        Carbon $endDate,
        string $transactionType
    ): Collection {
        $allTransactions = collect();
        $currentStart = $startDate->copy();

        while ($currentStart->lt($endDate)) {
            // End at 14 days from current start
            $currentEnd = $currentStart->copy()->addDays(14);

            // Don't go beyond the requested end date
            if ($currentEnd->gt($endDate)) {
                $currentEnd = $endDate->copy();
            }

            $transactions = $this->fetchSettlementsForDateRange(
                $currentStart,
                $currentEnd,
                $transactionType
            );

            $allTransactions = $allTransactions->merge($transactions);

            // Move to the next 14-day period
            $currentStart = $currentStart->copy()->addDays(14);
        }

        return $allTransactions;
    }

    /**
     * Fetch settlements for a specific date range and transaction type
     */
    protected function fetchSettlementsForDateRange(
        Carbon $startDate,
        Carbon $endDate,
        string $transactionType,
        int $page = 0,
        int $size = 1000
    ): Collection {
        $allContent = collect();
        $currentPage = $page;

        do {
            $response = Http::withBasicAuth($this->apiKey, $this->apiSecret)
                ->get("{$this->baseUrl}/integration/finance/che/sellers/{$this->supplierId}/settlements", [
                    'transactionType' => $transactionType,
                    'startDate' => $startDate->timestamp * 1000,
                    'endDate' => $endDate->timestamp * 1000,
                    'page' => $currentPage,
                    'size' => $size,
                ]);

            if (!$response->successful()) {
                throw new \Exception("Failed to fetch settlements: {$response->body()}");
            }

            $data = $response->json();
            $content = collect($data['content'] ?? []);
            $allContent = $allContent->merge($content);

            $totalPages = $data['totalPages'] ?? 1;
            $currentPage++;

        } while ($currentPage < $totalPages);

        return $allContent;
    }
}
```

### 4. Create Sync Command

**File**: `app/Console/Commands/SyncTrendyolFinance.php`

```bash
php artisan make:command SyncTrendyolFinance
```

```php
<?php

namespace App\Console\Commands;

use App\Models\Order\Order;
use App\Models\Order\OrderSettlementTransaction;
use App\Services\Integrations\SalesChannels\TrendyolFinanceAdapter;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncTrendyolFinance extends Command
{
    protected $signature = 'trendyol:sync-finance
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}
                            {--type= : Transaction type}';

    protected $description = 'Sync Trendyol settlement transactions';

    public function handle(TrendyolFinanceAdapter $adapter): int
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))
            : Carbon::now()->subDays(30);

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))
            : Carbon::now();

        $type = $this->option('type');

        $this->info("Fetching settlement transactions from {$from->toDateString()} to {$to->toDateString()}");

        if ($type) {
            $this->info("Transaction type: {$type}");
        }

        $transactions = $adapter->fetchSettlements($from, $to, $type);

        $this->info("Found {$transactions->count()} transactions");

        $processed = 0;
        $errors = 0;

        foreach ($transactions as $transaction) {
            try {
                $this->processTransaction($transaction);
                $processed++;
            } catch (\Exception $e) {
                $this->error("Error processing transaction: {$e->getMessage()}");
                $errors++;
            }
        }

        $this->info("Processed: {$processed}, Errors: {$errors}");

        return self::SUCCESS;
    }

    protected function processTransaction(array $transaction): void
    {
        // Find order by order number
        $orderNumber = $transaction['orderNumber'] ?? null;

        if (!$orderNumber) {
            throw new \Exception('Transaction missing order number');
        }

        $order = Order::where('order_number', $orderNumber)->first();

        if (!$order) {
            $this->warn("Order {$orderNumber} not found, skipping transaction");
            return;
        }

        // Create or update settlement transaction
        OrderSettlementTransaction::updateOrCreate(
            [
                'order_id' => $order->id,
                'transaction_type' => $transaction['transactionType'],
                'external_transaction_id' => $transaction['id'] ?? null,
            ],
            [
                'settlement_amount' => $transaction['settlementAmount'] ?? 0,
                'commission' => $transaction['commission'] ?? 0,
                'seller_revenue' => $transaction['sellerRevenue'] ?? 0,
                'platform_service_fee' => $transaction['platformServiceFee'] ?? 0,
                'penalty_fee' => $transaction['penaltyFee'] ?? 0,
                'shipping_cost' => $transaction['shippingCost'] ?? 0,
                'return_shipping_cost' => $transaction['returnShippingCost'] ?? 0,
                'overseas_operation_fee' => $transaction['overseasOperationFee'] ?? 0,
                'international_service_fee' => $transaction['internationalServiceFee'] ?? 0,
                'transaction_date' => isset($transaction['transactionDate'])
                    ? Carbon::createFromTimestampMs($transaction['transactionDate'])
                    : null,
                'payment_date' => isset($transaction['paymentDate'])
                    ? Carbon::createFromTimestampMs($transaction['paymentDate'])
                    : null,
                'currency' => $transaction['currency'] ?? 'TRY',
                'description' => $transaction['description'] ?? null,
                'raw_data' => $transaction,
            ]
        );
    }
}
```

### 5. Update Order View (Filament Infolist)

**File**: `app/Filament/Resources/Order/Orders/Infolists/OrderInfolist.php`

Add new section after Order Summary:

```php
Schemas\Components\Section::make(__('Financial Breakdown'))
    ->schema([
        Infolists\Components\TextEntry::make('total_commission')
            ->label(__('Commission'))
            ->money(fn ($record) => $record->currency)
            ->color('warning'),

        Infolists\Components\TextEntry::make('platform_service_fee')
            ->label(__('Platform Service Fee'))
            ->money(fn ($record) => $record->currency)
            ->state(fn ($record) => $record->getTotalPlatformServiceFee())
            ->color('warning'),

        Infolists\Components\TextEntry::make('penalty_fee')
            ->label(__('Penalties'))
            ->money(fn ($record) => $record->currency)
            ->state(fn ($record) => $record->getTotalPenalties())
            ->color('danger')
            ->visible(fn ($record) => $record->getTotalPenalties()->getAmount() > 0),

        Infolists\Components\TextEntry::make('shipping_cost')
            ->label(__('Shipping Cost'))
            ->money(fn ($record) => $record->currency)
            ->state(fn ($record) => $record->getTotalShippingCosts())
            ->color('warning'),

        Infolists\Components\TextEntry::make('return_shipping_cost')
            ->label(__('Return Shipping'))
            ->money(fn ($record) => $record->currency)
            ->state(fn ($record) => $record->getTotalReturnShippingCosts())
            ->color('warning')
            ->visible(fn ($record) => $record->getTotalReturnShippingCosts()->getAmount() > 0),

        Infolists\Components\TextEntry::make('net_profit')
            ->label(__('Net Profit'))
            ->money(fn ($record) => $record->currency)
            ->state(fn ($record) => $record->getNetProfit())
            ->weight('bold')
            ->size('lg')
            ->color(fn ($record) => $record->getNetProfit()->isPositive() ? 'success' : 'danger')
            ->columnSpanFull(),
    ])
    ->columns(2)
    ->collapsible()
    ->visible(fn ($record) => $record->isExternal()),

// Add settlement transactions table
Schemas\Components\Section::make(__('Settlement Transactions'))
    ->schema([
        Infolists\Components\RepeatableEntry::make('settlementTransactions')
            ->label('')
            ->schema([
                Infolists\Components\TextEntry::make('transaction_type')
                    ->label(__('Type'))
                    ->badge(),

                Infolists\Components\TextEntry::make('settlement_amount')
                    ->label(__('Amount'))
                    ->money(fn ($record) => $record->currency),

                Infolists\Components\TextEntry::make('transaction_date')
                    ->label(__('Date'))
                    ->dateTime(),

                Infolists\Components\TextEntry::make('payment_date')
                    ->label(__('Payment Date'))
                    ->dateTime()
                    ->placeholder(__('Pending')),

                Infolists\Components\TextEntry::make('description')
                    ->label(__('Description'))
                    ->placeholder(__('No description'))
                    ->columnSpanFull(),
            ])
            ->columns(4)
            ->contained(false),
    ])
    ->collapsible()
    ->collapsed()
    ->visible(fn ($record) => $record->settlementTransactions->count() > 0),
```

## Testing Plan

1. **Test Finance API Access**
   ```bash
   # Test fetching settlements for a small date range
   php artisan tinker
   $adapter = new \App\Services\Integrations\SalesChannels\TrendyolFinanceAdapter();
   $transactions = $adapter->fetchSettlements(now()->subDays(7), now());
   $transactions->count();
   ```

2. **Test Sync Command**
   ```bash
   # Sync last 14 days
   php artisan trendyol:sync-finance --from=2025-11-17 --to=2025-12-01

   # Sync specific transaction type
   php artisan trendyol:sync-finance --type=Sale --from=2025-11-17 --to=2025-12-01
   ```

3. **Verify Data in Database**
   ```bash
   php artisan tinker
   \App\Models\Order\OrderSettlementTransaction::count();
   $order = \App\Models\Order\Order::first();
   $order->settlementTransactions;
   $order->getNetProfit();
   ```

4. **Test Order View in Filament**
   - Navigate to an order detail page
   - Verify Financial Breakdown section shows correct totals
   - Verify Settlement Transactions table displays all transaction records

## Additional Notes

### Cargo Invoice API

There's also a Cargo Invoice API endpoint available:

```
GET https://apigw.trendyol.com/integration/finance/che/sellers/{sellerId}/cargo-invoice/{invoiceSerialNumber}/items
```

**Current Status**: API is accessible but returns empty results. This may be because:
- Cargo invoices are only issued periodically (monthly/quarterly)
- Need actual cargo invoice serial numbers from Trendyol dashboard
- Invoices haven't been generated yet for recent orders

**Recommendation**: Implement after settlement transactions are working. Can be added as additional enhancement to link cargo costs directly to specific invoice documents.

### Scheduling

Consider adding automatic daily sync:

**File**: `routes/console.php`

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('trendyol:sync-finance --from=-7days --to=now')
    ->daily()
    ->at('02:00');
```

### Performance Considerations

- Settlement transactions can accumulate quickly (multiple transaction types per order)
- Consider adding indexes on `order_id`, `transaction_type`, and `transaction_date`
- May need to eager load `settlementTransactions` when displaying order lists
- Consider caching net profit calculations for large result sets

## References

- [Trendyol Accounting Integration Docs](https://developers.trendyol.com/en/docs/trendyol-accounting-integration/current-account-statement)
- [Cargo Invoice Details](https://developers.trendyol.com/en/docs/trendyol-accounting-integration/cargo-invoice-details)
