<?php

namespace App\Enums\Integration;

use Filament\Support\Contracts\HasLabel;

enum IntegrationType: string implements HasLabel
{
    case SALES_CHANNEL = 'sales_channel';
    case SHIPPING_PROVIDER = 'shipping_provider';
    case PAYMENT_GATEWAY = 'payment_gateway';
    case INVOICE_PROVIDER = 'invoice_provider';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALES_CHANNEL => __('Sales Channel'),
            self::SHIPPING_PROVIDER => __('Shipping Provider'),
            self::PAYMENT_GATEWAY => __('Payment Gateway'),
            self::INVOICE_PROVIDER => __('Invoice Provider'),
        };
    }
}
