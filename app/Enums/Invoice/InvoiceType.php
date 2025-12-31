<?php

namespace App\Enums\Invoice;

enum InvoiceType: string
{
    case E_ARCHIVE = 'e-archive';
    case E_INVOICE = 'e-invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::E_ARCHIVE => 'E-Archive Invoice',
            self::E_INVOICE => 'E-Invoice',
        };
    }
}
