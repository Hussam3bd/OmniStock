<?php

namespace App\Actions\Invoice;

use App\Models\Invoice\Invoice;
use App\Services\Integrations\AdapterFactory;

class CancelInvoiceAction extends BaseInvoiceAction
{
    /**
     * Execute the action to cancel an invoice
     *
     * @param  array  $options  Optional parameters:
     *                          - 'reason' => Reason for cancellation
     */
    public function execute(Invoice $invoice, array $options = []): Invoice
    {
        if (! $this->validate($invoice)) {
            throw new \Exception('Invoice cannot be cancelled in its current state');
        }

        $reason = $options['reason'] ?? 'Order cancelled or returned';

        try {
            // Get the adapter for the integration
            $adapter = AdapterFactory::make($invoice->integration);

            // Cancel invoice with provider
            $cancelled = $adapter->cancelInvoice($invoice->invoice_uuid);

            if (! $cancelled) {
                throw new \Exception('Failed to cancel invoice with provider');
            }

            // Mark invoice as cancelled
            $invoice->markAsCancelled($reason);

            // Log success
            $this->logAction($invoice, 'invoice_cancelled', [
                'previous_status' => $invoice->getOriginal('status'),
                'cancel_reason' => $reason,
            ]);

            return $invoice->fresh();
        } catch (\Exception $e) {
            $this->logFailure($invoice, 'invoice_cancellation', $e, [
                'cancel_reason' => $reason,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if invoice can be cancelled
     */
    public function validate(Invoice $invoice): bool
    {
        return $invoice->canBeCancelled();
    }
}
