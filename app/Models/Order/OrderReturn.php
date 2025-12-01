<?php

namespace App\Models\Order;

use App\Enums\Order\ReturnStatus;
use Cknow\Money\Casts\MoneyIntegerCast;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class OrderReturn extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'returns';

    protected $fillable = [
        'order_id',
        'return_number',
        'platform',
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
        'reason_code',
        'reason_name',
        'customer_note',
        'internal_note',
        'return_shipping_carrier',
        'return_tracking_number',
        'return_tracking_url',
        'return_label_url',
        'return_shipping_cost',
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
            'status' => ReturnStatus::class,
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'label_generated_at' => 'datetime',
            'shipped_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'return_shipping_cost' => MoneyIntegerCast::class,
            'original_shipping_cost' => MoneyIntegerCast::class,
            'total_refund_amount' => MoneyIntegerCast::class,
            'restocking_fee' => MoneyIntegerCast::class,
            'platform_data' => 'array',
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

    // Media collections
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('customer_photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

        $this->addMediaCollection('documents')
            ->acceptsMimeTypes(['application/pdf', 'image/jpeg', 'image/png']);

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

    public function getTotalShippingLoss(): Money
    {
        return $this->original_shipping_cost->add($this->return_shipping_cost);
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
        } elseif ($totalItemsReturned >= $totalItemsInOrder) {
            $returnStatus = 'full';
        } else {
            $returnStatus = 'partial';
        }

        $order->update(['return_status' => $returnStatus]);
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
