<?php

namespace App\Services\Integrations\InvoiceProviders\TrendyolEFatura\Enums;

enum PaymentMeans: string
{
    case CREDIT_CARD = 'CREDIT_CARD';
    case EFT = 'EFT';
    case ON_DELIVERY = 'ON_DELIVERY';
    case MEDIATOR = 'MEDIATOR';
    case OTHER = 'OTHER';
}
