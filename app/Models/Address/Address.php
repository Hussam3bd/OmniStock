<?php

namespace App\Models\Address;

use App\Enums\Address\AddressType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        // Prevent hard deletion of order address snapshots
        static::deleting(function (Address $address) {
            if ($address->addressable_type === \App\Models\Order\Order::class && ! $address->isForceDeleting()) {
                // For order addresses, only allow soft delete, not hard delete
                return $address->trashed() ? true : null;
            }
        });
    }

    protected $fillable = [
        'addressable_type',
        'addressable_id',
        'country_id',
        'province_id',
        'district_id',
        'neighborhood_id',
        'type',
        'title',
        'first_name',
        'last_name',
        'company_name',
        'phone',
        'email',
        'address_line1',
        'address_line2',
        'building_name',
        'building_number',
        'floor',
        'apartment',
        'postal_code',
        'tax_office',
        'tax_number',
        'identity_number',
        'latitude',
        'longitude',
        'delivery_instructions',
        'is_default',
        'is_billing',
        'is_shipping',
    ];

    protected function casts(): array
    {
        return [
            'type' => AddressType::class,
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_default' => 'boolean',
            'is_billing' => 'boolean',
            'is_shipping' => 'boolean',
        ];
    }

    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    public function getFullNameAttribute(): string
    {
        if ($this->type === AddressType::INSTITUTIONAL && $this->company_name) {
            return $this->company_name;
        }

        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getFullAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line1,
            $this->address_line2,
            $this->building_name,
            $this->building_number ? 'No: '.$this->building_number : null,
            $this->floor ? 'Kat: '.$this->floor : null,
            $this->apartment ? 'Daire: '.$this->apartment : null,
            $this->neighborhood?->name,
            $this->district?->name,
            $this->province?->name,
            $this->postal_code,
            $this->country?->name,
        ]);

        return implode(', ', $parts);
    }

    public function isInstitutional(): bool
    {
        return $this->type === AddressType::INSTITUTIONAL;
    }

    public function isResidential(): bool
    {
        return $this->type === AddressType::RESIDENTIAL;
    }
}
