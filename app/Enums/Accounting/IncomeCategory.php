<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncomeCategory: string implements HasColor, HasLabel
{
    case SALES_SHOPIFY = 'sales_shopify';
    case SALES_TRENDYOL = 'sales_trendyol';
    case SALES_DIRECT = 'sales_direct';
    case REFUND_RECEIVED = 'refund_received';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::SALES_SHOPIFY => __('Sales - Shopify'),
            self::SALES_TRENDYOL => __('Sales - Trendyol'),
            self::SALES_DIRECT => __('Sales - Direct'),
            self::REFUND_RECEIVED => __('Refund Received'),
            self::OTHER => __('Other Income'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::SALES_SHOPIFY => 'success',
            self::SALES_TRENDYOL => 'orange',
            self::SALES_DIRECT => 'primary',
            self::REFUND_RECEIVED => 'info',
            self::OTHER => 'gray',
        };
    }
}
