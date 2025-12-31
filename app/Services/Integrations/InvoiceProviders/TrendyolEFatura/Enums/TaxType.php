<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums;

enum TaxType: string
{
    case KDV = 'KDV';
    case OTV_1 = 'OTV_1';
    case OTV_2 = 'OTV_2';
    case OTV_3 = 'OTV_3';
    case OTV_3A = 'OTV_3A';
    case OTV_3B = 'OTV_3B';
    case OTV_3C = 'OTV_3C';
    case OTV_4 = 'OTV_4';
    case KONAKLAMA = 'KONAKLAMA';
    case OIV = 'OIV';
    case STAMP_TAX = 'STAMP_TAX';
    case TRT_SHARE = 'TRT_SHARE';
    case PASTURE_FUND = 'PASTURE_FUND';
    case STOCK_REGISTRATION_FEE = 'STOCK_REGISTRATION_FEE';
}
