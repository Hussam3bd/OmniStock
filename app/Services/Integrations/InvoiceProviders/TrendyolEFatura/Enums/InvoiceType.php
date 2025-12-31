<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums;

enum InvoiceType: string
{
    case EARSIVFATURA = 'EARSIVFATURA';
    case TEMELFATURA = 'TEMELFATURA';
    case TICARIFATURA = 'TICARIFATURA';
    case TEMELIRSALIYE = 'TEMELIRSALIYE';
    case HKS = 'HKS';
    case IHRACAT = 'IHRACAT';
    case KAMU = 'KAMU';
    case ENERJI = 'ENERJI';
    case ILAC_TIBBICIHAZ = 'ILAC_TIBBICIHAZ';
    case YOLCUBERABERFATURA = 'YOLCUBERABERFATURA';
}
