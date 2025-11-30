<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasIcon, HasLabel
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';
    case VOIDED = 'voided';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => __('Pending'),
            self::AUTHORIZED => __('Authorized'),
            self::PARTIALLY_PAID => __('Partially Paid'),
            self::PAID => __('Paid'),
            self::PARTIALLY_REFUNDED => __('Partially Refunded'),
            self::REFUNDED => __('Refunded'),
            self::VOIDED => __('Voided'),
            self::FAILED => __('Failed'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::AUTHORIZED => 'info',
            self::PARTIALLY_PAID => 'warning',
            self::PAID => 'success',
            self::PARTIALLY_REFUNDED => 'warning',
            self::REFUNDED => 'gray',
            self::VOIDED => 'danger',
            self::FAILED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PENDING => 'heroicon-o-clock',
            self::AUTHORIZED => 'heroicon-o-shield-check',
            self::PARTIALLY_PAID => 'heroicon-o-banknotes',
            self::PAID => 'heroicon-o-check-circle',
            self::PARTIALLY_REFUNDED => 'heroicon-o-arrow-uturn-left',
            self::REFUNDED => 'heroicon-o-arrow-uturn-left',
            self::VOIDED => 'heroicon-o-x-circle',
            self::FAILED => 'heroicon-o-exclamation-triangle',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PENDING => __('Payment is pending'),
            self::AUTHORIZED => __('Payment has been authorized'),
            self::PARTIALLY_PAID => __('Order has been partially paid'),
            self::PAID => __('Payment completed successfully'),
            self::PARTIALLY_REFUNDED => __('Payment has been partially refunded'),
            self::REFUNDED => __('Payment has been fully refunded'),
            self::VOIDED => __('Payment has been voided'),
            self::FAILED => __('Payment failed'),
        };
    }
}
