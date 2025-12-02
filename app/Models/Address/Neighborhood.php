<?php

namespace App\Models\Address;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class Neighborhood extends Model
{
    use HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'district_id',
        'name',
        'population',
        'postal_code',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
