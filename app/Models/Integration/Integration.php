<?php

namespace App\Models\Integration;

use App\Models\Accounting\Account;
use App\Models\Inventory\Location;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Integration extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'provider',
        'is_active',
        'settings',
        'config',
        'location_id',
        'account_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'config' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
