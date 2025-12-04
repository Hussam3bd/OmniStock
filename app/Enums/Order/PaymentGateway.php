<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentGateway: string implements HasColor, HasLabel
{
    case IYZICO = 'iyzico';
    case STRIPE = 'stripe';
    case MANUAL = 'manual';
    case COD = 'cod';
    case BANK_DEPOSIT = 'bank_deposit';

    /**
     * Try to create from a string, returns null if not a valid enum case
     * This allows storing unknown gateway names from external sources
     */
    public static function tryFrom(string $value): ?self
    {
        return match (strtolower($value)) {
            'iyzico' => self::IYZICO,
            'stripe' => self::STRIPE,
            'manual' => self::MANUAL,
            'cod', 'cash on delivery (cod)' => self::COD,
            'bank_deposit', 'bank deposit' => self::BANK_DEPOSIT,
            default => null,
        };
    }

    /**
     * Parse gateway name from external sources (flexible matching)
     */
    public static function parse(?string $value): ?self
    {
        if (! $value) {
            return null;
        }

        $normalized = strtolower(trim($value));

        // Direct match
        if ($enum = self::tryFrom($normalized)) {
            return $enum;
        }

        // Partial matching for external gateway names
        if (str_contains($normalized, 'iyzico')) {
            return self::IYZICO;
        }

        if (str_contains($normalized, 'stripe')) {
            return self::STRIPE;
        }

        if (str_contains($normalized, 'cod') || str_contains($normalized, 'cash on delivery')) {
            return self::COD;
        }

        if (str_contains($normalized, 'bank') && (str_contains($normalized, 'deposit') || str_contains($normalized, 'transfer'))) {
            return self::BANK_DEPOSIT;
        }

        // Return null for unknown gateways - they'll be stored as raw strings
        return null;
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::IYZICO => 'Iyzico',
            self::STRIPE => 'Stripe',
            self::MANUAL => __('Manual Payment'),
            self::COD => __('Cash on Delivery'),
            self::BANK_DEPOSIT => __('Bank Deposit'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::IYZICO => 'info',
            self::STRIPE => 'purple',
            self::MANUAL => 'gray',
            self::COD => 'warning',
            self::BANK_DEPOSIT => 'success',
        };
    }

    /**
     * Check if this gateway supports automated cost syncing
     */
    public function supportsAutomatedSync(): bool
    {
        return match ($this) {
            self::IYZICO, self::STRIPE => true,
            default => false,
        };
    }
}
