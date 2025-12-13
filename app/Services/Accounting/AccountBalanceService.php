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

        // Get amount in account's currency using the stored exchange rate
        $amount = $this->convertAmount($transaction, $account);

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

        // Get amount in account's currency using the stored exchange rate
        $amount = $this->convertAmount($transaction, $account);

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
     * Convert transaction amount to account currency using stored exchange rate
     * The exchange_rate is captured at transaction creation time for historical accuracy
     */
    protected function convertAmount(Transaction $transaction, Account $account): Money
    {
        $transactionCurrency = $transaction->currency;
        $accountCurrency = $account->currency->code;

        // If same currency, no conversion needed
        if ($transactionCurrency === $accountCurrency) {
            return $transaction->amount;
        }

        // Use the stored exchange rate from transaction
        if (! $transaction->exchange_rate) {
            // Fallback: if no exchange rate stored, use amount as-is
            // This maintains backward compatibility with old transactions
            return Money::parse($transaction->amount->getAmount(), $accountCurrency, true);
        }

        // Apply exchange rate: transaction amount × exchange rate
        $convertedAmount = $transaction->amount->getAmount() * $transaction->exchange_rate;

        return Money::parse((int) round($convertedAmount), $accountCurrency, true);
    }
}
