<?php

namespace App\Models\Order;

use App\Enums\Order\OrderChannel;
use App\Enums\Order\ReturnReason;
use App\Enums\Order\ReturnStatus;
use App\Enums\Shipping\ShippingCarrier;
use App\Models\Shipping\ShippingRate;
use Cknow\Money\Casts\MoneyIntegerCast;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class OrderReturn extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'returns';

    protected $fillable = [
        'order_id',
        'return_number',
        'channel',
        'external_return_id',
        'status',
        'requested_at',
        'approved_at',
        'label_generated_at',
        'shipped_at',
        'received_at',
        'inspected_at',
        'completed_at',
        'rejected_at',
        'return_reason',
        'reason_name',
        'customer_note',
        'internal_note',
        'return_shipping_carrier',
        'return_tracking_number',
        'return_tracking_url',
        'return_label_url',
        'return_shipping_aggregator_integration_id',
        'return_shipping_aggregator_shipment_id',
        'return_shipping_aggregator_data',
        'return_shipping_cost',
        'return_shipping_desi',
        'return_shipping_vat_rate',
        'return_shipping_vat_amount',
        'return_shipping_rate_id',
        'original_shipping_cost',
        'total_refund_amount',
        'restocking_fee',
        'approved_by',
        'rejected_by',
        'inspected_by',
        'platform_data',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'status' => ReturnStatus::class,
            'return_reason' => ReturnReason::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'label_generated_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'return_shipping_carrier' => ShippingCarrier::class,
            'return_shipping_cost' => MoneyIntegerCast::class,
            'return_shipping_vat_rate' => 'decimal:2',
            'return_shipping_vat_amount' => MoneyIntegerCast::class,
            'return_shipping_desi' => 'decimal:2',
            'original_shipping_cost' => MoneyIntegerCast::class,
            'total_refund_amount' => MoneyIntegerCast::class,
            'restocking_fee' => MoneyIntegerCast::class,
            'platform_data' => 'array',
            'return_shipping_aggregator_data' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(ReturnRefund::class, 'return_id');
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(\App\Models\Platform\PlatformMapping::class, 'entity');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'rejected_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'inspected_by');
    }

    public function returnShippingRate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class, 'return_shipping_rate_id');
    }

    public function returnShippingAggregatorIntegration(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Integration\Integration::class, 'return_shipping_aggregator_integration_id');
    }

    // Media collections
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('customer_photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        $this->addMediaCollection('documents')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/svg+xml']);

        $this->addMediaCollection('inspection_photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
    }

    // Business logic methods
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function canApprove(): bool
    {
        return in_array($this->status, [
            ReturnStatus::Requested,
            ReturnStatus::PendingReview,
        ]);
    }

    public function canReject(): bool
    {
        return in_array($this->status, [
            ReturnStatus::Requested,
            ReturnStatus::PendingReview,
            ReturnStatus::Received,
            ReturnStatus::Inspecting,
        ]);
    }

    public function approve(\App\Models\User $user): void
    {
        $this->update([
            'status' => ReturnStatus::Approved,
            'approved_at' => now(),
            'approved_by' => $user->id,
        ]);

        // Update order return status
        $this->updateOrderReturnStatus();
    }

    public function reject(\App\Models\User $user, ?string $reason = null): void
    {
        $this->update([
            'status' => ReturnStatus::Rejected,
            'rejected_at' => now(),
            'rejected_by' => $user->id,
            'internal_note' => $reason,
        ]);
    }

    public function markAsReceived(\App\Models\User $user): void
    {
        $this->update([
            'status' => ReturnStatus::Received,
            'received_at' => now(),
        ]);
    }

    public function startInspection(\App\Models\User $user): void
    {
        $this->update([
            'status' => ReturnStatus::Inspecting,
            'inspected_by' => $user->id,
        ]);
    }

    public function complete(\App\Models\User $user): void
    {
        $this->update([
            'status' => ReturnStatus::Completed,
            'completed_at' => now(),
            'inspected_at' => $this->inspected_at ?? now(),
            'inspected_by' => $this->inspected_by ?? $user->id,
        ]);

        // Update order return status
        $this->updateOrderReturnStatus();
    }

    public function getReturnShippingTotalAttribute(): Money
    {
        if ($this->return_shipping_cost && $this->return_shipping_vat_amount) {
            return $this->return_shipping_cost->add($this->return_shipping_vat_amount);
        }

        return $this->return_shipping_cost ?? Money::TRY(0);
    }

    public function getTotalShippingLoss(): Money
    {
        return $this->original_shipping_cost->add($this->return_shipping_total);
    }

    public function getTotalLoss(): Money
    {
        return $this->getTotalShippingLoss()
            ->add($this->restocking_fee)
            ->add($this->total_refund_amount);
    }

    protected function updateOrderReturnStatus(): void
    {
        $order = $this->order;

        $totalItemsInOrder = $order->items->sum('quantity');
        $totalItemsReturned = $order->returns()
            ->whereIn('status', [ReturnStatus::Approved, ReturnStatus::Completed])
            ->get()
            ->flatMap->items
            ->sum('quantity');

        if ($totalItemsReturned === 0) {
            $returnStatus = 'none';
            $orderStatus = null; // Don't change order status
        } elseif ($totalItemsReturned >= $totalItemsInOrder) {
            $returnStatus = 'full';
            $orderStatus = \App\Enums\Order\OrderStatus::REFUNDED;
        } else {
            $returnStatus = 'partial';
            $orderStatus = \App\Enums\Order\OrderStatus::PARTIALLY_REFUNDED;
        }

        $updateData = ['return_status' => $returnStatus];
        if ($orderStatus !== null) {
            $updateData['order_status'] = $orderStatus;
        }

        $order->update($updateData);
    }

    // Auto-generate return number
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($return) {
            if (empty($return->return_number)) {
                $return->return_number = 'RET-'.strtoupper(uniqid());
            }
        });
    }
}
