<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasLabel;

enum RefundType: string implements HasLabel
{
    case ORIGINAL = 'original'; // The original transaction that was refunded
    case REFUND = 'refund'; // The refund transaction itself

    public function getLabel(): string
    {
        return match ($this) {
            self::ORIGINAL => __('Original (Refunded)'),
            self::REFUND => __('Refund'),
        };
    }
}
