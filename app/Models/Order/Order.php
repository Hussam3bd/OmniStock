<?php

namespace App\Models\Order;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Enums\Shipping\ShippingCarrier;
use App\Helpers\CurrencyHelper;
use App\Models\Accounting\Transaction;
use App\Models\Address\Address;
use App\Models\Concerns\HasAddressSnapshots;
use App\Models\Currency;
use App\Models\Customer\Customer;
use App\Models\Inventory\InventoryMovement;
use App\Models\Platform\PlatformMapping;
use Cknow\Money\Casts\MoneyIntegerCast;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use HasAddressSnapshots;
    use LogsActivity;

    protected $fillable = [
        'customer_id',
        'shipping_address_id',
        'billing_address_id',
        'channel',
        'integration_id',
        'order_number',
        'order_status',
        'payment_status',
        'payment_method',
        'payment_gateway',
        'payment_transaction_id',
        'payment_gateway_fee',
        'payment_gateway_commission_rate',
        'payment_gateway_commission_amount',
        'payment_payout_amount',
        'fulfillment_status',
        'return_status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
        'total_product_cost',
        'total_commission',
        'currency',
        'currency_id',
        'exchange_rate',
        'invoice_number',
        'invoice_date',
        'invoice_url',
        'notes',
        'order_date',
        'shipping_carrier',
        'shipping_desi',
        'shipping_cost_excluding_vat',
        'shipping_vat_rate',
        'shipping_vat_amount',
        'shipping_rate_id',
        'shipping_tracking_number',
        'shipping_tracking_url',
        'shipping_aggregator_integration_id',
        'shipping_aggregator_shipment_id',
        'shipping_aggregator_data',
        'shipped_at',
        'delivered_at',
        'estimated_delivery_start',
        'estimated_delivery_end',
    ];

    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'order_status' => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'fulfillment_status' => FulfillmentStatus::class,
            'subtotal' => MoneyIntegerCast::class,
            'tax_amount' => MoneyIntegerCast::class,
            'shipping_amount' => MoneyIntegerCast::class,
            'discount_amount' => MoneyIntegerCast::class,
            'total_amount' => MoneyIntegerCast::class,
            'total_product_cost' => MoneyIntegerCast::class,
            'total_commission' => MoneyIntegerCast::class,
            'payment_gateway_fee' => MoneyIntegerCast::class,
            'payment_gateway_commission_amount' => MoneyIntegerCast::class,
            'payment_payout_amount' => MoneyIntegerCast::class,
            'payment_gateway_commission_rate' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'invoice_date' => 'date',
            'order_date' => 'datetime',
            'shipping_desi' => 'decimal:2',
            'shipping_carrier' => ShippingCarrier::class,
            'shipping_cost_excluding_vat' => MoneyIntegerCast::class,
            'shipping_vat_rate' => 'decimal:2',
            'shipping_vat_amount' => MoneyIntegerCast::class,
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'estimated_delivery_start' => 'datetime',
            'estimated_delivery_end' => 'datetime',
            'shipping_aggregator_data' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'order_status',
                'payment_status',
                'fulfillment_status',
                'subtotal',
                'tax_amount',
                'shipping_amount',
                'discount_amount',
                'total_amount',
                'invoice_number',
                'invoice_date',
                'invoice_url',
                'notes',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function isExternal(): bool
    {
        return $this->channel?->isExternal() ?? false;
    }

    /**
     * Get total shipping cost including VAT (what we pay to carrier)
     */
    protected function totalShippingCost(): Attribute
    {
        return Attribute::make(
            get: function (): ?Money {
                if (! $this->shipping_cost_excluding_vat) {
                    return null;
                }

                $cost = $this->shipping_cost_excluding_vat;

                if ($this->shipping_vat_amount) {
                    $cost = $cost->add($this->shipping_vat_amount);
                }

                return $cost;
            }
        );
    }

    /**
     * Get total payment gateway cost (fixed fee + commission)
     */
    protected function totalPaymentGatewayCost(): Attribute
    {
        return Attribute::make(
            get: function (): ?Money {
                $cost = null;

                if ($this->payment_gateway_fee) {
                    $cost = $this->payment_gateway_fee;
                }

                if ($this->payment_gateway_commission_amount) {
                    $cost = $cost
                        ? $cost->add($this->payment_gateway_commission_amount)
                        : $this->payment_gateway_commission_amount;
                }

                return $cost;
            }
        );
    }

    /**
     * Get effective product cost (COGS)
     * For rejected orders where products came back, return 0
     */
    protected function effectiveProductCost(): Attribute
    {
        return Attribute::make(
            get: function (): ?Money {
                // If order is rejected (COD delivery refused), product came back = no cost
                if ($this->order_status === OrderStatus::REJECTED && $this->total_product_cost) {
                    // Return zero in the same currency
                    return $this->total_product_cost->subtract($this->total_product_cost);
                }

                return $this->total_product_cost;
            }
        );
    }

    /**
     * Calculate gross profit: Revenue - COGS - Shipping Costs - Platform Commission - Payment Gateway Fees
     * For Shopify: Revenue includes shipping fee from customer
     * For Trendyol: Revenue is product price only (free shipping to customer)
     * For rejected orders: COGS = 0 (product came back), only shipping loss applies
     */
    protected function grossProfit(): Attribute
    {
        return Attribute::make(
            get: function (): ?Money {
                if (! $this->total_amount) {
                    return null;
                }

                $profit = $this->total_amount;

                // Subtract COGS (Cost of Goods Sold) - uses effective cost (0 for rejected orders)
                if ($this->effective_product_cost) {
                    $profit = $profit->subtract($this->effective_product_cost);
                }

                // Subtract shipping costs (what we pay to carrier)
                if ($this->total_shipping_cost) {
                    $profit = $profit->subtract($this->total_shipping_cost);
                }

                // Subtract platform commission (e.g., Trendyol commission)
                if ($this->total_commission) {
                    $profit = $profit->subtract($this->total_commission);
                }

                // Subtract payment gateway fees (e.g., Iyzico, Stripe fees)
                if ($this->total_payment_gateway_cost) {
                    $profit = $profit->subtract($this->total_payment_gateway_cost);
                }

                return $profit;
            }
        );
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function billingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function shippingAggregatorIntegration(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Integration\Integration::class, 'shipping_aggregator_integration_id');
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Integration\Integration::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(PlatformMapping::class, 'entity');
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function hasReturns(): bool
    {
        return $this->return_status && $this->return_status !== 'none';
    }

    public function hasPartialReturn(): bool
    {
        return $this->return_status === 'partial';
    }

    public function hasFullReturn(): bool
    {
        return $this->return_status === 'full';
    }

    /**
     * Get the total amount in default currency using stored exchange rate
     * This ensures historical accuracy - uses the rate from order creation time
     */
    public function getTotalInDefaultCurrency(): float
    {
        if (! $this->exchange_rate || ! $this->total_amount) {
            return 0;
        }

        // Convert from order currency to default currency
        return $this->total_amount->getAmount() * $this->exchange_rate / 100;
    }

    /**
     * Get formatted total with currency symbol
     */
    public function getFormattedTotal(): string
    {
        if (! $this->total_amount) {
            return '-';
        }

        return CurrencyHelper::format($this->total_amount, $this->currency);
    }

    /**
     * Get formatted total with conversion to default currency
     * Example: "$100.00 USD (₺3,420.00 TRY)"
     */
    public function getFormattedTotalWithConversion(): string
    {
        if (! $this->total_amount || ! $this->currency_id) {
            return $this->getFormattedTotal();
        }

        $defaultCurrency = Currency::getDefault();

        // If this is already in default currency, no conversion needed
        if ($this->currency_id === $defaultCurrency?->id) {
            return $this->getFormattedTotal();
        }

        $originalFormatted = $this->getFormattedTotal();
        $convertedAmount = $this->getTotalInDefaultCurrency();
        $convertedFormatted = CurrencyHelper::format(
            \Cknow\Money\Money::of($convertedAmount, $defaultCurrency->code),
            $defaultCurrency->code
        );

        return "{$originalFormatted} ({$convertedFormatted})";
    }

    /**
     * Convert any Money amount to default currency using stored exchange rate
     */
    public function convertToDefaultCurrency(?Money $amount): ?Money
    {
        if (! $amount || ! $this->exchange_rate) {
            return $amount;
        }

        $defaultCurrency = Currency::getDefault();
        $convertedAmountInCents = (int) round($amount->getAmount() * $this->exchange_rate);

        return new Money($convertedAmountInCents, new \Money\Currency($defaultCurrency->code));
    }

    /**
     * Convert order total to any currency using historical exchange rate from order date
     * This allows you to see what the order was worth in USD (or any currency) on the day it was placed
     *
     * Example: $order->getHistoricalValueInCurrency('USD') - shows what TRY order was worth in USD that day
     */
    public function getHistoricalValueInCurrency(string $targetCurrencyCode): ?Money
    {
        if (! $this->total_amount || ! $this->currency_id || ! $this->order_date) {
            return null;
        }

        // Find target currency
        $targetCurrency = Currency::where('code', strtoupper($targetCurrencyCode))->first();

        if (! $targetCurrency) {
            return null;
        }

        // If order is already in target currency, return Money object in that currency
        if ($this->currency_id === $targetCurrency->id) {
            return new Money($this->total_amount->getAmount(), new \Money\Currency($targetCurrency->code));
        }

        // Get historical exchange rate from order date
        $rate = \App\Models\ExchangeRate::getRate(
            $this->currency_id,
            $targetCurrency->id,
            $this->order_date
        );

        if (! $rate) {
            // Try to get current rate as fallback
            $rate = CurrencyHelper::getRate($this->currency, $targetCurrency->code);
        }

        if (! $rate) {
            return null;
        }

        // Convert: order amount (in cents) × exchange rate
        // getAmount() returns cents, multiply by rate, result is also in cents
        $convertedAmountInCents = (int) round($this->total_amount->getAmount() * $rate);

        // Create Money object with amount in cents
        return new Money($convertedAmountInCents, new \Money\Currency($targetCurrency->code));
    }

    /**
     * Get formatted historical value in any currency
     * Example: $order->getFormattedHistoricalValue('USD') returns "$23.50 USD"
     */
    public function getFormattedHistoricalValue(string $targetCurrencyCode): ?string
    {
        $converted = $this->getHistoricalValueInCurrency($targetCurrencyCode);

        if (! $converted) {
            return null;
        }

        // Money object format() method returns formatted string with symbol
        return $converted->format();
    }
}
