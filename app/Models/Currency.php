<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'symbol',
        'decimal_places',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function exchangeRatesFrom(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'from_currency_id');
    }

    public function exchangeRatesTo(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'to_currency_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(\App\Models\Purchase\PurchaseOrder::class);
    }

    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }

    public static function getActive(): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('is_active', true)->get();
    }
}
