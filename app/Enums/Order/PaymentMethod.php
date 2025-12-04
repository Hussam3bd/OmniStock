<?php

namespace App\Enums\Order;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasColor, HasLabel
{
    case ONLINE = 'online';
    case COD = 'cod';
    case BANK_TRANSFER = 'bank_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::ONLINE => __('Online Payment'),
            self::COD => __('Cash on Delivery'),
            self::BANK_TRANSFER => __('Bank Transfer'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ONLINE => 'success',
            self::COD => 'warning',
            self::BANK_TRANSFER => 'info',
        };
    }
}
