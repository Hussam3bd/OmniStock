<?php

namespace App\Observers;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\TransactionType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Currency;
use App\Models\Purchase\PurchaseOrder;

class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "saving" event.
     */
    public function saving(PurchaseOrder $purchaseOrder): void
    {
        // Get the currency code for this purchase order
        // Load the currency relationship if needed to get the code
        if ($purchaseOrder->currency_id && ! $purchaseOrder->currency_code) {
            $currency = Currency::find($purchaseOrder->currency_id);
            $currencyCode = $currency?->code ?? 'TRY';
        } else {
            $currencyCode = $purchaseOrder->currency_code ?? $purchaseOrder->currency?->code ?? 'TRY';
        }

        // Initialize money fields if null (new record) using PO's currency
        $subtotal = $purchaseOrder->subtotal ?? money(0, $currencyCode);
        $tax = $purchaseOrder->tax ?? money(0, $currencyCode);
        $shippingCost = $purchaseOrder->shipping_cost ?? money(0, $currencyCode);

        // If shipping_cost changed, recalculate total
        if ($purchaseOrder->isDirty('shipping_cost')) {
            $purchaseOrder->total = $subtotal
                ->add($tax)
                ->add($shippingCost);
        }
    }

    /**
     * Handle the PurchaseOrder "updated" event.
     */
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        // Auto-create expense transaction when purchase order is fully received
        if ($purchaseOrder->isDirty('status') &&
            $purchaseOrder->status === PurchaseOrderStatus::Received &&
            ! $this->hasExistingExpenseTransaction($purchaseOrder)) {
            $this->createExpenseTransaction($purchaseOrder);
        }
    }

    /**
     * Create expense transaction for received purchase order
     */
    protected function createExpenseTransaction(PurchaseOrder $purchaseOrder): void
    {
        // Use the purchase order's account if specified, otherwise get default bank/cash account
        $account = $purchaseOrder->account
            ?? Account::where('type', 'bank')->first()
            ?? Account::where('type', 'cash')->first();

        if (! $account) {
            activity()
                ->performedOn($purchaseOrder)
                ->withProperties(['reason' => 'no_account_found'])
                ->log('expense_transaction_creation_skipped');

            return;
        }

        // Calculate exchange rate from PO currency to account currency
        $exchangeRate = 1.0;
        if ($purchaseOrder->currency_id !== $account->currency_id) {
            // Use the PO's stored exchange rate
            $exchangeRate = $purchaseOrder->exchange_rate ?? 1.0;
        }

        // Get currency code from currency relationship
        $currency = $purchaseOrder->currency;
        $currencyCode = $currency ? $currency->code : 'TRY';

        // Create expense transaction with purchase order total
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'transactionable_type' => PurchaseOrder::class,
            'transactionable_id' => $purchaseOrder->id,
            'type' => TransactionType::EXPENSE,
            'category' => ExpenseCategory::PRODUCT_PURCHASE->value,
            'amount' => $purchaseOrder->total->getAmount(),
            'currency' => $currencyCode,
            'currency_id' => $purchaseOrder->currency_id,
            'exchange_rate' => $exchangeRate,
            'description' => __('Expense for purchase order #:number from :supplier', [
                'number' => $purchaseOrder->order_number,
                'supplier' => $purchaseOrder->supplier->name ?? 'N/A',
            ]),
            'transaction_date' => $purchaseOrder->received_date ?? now(),
        ]);

        activity()
            ->performedOn($purchaseOrder)
            ->withProperties([
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'amount' => $purchaseOrder->total->getAmount(),
            ])
            ->log('expense_transaction_created');
    }

    /**
     * Check if purchase order already has an expense transaction
     */
    protected function hasExistingExpenseTransaction(PurchaseOrder $purchaseOrder): bool
    {
        return Transaction::where('transactionable_type', PurchaseOrder::class)
            ->where('transactionable_id', $purchaseOrder->id)
            ->where('type', TransactionType::EXPENSE)
            ->exists();
    }
}
