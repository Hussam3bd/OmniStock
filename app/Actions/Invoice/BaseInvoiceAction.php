<?php

namespace App\Actions\Invoice;

use App\Actions\Invoice\Contracts\InvoiceAction;
use App\Models\Invoice\Invoice;

abstract class BaseInvoiceAction implements InvoiceAction
{
    /**
     * Execute the action on the given invoice
     */
    abstract public function execute(Invoice $invoice, array $options = []): Invoice;

    /**
     * Validate if the action can be performed on the invoice
     */
    abstract public function validate(Invoice $invoice): bool;

    /**
     * Log the action being performed
     */
    protected function logAction(Invoice $invoice, string $action, array $properties = []): void
    {
        activity()
            ->performedOn($invoice)
            ->withProperties(array_merge([
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'order_id' => $invoice->order_id,
                'order_number' => $invoice->order?->order_number,
                'integration_id' => $invoice->integration_id,
            ], $properties))
            ->log($action);
    }

    /**
     * Log an action failure
     */
    protected function logFailure(Invoice $invoice, string $action, \Exception $exception, array $properties = []): void
    {
        activity()
            ->performedOn($invoice)
            ->withProperties(array_merge([
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'order_id' => $invoice->order_id,
                'order_number' => $invoice->order?->order_number,
                'integration_id' => $invoice->integration_id,
                'error' => $exception->getMessage(),
            ], $properties))
            ->log($action.'_failed');
    }
}
