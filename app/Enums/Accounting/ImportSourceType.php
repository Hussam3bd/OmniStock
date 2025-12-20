<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasLabel;

enum ImportSourceType: string implements HasLabel
{
    case CREDIT_CARD = 'credit_card';
    case BANK_ACCOUNT = 'bank_account';
    case MANUAL = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::CREDIT_CARD => __('Credit Card'),
            self::BANK_ACCOUNT => __('Bank Account'),
            self::MANUAL => __('Manual Entry'),
        };
    }
}
