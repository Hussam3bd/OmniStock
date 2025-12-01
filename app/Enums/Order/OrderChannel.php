<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum OrderChannel: string implements HasColor, HasIcon, HasLabel
{
    case PORTAL = 'portal';
    case TRENDYOL = 'trendyol';
    case SHOPIFY = 'shopify';

    public function getLabel(): string
    {
        return match ($this) {
            self::PORTAL => __('Portal'),
            self::TRENDYOL => __('Trendyol'),
            self::SHOPIFY => __('Shopify'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PORTAL => 'primary',
            self::TRENDYOL => 'warning',
            self::SHOPIFY => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PORTAL => 'heroicon-o-computer-desktop',
            self::TRENDYOL => 'heroicon-o-building-storefront',
            self::SHOPIFY => 'heroicon-o-shopping-bag',
        };
    }

    public function isExternal(): bool
    {
        return match ($this) {
            self::PORTAL => false,
            default => true,
        };
    }
}
