<?php

namespace App\Services\Accounting;

use App\Enums\Accounting\RefundType;
use App\Models\Accounting\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RefundDetectionService
{
    /**
     * Detect and mark refund pairs for an account
     *
     * @param  int  $accountId  The account ID to detect refunds for
     * @param  int  $lookbackDays  Number of days to look back for refund pairs (default: 90)
     * @return array Statistics about detected refunds
     */
    public function detectRefunds(int $accountId, int $lookbackDays = 90): array
    {
        $refundPairsFound = 0;
        $processedIds = [];

        // Get all transactions for the account that aren't already marked as refunds
        $transactions = Transaction::where('account_id', $accountId)
            ->where('is_refund', false)
            ->whereNull('transactionable_type') // Only imported transactions, not order/purchase order transactions
            ->orderBy('transaction_date')
            ->get();

        foreach ($transactions as $transaction) {
            // Skip if already processed in this run
            if (in_array($transaction->id, $processedIds)) {
                continue;
            }

            // Find potential refund match
            $refundMatch = $this->findRefundMatch($transaction, $transactions, $lookbackDays);

            if ($refundMatch) {
                $this->markAsRefundPair($transaction, $refundMatch);
                $processedIds[] = $transaction->id;
                $processedIds[] = $refundMatch->id;
                $refundPairsFound++;
            }
        }

        return [
            'refund_pairs_found' => $refundPairsFound,
            'transactions_marked' => $refundPairsFound * 2,
        ];
    }

    /**
     * Find a refund match for a transaction
     */
    protected function findRefundMatch(Transaction $transaction, $transactions, int $lookbackDays): ?Transaction
    {
        $transactionAmount = $transaction->amount->getAmount();
        $transactionDate = Carbon::parse($transaction->transaction_date);

        foreach ($transactions as $potentialMatch) {
            // Skip self
            if ($potentialMatch->id === $transaction->id) {
                continue;
            }

            // Skip if already marked as refund
            if ($potentialMatch->is_refund) {
                continue;
            }

            $matchAmount = $potentialMatch->amount->getAmount();
            $matchDate = Carbon::parse($potentialMatch->transaction_date);

            // For refunds, types must be opposite (expense refunded as income, or income refunded as expense)
            if ($transaction->type === $potentialMatch->type) {
                continue;
            }

            // Amounts must be equal (both are stored as positive in DB)
            if ($transactionAmount !== $matchAmount) {
                continue;
            }

            // Check if descriptions are similar (at least 50% match)
            if (! $this->descriptionsMatch($transaction->description, $potentialMatch->description)) {
                continue;
            }

            // Check if dates are within lookback period
            $daysDifference = abs($transactionDate->diffInDays($matchDate));
            if ($daysDifference > $lookbackDays) {
                continue;
            }

            // Found a match!
            return $potentialMatch;
        }

        return null;
    }

    /**
     * Check if two descriptions match (similarity check)
     */
    protected function descriptionsMatch(string $desc1, string $desc2): bool
    {
        // Normalize descriptions
        $desc1 = strtoupper(trim($desc1));
        $desc2 = strtoupper(trim($desc2));

        // Exact match
        if ($desc1 === $desc2) {
            return true;
        }

        // Calculate similarity percentage
        similar_text($desc1, $desc2, $percent);

        // Consider it a match if 70% or more similar
        return $percent >= 70;
    }

    /**
     * Mark two transactions as a refund pair
     */
    protected function markAsRefundPair(Transaction $transaction1, Transaction $transaction2): void
    {
        DB::transaction(function () use ($transaction1, $transaction2) {
            // Determine which is original and which is refund based on date
            // The earlier transaction is the original
            $date1 = Carbon::parse($transaction1->transaction_date);
            $date2 = Carbon::parse($transaction2->transaction_date);

            if ($date1->isBefore($date2)) {
                $original = $transaction1;
                $refund = $transaction2;
            } else {
                $original = $transaction2;
                $refund = $transaction1;
            }

            // Mark original transaction
            $original->update([
                'is_refund' => true,
                'refund_type' => RefundType::ORIGINAL,
                'linked_transaction_id' => $refund->id,
            ]);

            // Mark refund transaction
            $refund->update([
                'is_refund' => true,
                'refund_type' => RefundType::REFUND,
                'linked_transaction_id' => $original->id,
            ]);
        });
    }

    /**
     * Detect refunds for recently imported transactions
     * This can be called after an import completes
     */
    public function detectRefundsForRecentImport(int $accountId, int $minutesSinceImport = 5): array
    {
        $lookbackDays = 90;
        $refundPairsFound = 0;
        $processedIds = [];

        // Get recently imported transactions
        $recentTransactions = Transaction::where('account_id', $accountId)
            ->whereNull('transactionable_type')
            ->whereHas('importedTransaction', function ($query) use ($minutesSinceImport) {
                $query->where('imported_at', '>=', now()->subMinutes($minutesSinceImport));
            })
            ->get();

        // Get all transactions for potential matching (including older ones)
        $allTransactions = Transaction::where('account_id', $accountId)
            ->where('is_refund', false)
            ->whereNull('transactionable_type')
            ->get();

        foreach ($recentTransactions as $transaction) {
            // Skip if already processed in this run
            if (in_array($transaction->id, $processedIds) || $transaction->is_refund) {
                continue;
            }

            $refundMatch = $this->findRefundMatch($transaction, $allTransactions, $lookbackDays);

            if ($refundMatch) {
                $this->markAsRefundPair($transaction, $refundMatch);
                $processedIds[] = $transaction->id;
                $processedIds[] = $refundMatch->id;
                $refundPairsFound++;
            }
        }

        return [
            'refund_pairs_found' => $refundPairsFound,
            'transactions_marked' => $refundPairsFound * 2,
        ];
    }
}
