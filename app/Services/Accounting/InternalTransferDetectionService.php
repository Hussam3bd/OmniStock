<?php

namespace App\Services\Accounting;

use App\Models\Accounting\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InternalTransferDetectionService
{
    /**
     * Patterns that indicate internal transfers
     */
    protected array $transferPatterns = [
        'K.KARTI ÖDEME',
        'K.KARTL ÖDEME',
        'KART ÖDEME',
        'HAVALE',
        'EFT',
        'TRANSFER',
        'VİRMAN',
    ];

    /**
     * Detect and mark internal transfers for an account
     *
     * @param  int  $accountId  The account ID to detect transfers for
     * @param  int  $lookbackDays  Number of days to look back for transfer pairs (default: 90)
     * @return array Statistics about detected transfers
     */
    public function detectTransfers(int $accountId, int $lookbackDays = 90): array
    {
        $singleTransfersMarked = 0;
        $transferPairsFound = 0;

        // Get all transactions for the account that aren't already marked
        $transactions = Transaction::where('account_id', $accountId)
            ->where('is_internal_transfer', false)
            ->whereNull('transactionable_type') // Only imported transactions
            ->orderBy('transaction_date')
            ->get();

        $processedIds = [];

        foreach ($transactions as $transaction) {
            // Skip if already processed
            if (in_array($transaction->id, $processedIds) || $transaction->is_internal_transfer) {
                continue;
            }

            // Check if description matches transfer patterns
            if (! $this->isTransferDescription($transaction->description)) {
                continue;
            }

            // Try to find matching transfer in other accounts
            $transferMatch = $this->findTransferMatch($transaction, $lookbackDays);

            if ($transferMatch) {
                // Found a matching transfer in another account
                $this->markAsTransferPair($transaction, $transferMatch);
                $processedIds[] = $transaction->id;
                $processedIds[] = $transferMatch->id;
                $transferPairsFound++;
            } else {
                // No match found, but still mark as internal transfer (single-sided)
                $this->markAsSingleTransfer($transaction);
                $processedIds[] = $transaction->id;
                $singleTransfersMarked++;
            }
        }

        return [
            'transfer_pairs_found' => $transferPairsFound,
            'single_transfers_marked' => $singleTransfersMarked,
            'total_transactions_marked' => ($transferPairsFound * 2) + $singleTransfersMarked,
        ];
    }

    /**
     * Check if description indicates an internal transfer
     */
    protected function isTransferDescription(string $description): bool
    {
        $upperDescription = strtoupper($description);

        foreach ($this->transferPatterns as $pattern) {
            if (str_contains($upperDescription, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find a matching transfer in other accounts
     */
    protected function findTransferMatch(Transaction $transaction, int $lookbackDays): ?Transaction
    {
        $transactionAmount = $transaction->amount->getAmount();
        $transactionDate = Carbon::parse($transaction->transaction_date);

        // Look for opposite transaction type (expense -> income, income -> expense)
        // in different accounts with same amount within date range
        $oppositeType = $transaction->type === 'expense' ? 'income' : 'expense';

        $potentialMatches = Transaction::where('account_id', '!=', $transaction->account_id)
            ->where('type', $oppositeType)
            ->where('is_internal_transfer', false)
            ->whereNull('transactionable_type')
            ->whereBetween('transaction_date', [
                $transactionDate->copy()->subDays($lookbackDays),
                $transactionDate->copy()->addDays($lookbackDays),
            ])
            ->get();

        foreach ($potentialMatches as $match) {
            $matchAmount = $match->amount->getAmount();
            $matchDate = Carbon::parse($match->transaction_date);

            // Check if amounts match
            if ($transactionAmount !== $matchAmount) {
                continue;
            }

            // Check if descriptions are similar
            if (! $this->descriptionsMatch($transaction->description, $match->description)) {
                continue;
            }

            // Check if dates are close (within 3 days for transfers)
            $daysDifference = abs($transactionDate->diffInDays($matchDate));
            if ($daysDifference > 3) {
                continue;
            }

            return $match;
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

        // Consider it a match if 50% or more similar
        return $percent >= 50;
    }

    /**
     * Mark two transactions as an internal transfer pair
     */
    protected function markAsTransferPair(Transaction $transaction1, Transaction $transaction2): void
    {
        DB::transaction(function () use ($transaction1, $transaction2) {
            // Mark both transactions as internal transfers and link them
            $transaction1->update([
                'is_internal_transfer' => true,
                'linked_transaction_id' => $transaction2->id,
            ]);

            $transaction2->update([
                'is_internal_transfer' => true,
                'linked_transaction_id' => $transaction1->id,
            ]);
        });
    }

    /**
     * Mark a single transaction as an internal transfer (no match found)
     */
    protected function markAsSingleTransfer(Transaction $transaction): void
    {
        $transaction->update([
            'is_internal_transfer' => true,
        ]);
    }

    /**
     * Detect transfers for recently imported transactions
     */
    public function detectTransfersForRecentImport(int $accountId, int $minutesSinceImport = 5): array
    {
        $lookbackDays = 90;
        $singleTransfersMarked = 0;
        $transferPairsFound = 0;
        $processedIds = [];

        // Get recently imported transactions
        $recentTransactions = Transaction::where('account_id', $accountId)
            ->whereNull('transactionable_type')
            ->whereHas('importedTransaction', function ($query) use ($minutesSinceImport) {
                $query->where('imported_at', '>=', now()->subMinutes($minutesSinceImport));
            })
            ->get();

        foreach ($recentTransactions as $transaction) {
            // Skip if already processed
            if (in_array($transaction->id, $processedIds) || $transaction->is_internal_transfer) {
                continue;
            }

            // Check if description matches transfer patterns
            if (! $this->isTransferDescription($transaction->description)) {
                continue;
            }

            // Try to find matching transfer
            $transferMatch = $this->findTransferMatch($transaction, $lookbackDays);

            if ($transferMatch) {
                $this->markAsTransferPair($transaction, $transferMatch);
                $processedIds[] = $transaction->id;
                $processedIds[] = $transferMatch->id;
                $transferPairsFound++;
            } else {
                $this->markAsSingleTransfer($transaction);
                $processedIds[] = $transaction->id;
                $singleTransfersMarked++;
            }
        }

        return [
            'transfer_pairs_found' => $transferPairsFound,
            'single_transfers_marked' => $singleTransfersMarked,
            'total_transactions_marked' => ($transferPairsFound * 2) + $singleTransfersMarked,
        ];
    }
}
