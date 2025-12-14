<?php

namespace App\Observers;

use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Events\Order\OrderCancelled;
use App\Jobs\SyncOrderFulfillmentData;
use App\Jobs\SyncOrderPaymentFees;
use App\Models\Accounting\Account;
use App\Models\Accounting\Transaction;
use App\Models\Order\Order;

class OrderObserver
{
    /**
     * Handle the Order "updated" event.
     */
    public function updated(Order $order): void
    {
        // 1. Handle inventory restoration for cancelled/rejected orders
        if ($order->isDirty('order_status') &&
            ($order->order_status === OrderStatus::CANCELLED ||
             $order->order_status === OrderStatus::REJECTED)) {
            OrderCancelled::dispatch($order);
        }

        // 2. Auto-sync payment fees when order is paid
        if ($order->isDirty('payment_status') &&
            $order->payment_status === PaymentStatus::PAID &&
            $order->payment_transaction_id) {
            SyncOrderPaymentFees::dispatch($order);
        }

        // 3. Auto-create income transaction when order is paid
        if ($order->isDirty('payment_status') &&
            $order->payment_status === PaymentStatus::PAID &&
            $order->payment_payout_amount &&
            ! $this->hasExistingIncomeTransaction($order)) {
            $this->createIncomeTransaction($order);
        }

        // 4. Auto-sync shipment data when Shopify order is fulfilled
        if ($order->isDirty('fulfillment_status') &&
            $order->fulfillment_status === FulfillmentStatus::FULFILLED &&
            $order->channel === OrderChannel::SHOPIFY &&
            $order->isExternal()) {
            SyncOrderFulfillmentData::dispatch($order);
        }
    }

    /**
     * Create income transaction for paid order
     */
    protected function createIncomeTransaction(Order $order): void
    {
        // Get default payment gateway account or first bank account
        $account = Account::where('type', 'payment_gateway')->first()
            ?? Account::where('type', 'bank')->first();

        if (! $account) {
            activity()
                ->performedOn($order)
                ->withProperties(['reason' => 'no_account_found'])
                ->log('income_transaction_creation_skipped');

            return;
        }

        // Determine income category based on channel
        $category = match ($order->channel) {
            OrderChannel::SHOPIFY => IncomeCategory::SALES_SHOPIFY,
            OrderChannel::TRENDYOL => IncomeCategory::SALES_TRENDYOL,
            default => IncomeCategory::OTHER,
        };

        // Calculate exchange rate from order currency to account currency
        $exchangeRate = 1.0;
        if ($order->currency_id !== $account->currency_id) {
            // Use the order's stored exchange rate to convert to default currency first
            // then get rate from default to account currency
            $exchangeRate = $order->exchange_rate ?? 1.0;
        }

        // Create transaction with payment payout amount (after gateway fees)
        $transaction = Transaction::create([
            'account_id' => $account->id,
            'transactionable_type' => Order::class,
            'transactionable_id' => $order->id,
            'type' => TransactionType::INCOME,
            'category' => $category->value,
            'amount' => $order->payment_payout_amount->getAmount(),
            'currency' => $order->currency, // Currency is a string field
            'currency_id' => $order->currency_id,
            'exchange_rate' => $exchangeRate,
            'description' => __('Income from order :number (:channel)', [
                'number' => $order->order_number,
                'channel' => $order->channel->getLabel(),
            ]),
            'transaction_date' => now(),
        ]);

        activity()
            ->performedOn($order)
            ->withProperties([
                'transaction_id' => $transaction->id,
                'account_id' => $account->id,
                'amount' => $order->payment_payout_amount->getAmount(),
            ])
            ->log('income_transaction_created');
    }

    /**
     * Check if order already has an income transaction
     */
    protected function hasExistingIncomeTransaction(Order $order): bool
    {
        return Transaction::where('transactionable_type', Order::class)
            ->where('transactionable_id', $order->id)
            ->where('type', TransactionType::INCOME)
            ->exists();
    }
}
