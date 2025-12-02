<?php

namespace App\Models\Address;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class District extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'province_id',
        'name',
        'population',
        'area',
        'latitude',
        'longitude',
        'postal_code',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function neighborhoods(): HasMany
    {
        return $this->hasMany(Neighborhood::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
