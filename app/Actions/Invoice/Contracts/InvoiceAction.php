<?php

namespace App\Actions\Invoice\Contracts;

use App\Models\Invoice\Invoice;

interface InvoiceAction
{
    /**
     * Execute the action on the given invoice
     */
    public function execute(Invoice $invoice, array $options = []): Invoice;

    /**
     * Validate if the action can be performed on the invoice
     */
    public function validate(Invoice $invoice): bool;
}
