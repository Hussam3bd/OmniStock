<?php

namespace App\Services\Accounting;

use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use Cknow\Money\Money;

class AccountBalanceService
{
    /**
     * Update account balance after transaction creation
     */
    public function applyTransaction(Transaction $transaction): void
    {
        $account = $transaction->account;

        if (! $account) {
            return;
        }

        // Get amount in account's currency
        $amount = $this->convertAmount(
            $transaction->amount,
            $transaction->currency,
            $account->currency->code
        );

        // Apply transaction based on type
        match ($transaction->type) {
            TransactionType::INCOME => $this->increaseBalance($account, $amount),
            TransactionType::EXPENSE => $this->decreaseBalance($account, $amount),
            TransactionType::TRANSFER => null, // Transfers are handled separately
        };

        activity()
            ->performedOn($account)
            ->withProperties([
                'transaction_id' => $transaction->id,
                'type' => $transaction->type->value,
                'amount' => $amount->getAmount(),
                'new_balance' => $account->balance->getAmount(),
            ])
            ->log('account_balance_updated');
    }

    /**
     * Reverse account balance after transaction deletion
     */
    public function reverseTransaction(Transaction $transaction): void
    {
        $account = $transaction->account;

        if (! $account) {
            return;
        }

        // Get amount in account's currency
        $amount = $this->convertAmount(
            $transaction->amount,
            $transaction->currency,
            $account->currency->code
        );

        // Reverse transaction based on type
        match ($transaction->type) {
            TransactionType::INCOME => $this->decreaseBalance($account, $amount),
            TransactionType::EXPENSE => $this->increaseBalance($account, $amount),
            TransactionType::TRANSFER => null,
        };

        activity()
            ->performedOn($account)
            ->withProperties([
                'transaction_id' => $transaction->id,
                'type' => 'reversal',
                'original_type' => $transaction->type->value,
                'amount' => $amount->getAmount(),
                'new_balance' => $account->balance->getAmount(),
            ])
            ->log('account_balance_reversed');
    }

    /**
     * Increase account balance
     */
    protected function increaseBalance(Account $account, Money $amount): void
    {
        $newBalance = $account->balance->add($amount);
        $account->update(['balance' => $newBalance->getAmount()]);
    }

    /**
     * Decrease account balance
     */
    protected function decreaseBalance(Account $account, Money $amount): void
    {
        $newBalance = $account->balance->subtract($amount);
        $account->update(['balance' => $newBalance->getAmount()]);
    }

    /**
     * Convert amount from one currency to another
     * For now, uses simple conversion. Can be enhanced with real-time rates.
     */
    protected function convertAmount(Money $amount, string $fromCurrency, string $toCurrency): Money
    {
        // If same currency, no conversion needed
        if ($fromCurrency === $toCurrency) {
            return $amount;
        }

        // TODO: Implement proper currency conversion using exchange rates table
        // For now, return the amount as-is (assumes same currency)
        return Money::parse($amount->getAmount(), $toCurrency, true);
    }
}
