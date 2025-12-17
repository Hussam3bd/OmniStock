<?php

namespace App\Services\Accounting;

use App\Enums\Accounting\CapitalCategory;
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
            TransactionType::CAPITAL => $this->applyCapitalTransaction($transaction, $account, $amount),
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
            TransactionType::CAPITAL => $this->reverseCapitalTransaction($transaction, $account, $amount),
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
     * Apply capital transaction (contribution increases, withdrawal decreases)
     */
    protected function applyCapitalTransaction(Transaction $transaction, Account $account, Money $amount): void
    {
        $category = CapitalCategory::tryFrom($transaction->category);

        if ($category === CapitalCategory::OWNER_CONTRIBUTION) {
            $this->increaseBalance($account, $amount);
        } elseif (in_array($category, [CapitalCategory::OWNER_WITHDRAWAL, CapitalCategory::PROFIT_DISTRIBUTION])) {
            $this->decreaseBalance($account, $amount);
        }
    }

    /**
     * Reverse capital transaction
     */
    protected function reverseCapitalTransaction(Transaction $transaction, Account $account, Money $amount): void
    {
        $category = CapitalCategory::tryFrom($transaction->category);

        if ($category === CapitalCategory::OWNER_CONTRIBUTION) {
            $this->decreaseBalance($account, $amount);
        } elseif (in_array($category, [CapitalCategory::OWNER_WITHDRAWAL, CapitalCategory::PROFIT_DISTRIBUTION])) {
            $this->increaseBalance($account, $amount);
        }
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
            return new Money($transaction->amount->getAmount(), $accountCurrency);
        }

        // Apply exchange rate: transaction amount (in minor units) × exchange rate
        // Example: 3400 USD cents × 42.55 rate = 144,670 TRY cents
        $convertedAmountInMinorUnits = $transaction->amount->getAmount() * $transaction->exchange_rate;

        // Create Money object from minor units (already in cents/kuruş)
        return new Money((int) round($convertedAmountInMinorUnits), $accountCurrency);
    }
}
