<?php

namespace App\Jobs;

use App\Actions\Invoice\GenerateInvoiceAction;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\PaymentStatus;
use App\Models\Order\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AutoGenerateInvoice implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Order $order
    ) {}

    /**
     * Execute the job.
     */
    public function handle(GenerateInvoiceAction $generateInvoiceAction): void
    {
        // Check if order meets the criteria for auto-invoice generation
        if (! $this->shouldGenerateInvoice()) {
            return;
        }

        // Get the invoice provider integration ID from order's integration settings
        $invoiceProviderIntegrationId = $this->getInvoiceProviderIntegrationId();

        if (! $invoiceProviderIntegrationId) {
            Log::info('Order integration does not have auto-invoice generation enabled', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'integration_id' => $this->order->integration_id,
            ]);

            return;
        }

        // Check if invoice already exists
        if ($this->hasValidInvoice()) {
            Log::info('Order already has a valid invoice', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
            ]);

            return;
        }

        try {
            Log::info('Auto-generating invoice for order', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'invoice_provider_integration_id' => $invoiceProviderIntegrationId,
            ]);

            // Generate invoice
            $invoice = $generateInvoiceAction->execute($this->order, [
                'integration_id' => $invoiceProviderIntegrationId,
            ]);

            activity()
                ->performedOn($invoice)
                ->withProperties([
                    'order_id' => $this->order->id,
                    'order_number' => $this->order->order_number,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                ])
                ->log('invoice_auto_generated');
        } catch (\Exception $e) {
            Log::error('Failed to auto-generate invoice', [
                'order_id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'error' => $e->getMessage(),
            ]);

            activity()
                ->performedOn($this->order)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('invoice_auto_generation_failed');

            // Don't fail the job - just log the error
        }
    }

    /**
     * Check if order meets the criteria for auto-invoice generation
     */
    protected function shouldGenerateInvoice(): bool
    {
        // Must be paid
        if ($this->order->payment_status !== PaymentStatus::PAID) {
            return false;
        }

        // Must be delivered
        if ($this->order->fulfillment_status !== FulfillmentStatus::DELIVERED) {
            return false;
        }

        return true;
    }

    /**
     * Get the invoice provider integration ID from order's integration settings
     */
    protected function getInvoiceProviderIntegrationId(): ?int
    {
        if (! $this->order->integration) {
            return null;
        }

        // Check if integration has auto_invoice_generation enabled
        $autoInvoiceEnabled = $this->order->integration->settings['auto_invoice_generation'] ?? false;

        if (! $autoInvoiceEnabled) {
            return null;
        }

        // Get the invoice provider integration ID
        return $this->order->integration->settings['invoice_provider_integration_id'] ?? null;
    }

    /**
     * Check if order already has a valid invoice
     */
    protected function hasValidInvoice(): bool
    {
        return $this->order->invoices()
            ->whereNot('status', InvoiceStatus::CANCELLED)
            ->whereNot('status', InvoiceStatus::FAILED)
            ->exists();
    }
}
