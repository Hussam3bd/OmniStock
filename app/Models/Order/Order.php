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
        'fulfillment_status',
        'return_status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
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
            'total_commission' => MoneyIntegerCast::class,
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
