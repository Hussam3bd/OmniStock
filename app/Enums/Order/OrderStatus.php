<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasIcon, HasLabel
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case PROCESSING = 'processing';
    case ON_HOLD = 'on_hold';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => __('Pending'),
            self::CONFIRMED => __('Confirmed'),
            self::PROCESSING => __('Processing'),
            self::ON_HOLD => __('On Hold'),
            self::COMPLETED => __('Completed'),
            self::CANCELLED => __('Cancelled'),
            self::REFUNDED => __('Refunded'),
            self::FAILED => __('Failed'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::CONFIRMED => 'info',
            self::PROCESSING => 'primary',
            self::ON_HOLD => 'gray',
            self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
            self::REFUNDED => 'warning',
            self::FAILED => 'danger',
        };
    }

    public function getIcon(): ?string
    {
        return match ($this) {
            self::PENDING => 'heroicon-o-clock',
            self::CONFIRMED => 'heroicon-o-check-circle',
            self::PROCESSING => 'heroicon-o-arrow-path',
            self::ON_HOLD => 'heroicon-o-pause-circle',
            self::COMPLETED => 'heroicon-o-check-badge',
            self::CANCELLED => 'heroicon-o-x-circle',
            self::REFUNDED => 'heroicon-o-arrow-uturn-left',
            self::FAILED => 'heroicon-o-exclamation-triangle',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PENDING => __('Order received, awaiting confirmation'),
            self::CONFIRMED => __('Order confirmed, ready for processing'),
            self::PROCESSING => __('Order is being prepared and processed'),
            self::ON_HOLD => __('Order temporarily suspended'),
            self::COMPLETED => __('Order successfully completed and delivered'),
            self::CANCELLED => __('Order cancelled'),
            self::REFUNDED => __('Order refunded to customer'),
            self::FAILED => __('Order failed to process'),
        };
    }
}
