<?php

namespace App\Enums\Invoice;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoiceStatus: string implements HasColor, HasLabel
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case ISSUED = 'issued';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::PENDING => 'Pending',
            self::ISSUED => 'Issued',
            self::FAILED => 'Failed',
            self::CANCELLED => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'warning',
            self::ISSUED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED => 'gray',
        };
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::ISSUED, self::PENDING]);
    }

    public function isValid(): bool
    {
        return in_array($this, [self::ISSUED, self::PENDING]);
    }
}
