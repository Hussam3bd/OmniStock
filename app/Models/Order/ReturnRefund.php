<?php

namespace App\Models\Order;

use Cknow\Money\Casts\MoneyIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRefund extends Model
{
    protected $fillable = [
        'return_id',
        'refund_number',
        'amount',
        'currency',
        'method',
        'status',
        'external_refund_id',
        'payment_gateway',
        'initiated_at',
        'processed_at',
        'completed_at',
        'failed_at',
        'processed_by',
        'note',
        'failure_reason',
        'platform_data',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyIntegerCast::class,
            'initiated_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'platform_data' => 'array',
        ];
    }

    public function return(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'return_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsProcessing(\App\Models\User $user): void
    {
        $this->update([
            'status' => 'processing',
            'processed_at' => now(),
            'processed_by' => $user->id,
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $reason): void
    {
        $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    // Auto-generate refund number
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($refund) {
            if (empty($refund->refund_number)) {
                $refund->refund_number = 'REF-'.strtoupper(uniqid());
            }
        });
    }
}
