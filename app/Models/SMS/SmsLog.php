<?php

namespace App\Models\SMS;

use App\Models\Customer\Customer;
use App\Models\Order\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsLog extends Model
{
    protected $fillable = [
        'order_id',
        'customer_id',
        'phone',
        'message',
        'type',
        'sent',
        'provider',
        'provider_sms_id',
        'status_code',
        'provider_response',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent' => 'boolean',
            'provider_response' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
