<?php

namespace App\Models\Order;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Models\Accounting\Transaction;
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
        'channel',
        'order_number',
        'order_status',
        'payment_status',
        'fulfillment_status',
        'subtotal',
        'tax_amount',
        'shipping_amount',
        'discount_amount',
        'total_amount',
        'currency',
        'invoice_number',
        'invoice_date',
        'invoice_url',
        'notes',
        'order_date',
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
            'invoice_date' => 'date',
            'order_date' => 'datetime',
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
}
