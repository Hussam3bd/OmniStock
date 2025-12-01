<?php

namespace App\Models\Product;

use App\Enums\Order\OrderChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductChannelAvailability extends Model
{
    protected $table = 'product_channel_availability';

    protected $fillable = [
        'product_variant_id',
        'channel',
        'is_enabled',
        'channel_settings',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'channel_settings' => 'array',
        'channel' => OrderChannel::class,
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
