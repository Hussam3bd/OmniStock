<?php

namespace App\Filament\Resources\Invoice\Invoices\Pages;

use App\Filament\Resources\Invoice\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Invoices are created automatically, no manual creation
        ];
    }
}
