<?php

namespace App\Models\Customer;

use App\Enums\Order\OrderChannel;
use App\Models\Address\Address;
use App\Models\Order\Order;
use App\Models\Platform\PlatformMapping;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Customer extends Model
{
    protected $fillable = [
        'channel',
        'first_name',
        'last_name',
        'email',
        'phone',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function platformMappings(): MorphMany
    {
        return $this->morphMany(PlatformMapping::class, 'entity');
    }

    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
