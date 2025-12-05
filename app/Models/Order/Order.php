<?php

namespace App\Models\Order;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Enums\Shipping\ShippingCarrier;
use App\Models\Accounting\Transaction;
use App\Models\Address\Address;
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
    use LogsActivity;

    protected $fillable = [
        'customer_id',
        'shipping_address_id',
        'billing_address_id',
        'channel',
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

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
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
}
