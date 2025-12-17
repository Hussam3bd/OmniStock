<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasLabel;

enum AccountType: string implements HasLabel
{
    case BANK = 'bank';
    case CASH = 'cash';
    case CREDIT_CARD = 'credit_card';
    case PAYMENT_GATEWAY = 'payment_gateway';
    case EQUITY = 'equity';

    public function getLabel(): string
    {
        return match ($this) {
            self::BANK => __('Bank Account'),
            self::CASH => __('Cash'),
            self::CREDIT_CARD => __('Credit Card'),
            self::PAYMENT_GATEWAY => __('Payment Gateway'),
            self::EQUITY => __('Owner\'s Equity'),
        };
    }
}
