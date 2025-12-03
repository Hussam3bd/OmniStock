<?php

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShippingRateTable extends Model
{
    protected $fillable = [
        'name',
        'effective_from',
        'effective_until',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function rates(): HasMany
    {
        return $this->hasMany(ShippingRate::class);
    }

    /**
     * Get the currently active rate table
     */
    public static function active(): ?self
    {
        return self::where('is_active', true)
            ->where('effective_from', '<=', now())
            ->where(function ($query) {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', now());
            })
            ->first();
    }

    /**
     * Deactivate all rate tables
     */
    public static function deactivateAll(): void
    {
        self::query()->update(['is_active' => false]);
    }

    /**
     * Activate this rate table and deactivate others
     */
    public function activate(): void
    {
        self::deactivateAll();
        $this->update(['is_active' => true]);
    }
}
