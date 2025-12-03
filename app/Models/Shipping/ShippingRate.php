<?php

namespace App\Models\Shipping;

use App\Enums\Shipping\ShippingCarrier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    protected $fillable = [
        'shipping_rate_table_id',
        'carrier',
        'desi_from',
        'desi_to',
        'price_excluding_vat',
        'vat_rate',
        'is_heavy_cargo',
    ];

    protected function casts(): array
    {
        return [
            'carrier' => ShippingCarrier::class,
            'desi_from' => 'decimal:2',
            'desi_to' => 'decimal:2',
            'price_excluding_vat' => 'integer',
            'vat_rate' => 'decimal:2',
            'is_heavy_cargo' => 'boolean',
        ];
    }

    public function rateTable(): BelongsTo
    {
        return $this->belongsTo(ShippingRateTable::class, 'shipping_rate_table_id');
    }

    /**
     * Calculate total price including VAT
     */
    public function getTotalPriceAttribute(): int
    {
        $vat = ($this->price_excluding_vat * $this->vat_rate) / 100;

        return (int) ($this->price_excluding_vat + $vat);
    }

    /**
     * Calculate VAT amount
     */
    public function getVatAmountAttribute(): int
    {
        return (int) (($this->price_excluding_vat * $this->vat_rate) / 100);
    }

    /**
     * Find rate for specific carrier and desi
     */
    public static function findRateForDesi(ShippingCarrier $carrier, float $desi, ?int $rateTableId = null): ?self
    {
        // Use active rate table if not specified
        if (! $rateTableId) {
            $activeTable = ShippingRateTable::active();
            if (! $activeTable) {
                return null;
            }
            $rateTableId = $activeTable->id;
        }

        return self::where('shipping_rate_table_id', $rateTableId)
            ->where('carrier', $carrier->value)
            ->where('desi_from', '<=', $desi)
            ->where(function ($query) use ($desi) {
                $query->whereNull('desi_to')
                    ->orWhere('desi_to', '>=', $desi);
            })
            ->first();
    }
}
