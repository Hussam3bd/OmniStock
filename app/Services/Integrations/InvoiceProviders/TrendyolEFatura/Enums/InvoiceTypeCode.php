<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums;

enum InvoiceTypeCode: string
{
    case SATIS = 'SATIS';
    case IADE = 'IADE';
    case TEVKIFAT = 'TEVKIFAT';
    case ISTISNA = 'ISTISNA';
    case OZELMATRAH = 'OZELMATRAH';
    case IHRACKAYITLI = 'IHRACKAYITLI';
    case TEVKIFATIADE = 'TEVKIFATIADE';
    case SEVK = 'SEVK';
    case MATBUDAN = 'MATBUDAN';
    case KONAKLAMAVERGISI = 'KONAKLAMAVERGISI';
    case SARJ = 'SARJ';
    case SARJANLIK = 'SARJANLIK';
    case TEKNOLOJIDESTEK = 'TEKNOLOJIDESTEK';
    case KOMISYONCU = 'KOMISYONCU';
    case HKSSATIS = 'HKSSATIS';
    case HKSKOMISYONCU = 'HKSKOMISYONCU';
}
