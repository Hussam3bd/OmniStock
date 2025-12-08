<?php

namespace App\Models\Integration;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Accounting\Account;
use App\Models\Inventory\Location;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
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
            'type' => IntegrationType::class,
            'provider' => IntegrationProvider::class,
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

    /**
     * Get the adapter instance for this integration based on provider enum
     */
    public function adapter(): mixed
    {
        return match ($this->provider) {
            IntegrationProvider::SHOPIFY => new ShopifyAdapter($this),
            IntegrationProvider::TRENDYOL => new TrendyolAdapter($this),
            IntegrationProvider::BASIT_KARGO => new BasitKargoAdapter($this),
            default => null,
        };
    }
}
