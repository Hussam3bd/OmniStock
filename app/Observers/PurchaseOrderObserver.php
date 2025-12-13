<?php

namespace App\Observers;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\TransactionType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Purchase\PurchaseOrder;

class PurchaseOrderObserver
{
    /**
     * Handle the PurchaseOrder "saving" event.
     */
    public function saving(PurchaseOrder $purchaseOrder): void
    {
        // Initialize money fields if null (new record)
        $subtotal = $purchaseOrder->subtotal ?? money(0);
        $tax = $purchaseOrder->tax ?? money(0);
        $shippingCost = $purchaseOrder->shipping_cost ?? money(0);

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
        // Get default bank account or cash account
        $account = Account::where('type', 'bank')->first()
            ?? Account::where('type', 'cash')->first();

        if (! $account) {
            activity()
                ->performedOn($purchaseOrder)
                ->withProperties(['reason' => 'no_account_found'])
                ->log('expense_transaction_creation_skipped');

            return;
        }

        // Create expense transaction with purchase order total
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'transactionable_type' => PurchaseOrder::class,
            'transactionable_id' => $purchaseOrder->id,
            'purchase_order_id' => $purchaseOrder->id, // Keep for backward compatibility
            'type' => TransactionType::EXPENSE,
            'category' => ExpenseCategory::PRODUCT_PURCHASE->value,
            'amount' => $purchaseOrder->total->getAmount(),
            'currency' => $purchaseOrder->currency->code,
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
        return Transaction::where('purchase_order_id', $purchaseOrder->id)
            ->where('type', TransactionType::EXPENSE)
            ->exists();
    }
}
