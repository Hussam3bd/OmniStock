<?php

namespace App\Actions\Invoice;

use App\Enums\Integration\IntegrationType;
use App\Enums\Invoice\FileExtension;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Invoice\InvoiceType;
use App\Models\Integration\Integration;
use App\Models\Invoice\Invoice;
use App\Models\Order\Order;
use App\Services\Integrations\AdapterFactory;
use App\Services\Integrations\Contracts\InvoiceProviderAdapter;
use Illuminate\Support\Facades\Log;

class GenerateInvoiceAction
{
    /**
     * Generate an invoice for an order using the specified invoice provider integration
     *
     * @param  array  $options  Optional parameters:
     *                          - 'integration_id' => ID of the invoice provider integration to use
     *                          - 'invoice_type' => Type of invoice (default: 'e-archive')
     *                          - 'send_to_customer' => Whether to send invoice to customer (default: false)
     */
    public function execute(Order $order, array $options = []): Invoice
    {
        // Get invoice provider integration
        $integrationId = $options['integration_id'] ?? null;
        $integration = $integrationId
            ? Integration::findOrFail($integrationId)
            : Integration::where('type', IntegrationType::INVOICE_PROVIDER)
                ->where('is_active', true)
                ->firstOrFail();

        if ($integration->type !== IntegrationType::INVOICE_PROVIDER) {
            throw new \Exception('Integration must be an invoice provider');
        }

        // Check if order already has an invoice for this integration
        $existingInvoice = $order->invoices()
            ->where('integration_id', $integration->id)
            ->whereNot('status', InvoiceStatus::CANCELLED)
            ->first();

        if ($existingInvoice && $existingInvoice->status->isValid()) {
            throw new \Exception('Order already has a valid invoice from this provider');
        }

        try {
            // If there's a failed invoice, retry it instead of creating a new one
            if ($existingInvoice && $existingInvoice->status === InvoiceStatus::FAILED) {
                $invoice = $existingInvoice;
                $invoice->update([
                    'status' => InvoiceStatus::PENDING,
                    'error_message' => null,
                    'invoice_type' => isset($options['invoice_type']) ? InvoiceType::from($options['invoice_type']) : $invoice->invoice_type,
                ]);
            } else {
                // Create new invoice record
                $invoice = Invoice::create([
                    'order_id' => $order->id,
                    'integration_id' => $integration->id,
                    'invoice_type' => isset($options['invoice_type']) ? InvoiceType::from($options['invoice_type']) : InvoiceType::E_ARCHIVE,
                    'invoice_number' => $this->generateInvoiceNumber($order),
                    'status' => InvoiceStatus::PENDING,
                    'invoice_data' => $this->prepareInvoiceData($order),
                ]);
            }

            // Get the adapter for the integration
            $adapter = AdapterFactory::make($integration);

            // Generate invoice with provider
            $result = $adapter->generateInvoice($order);

            // Handle both array and string responses for backwards compatibility
            if (is_array($result)) {
                $externalId = $result['invoice_id'] ?? $result['invoice_number'] ?? '';
                $invoiceUuid = $result['invoice_uuid'] ?? null;
                $providerResponse = $result['response'] ?? $result;
            } else {
                $externalId = $result;
                $invoiceUuid = null;
                $providerResponse = ['external_id' => $result];
            }

            // Mark invoice as issued
            $invoice->markAsIssued($externalId, $invoiceUuid, array_merge([
                'generated_at' => now()->toIso8601String(),
            ], $providerResponse));

            // Fetch and store PDF and HTML URLs if adapter supports it
            if (method_exists($adapter, 'getPermanentUrl') && $invoiceUuid) {
                try {
                    // Fetch PDF URL
                    $pdfUrl = $adapter->getPermanentUrl(
                        $invoiceUuid,
                    );

                    // Fetch HTML URL
                    $htmlUrl = $adapter->getPermanentUrl(
                        $invoiceUuid,
                        fileExtension: FileExtension::HTML,
                    );

                    // Update invoice with URLs
                    $invoice->update([
                        'pdf_url' => $pdfUrl,
                        'html_url' => $htmlUrl,
                    ]);
                } catch (\Exception $e) {
                    // Log error but don't fail the invoice generation
                    Log::warning('Failed to fetch invoice URLs', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update order with invoice data
            $this->syncInvoiceToOrder($invoice);

            // Send to customer if requested
            if ($options['send_to_customer'] ?? false) {
                $this->sendToCustomer($invoice, $adapter);
            }

            // Log success
            activity()
                ->performedOn($invoice)
                ->withProperties([
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'integration_id' => $integration->id,
                    'external_id' => $externalId,
                ])
                ->log('invoice_generated');

            return $invoice->fresh();
        } catch (\Exception $e) {
            // Mark invoice as failed if it was created
            if (isset($invoice)) {
                $invoice->markAsFailed($e->getMessage());
            }

            // Log failure
            activity()
                ->performedOn($invoice ?? $order)
                ->withProperties([
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'integration_id' => $integration->id,
                    'error' => $e->getMessage(),
                ])
                ->log('invoice_generation_failed');

            throw $e;
        }
    }

    /**
     * Generate a unique invoice number for the order
     */
    protected function generateInvoiceNumber(Order $order): string
    {
        // Use order number as base for invoice number
        $prefix = 'INV-';
        $year = now()->format('Y');
        $month = now()->format('m');

        // Get the next sequential number for this month
        $lastInvoice = Invoice::whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('id', 'desc')
            ->first();

        $sequence = $lastInvoice ? ((int) substr($lastInvoice->invoice_number, -6)) + 1 : 1;

        return $prefix.$year.$month.'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Prepare invoice data from order
     */
    protected function prepareInvoiceData(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'customer_name' => $order->customer->first_name.' '.$order->customer->last_name,
            'total_amount' => $order->total_amount?->getAmount(),
            'currency' => $order->currency,
            'order_date' => $order->order_date?->toIso8601String(),
        ];
    }

    /**
     * Send invoice to customer
     */
    protected function sendToCustomer(Invoice $invoice, InvoiceProviderAdapter $adapter): void
    {
        $customer = $invoice->order->customer;

        $sent = $adapter->sendInvoice($invoice->external_id, $customer);

        if ($sent) {
            $methods = [];
            if ($customer->email) {
                $methods[] = 'email';
            }
            if ($customer->phone) {
                $methods[] = 'sms';
            }

            $invoice->markAsSent($methods);
        }
    }

    /**
     * Sync invoice data to order table for easy access
     */
    protected function syncInvoiceToOrder(Invoice $invoice): void
    {
        if (!$invoice->order) {
            return;
        }

        $invoice->order->update([
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => $invoice->issued_at,
            'invoice_url' => $invoice->pdf_url ?? $invoice->html_url,
        ]);
    }
}
