<?php

namespace App\Enums\Invoice;

use Filament\Support\Contracts\HasLabel;

enum InvoiceType: string implements HasLabel
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
