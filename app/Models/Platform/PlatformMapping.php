<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PlatformMapping extends Model
{
    protected $fillable = [
        'platform',
        'entity_type',
        'entity_id',
        'platform_id',
        'platform_data',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'platform_data' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo();
    }
}
