<?php

namespace App\Observers;

use App\Models\Accounting\Transaction;
use App\Services\Accounting\AccountBalanceService;

class TransactionObserver
{
    public function __construct(
        protected AccountBalanceService $balanceService
    ) {}

    /**
     * Handle the Transaction "created" event.
     * Automatically update account balance when transaction is created
     */
    public function created(Transaction $transaction): void
    {
        $this->balanceService->applyTransaction($transaction);
    }

    /**
     * Handle the Transaction "deleted" event.
     * Automatically reverse account balance when transaction is deleted
     */
    public function deleted(Transaction $transaction): void
    {
        $this->balanceService->reverseTransaction($transaction);
    }

    /**
     * Handle the Transaction "restored" event.
     * Re-apply balance when transaction is restored from soft delete
     */
    public function restored(Transaction $transaction): void
    {
        $this->balanceService->applyTransaction($transaction);
    }

    /**
     * Handle the Transaction "force deleted" event.
     * Reverse balance when transaction is permanently deleted
     */
    public function forceDeleted(Transaction $transaction): void
    {
        $this->balanceService->reverseTransaction($transaction);
    }
}
