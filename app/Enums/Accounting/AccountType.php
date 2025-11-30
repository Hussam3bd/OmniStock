<?php

namespace App\Enums\Accounting;

enum AccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';
    case CREDIT_CARD = 'credit_card';
    case PAYMENT_GATEWAY = 'payment_gateway';

    public function getLabel(): string
    {
        return match ($this) {
            self::BANK => __('Bank Account'),
            self::CASH => __('Cash'),
            self::CREDIT_CARD => __('Credit Card'),
            self::PAYMENT_GATEWAY => __('Payment Gateway'),
        };
    }
}
