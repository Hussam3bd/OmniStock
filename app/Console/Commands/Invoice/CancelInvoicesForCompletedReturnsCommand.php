<?php

namespace App\Console\Commands\Invoice;

use App\Actions\Invoice\CancelInvoiceAction;
use App\Enums\Invoice\InvoiceStatus;
use App\Enums\Order\ReturnStatus;
use App\Models\Invoice\Invoice;
use App\Models\Order\OrderReturn;
use Illuminate\Console\Command;

class CancelInvoicesForCompletedReturnsCommand extends Command
{
    protected $signature = 'invoice:cancel-for-completed-returns
                          {--dry-run : Show what would be cancelled without actually cancelling}';

    protected $description = 'Cancel issued invoices for orders that have completed returns';

    public function handle(CancelInvoiceAction $cancelInvoiceAction): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No invoices will be cancelled.');
            $this->newLine();
        }

        $completedReturns = OrderReturn::where('status', ReturnStatus::Completed)
            ->with('order')
            ->get();

        if ($completedReturns->isEmpty()) {
            $this->info('No completed returns found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$completedReturns->count()} completed return(s). Checking for cancellable invoices...");
        $this->newLine();

        $orderIds = $completedReturns->pluck('order_id')->unique();

        $invoices = Invoice::whereIn('order_id', $orderIds)
            ->where('status', InvoiceStatus::ISSUED)
            ->whereNull('cancelled_at')
            ->with('order')
            ->get();

        if ($invoices->isEmpty()) {
            $this->info('No issued invoices found for completed returns. Nothing to do.');

            return Command::SUCCESS;
        }

        $this->info("Found {$invoices->count()} invoice(s) to cancel.");
        $this->newLine();

        $this->table(
            ['Invoice #', 'Order #', 'Status', 'Issued At'],
            $invoices->map(fn (Invoice $invoice) => [
                $invoice->invoice_number,
                $invoice->order?->order_number ?? 'N/A',
                $invoice->status->getLabel(),
                $invoice->issued_at?->format('Y-m-d H:i'),
            ])->toArray()
        );

        if ($dryRun) {
            $this->newLine();
            $this->warn("DRY RUN: Would cancel {$invoices->count()} invoice(s). Run without --dry-run to proceed.");

            return Command::SUCCESS;
        }

        if (! $this->confirm("Cancel {$invoices->count()} invoice(s)?")) {
            $this->info('Cancelled by user.');

            return Command::SUCCESS;
        }

        $cancelled = 0;
        $failed = 0;

        foreach ($invoices as $invoice) {
            try {
                $cancelInvoiceAction->execute($invoice, [
                    'reason' => 'Order return completed (backfill)',
                ]);

                $cancelled++;
                $this->line("  Cancelled: {$invoice->invoice_number}");
            } catch (\Exception $e) {
                $failed++;
                $this->error("  Failed: {$invoice->invoice_number} - {$e->getMessage()}");
            }
        }

        $this->newLine();
        $this->info("Done. Cancelled: {$cancelled}, Failed: {$failed}");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
