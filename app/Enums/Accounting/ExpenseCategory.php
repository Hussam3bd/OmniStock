<?php

namespace App\Enums\Accounting;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExpenseCategory: string implements HasColor, HasLabel
{
    case PRODUCT_PURCHASE = 'product_purchase';
    case MARKETING_META = 'marketing_meta';
    case MARKETING_TIKTOK = 'marketing_tiktok';
    case MARKETING_GOOGLE = 'marketing_google';
    case MARKETING_OTHER = 'marketing_other';
    case PACKAGING_MATERIALS = 'packaging_materials';
    case PHOTOGRAPHY_CONTENT = 'photography_content';
    case SHIPPING_LOGISTICS = 'shipping_logistics';
    case SOFTWARE_TOOLS = 'software_tools';
    case PROFESSIONAL_SERVICES = 'professional_services';
    case OFFICE_SUPPLIES = 'office_supplies';
    case RENT_UTILITIES = 'rent_utilities';
    case SALARIES_WAGES = 'salaries_wages';
    case BANK_FEES = 'bank_fees';
    case OTHER = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::PRODUCT_PURCHASE => __('Product Purchase'),
            self::MARKETING_META => __('Marketing - Meta Ads'),
            self::MARKETING_TIKTOK => __('Marketing - TikTok Ads'),
            self::MARKETING_GOOGLE => __('Marketing - Google Ads'),
            self::MARKETING_OTHER => __('Marketing - Other'),
            self::PACKAGING_MATERIALS => __('Packaging & Materials'),
            self::PHOTOGRAPHY_CONTENT => __('Photography & Content Creation'),
            self::SHIPPING_LOGISTICS => __('Shipping & Logistics'),
            self::SOFTWARE_TOOLS => __('Software & Tools'),
            self::PROFESSIONAL_SERVICES => __('Professional Services'),
            self::OFFICE_SUPPLIES => __('Office Supplies'),
            self::RENT_UTILITIES => __('Rent & Utilities'),
            self::SALARIES_WAGES => __('Salaries & Wages'),
            self::BANK_FEES => __('Bank & Payment Fees'),
            self::OTHER => __('Other Expenses'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PRODUCT_PURCHASE => 'primary',
            self::MARKETING_META, self::MARKETING_TIKTOK, self::MARKETING_GOOGLE, self::MARKETING_OTHER => 'warning',
            self::PACKAGING_MATERIALS, self::PHOTOGRAPHY_CONTENT => 'info',
            self::SHIPPING_LOGISTICS => 'success',
            self::SOFTWARE_TOOLS, self::PROFESSIONAL_SERVICES => 'indigo',
            self::SALARIES_WAGES => 'purple',
            self::BANK_FEES => 'danger',
            default => 'gray',
        };
    }

    /**
     * Check if this is a marketing category
     */
    public function isMarketing(): bool
    {
        return in_array($this, [
            self::MARKETING_META,
            self::MARKETING_TIKTOK,
            self::MARKETING_GOOGLE,
            self::MARKETING_OTHER,
        ]);
    }
}
