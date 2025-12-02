<?php

namespace App\Models\Address;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Province extends Model
{
    use HasTranslations;

    public array $translatable = ['name', 'region'];

    protected $fillable = [
        'country_id',
        'name',
        'region',
        'population',
        'area',
        'altitude',
        'area_codes',
        'is_coastal',
        'is_metropolitan',
        'latitude',
        'longitude',
        'nuts1_code',
        'nuts2_code',
        'nuts3_code',
    ];

    protected function casts(): array
    {
        return [
            'area_codes' => 'array',
            'is_coastal' => 'boolean',
            'is_metropolitan' => 'boolean',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
