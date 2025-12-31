<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums;

enum Source: string
{
    case PORTAL = 'PORTAL';
    case WEB = 'WEB';
    case MOBILE = 'MOBILE';
    case PARTNER = 'PARTNER';
}
