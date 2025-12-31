<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums;

enum UnitCode: string
{
    case ADET = 'C62';
    case GUN = 'DAY';
    case AY = 'MON';
    case SAAT = 'HUR';
    case KILOGRAM = 'KGM';
    case LITRE = 'LTR';
    case METRE = 'MTR';
    case METREKARE = 'MTK';
    case METREKUP = 'MTQ';
    case PAKET = 'PK';
    case SET = 'SET';
}
