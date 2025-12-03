<?php

namespace App\Enums\Integration;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum IntegrationProvider: string implements HasColor, HasIcon, HasLabel
{
    case SHOPIFY = 'shopify';
    case TRENDYOL = 'trendyol';
    case BASIT_KARGO = 'basit_kargo';
    case STRIPE = 'stripe';
    case IYZICO = 'iyzico';
    case TRENDYOL_EFATURA = 'trendyol_efatura';

    public function getLabel(): string
    {
        return match ($this) {
            self::SHOPIFY => 'Shopify',
            self::TRENDYOL => 'Trendyol',
            self::BASIT_KARGO => 'Basit Kargo',
            self::STRIPE => 'Stripe',
            self::IYZICO => 'Iyzico',
            self::TRENDYOL_EFATURA => 'Trendyol E-Fatura',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SHOPIFY => 'success',
            self::TRENDYOL => 'warning',
            self::BASIT_KARGO => 'info',
            self::STRIPE => 'primary',
            self::IYZICO => 'warning',
            self::TRENDYOL_EFATURA => 'success',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::SHOPIFY => 'heroicon-o-shopping-bag',
            self::TRENDYOL => 'heroicon-o-building-storefront',
            self::BASIT_KARGO => 'heroicon-o-truck',
            self::STRIPE => 'heroicon-o-credit-card',
            self::IYZICO => 'heroicon-o-banknotes',
            self::TRENDYOL_EFATURA => 'heroicon-o-document-text',
        };
    }

    public function getType(): IntegrationType
    {
        return match ($this) {
            self::SHOPIFY, self::TRENDYOL => IntegrationType::SALES_CHANNEL,
            self::BASIT_KARGO => IntegrationType::SHIPPING_PROVIDER,
            self::STRIPE, self::IYZICO => IntegrationType::PAYMENT_GATEWAY,
            self::TRENDYOL_EFATURA => IntegrationType::INVOICE_PROVIDER,
        };
    }

    public static function forType(IntegrationType $type): array
    {
        return array_filter(
            self::cases(),
            fn (self $provider) => $provider->getType() === $type
        );
    }
}
