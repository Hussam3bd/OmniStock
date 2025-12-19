<?php

namespace App\Services\Payment;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\PaymentGateway;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\PaymentGateways\IyzicoAdapter;

class PaymentCostSyncService
{
    /**
     * Sync payment gateway costs for an order
     */
    public function syncPaymentCosts(Order $order): bool
    {
        // Check if order has payment transaction ID
        if (! $order->payment_transaction_id) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'reason' => 'no_payment_transaction_id',
                ])
                ->log('payment_cost_sync_skipped');

            return false;
        }

        // Parse payment gateway
        $paymentGateway = PaymentGateway::parse($order->payment_gateway);

        if (! $paymentGateway) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'reason' => 'unknown_payment_gateway',
                    'payment_gateway' => $order->payment_gateway,
                ])
                ->log('payment_cost_sync_skipped');

            return false;
        }

        // Only sync for gateways that support automated sync
        if (! $paymentGateway->supportsAutomatedSync()) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'reason' => 'gateway_not_supported',
                    'payment_gateway' => $paymentGateway->value,
                ])
                ->log('payment_cost_sync_skipped');

            return false;
        }

        try {
            return match ($paymentGateway) {
                PaymentGateway::IYZICO => $this->syncIyzicoCosts($order),
                PaymentGateway::STRIPE => $this->syncStripeCosts($order),
                default => false,
            };
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'payment_gateway' => $paymentGateway->value,
                    'payment_transaction_id' => $order->payment_transaction_id,
                ])
                ->log('payment_cost_sync_failed');

            throw $e;
        }
    }

    /**
     * Sync Iyzico payment costs
     */
    protected function syncIyzicoCosts(Order $order): bool
    {
        // Get active Iyzico integration
        $integration = Integration::where('provider', IntegrationProvider::IYZICO->value)
            ->where('type', IntegrationType::PAYMENT_GATEWAY->value)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            throw new \Exception('No active Iyzico integration found');
        }

        // Create adapter and retrieve transaction
        $adapter = new IyzicoAdapter($integration);
        $transaction = $adapter->getTransaction($order->payment_transaction_id);

        // Convert amounts from TRY to minor units
        // Iyzico returns amounts in TRY (e.g., 2400.00, 0.25, 395.86)
        // We store in minor units (e.g., 240000, 25, 39586)
        $currency = $transaction['currency'] ?? 'TRY';
        $price = $this->convertToMinorUnits($transaction['price'], $currency);

        // Calculate fees
        $iyzicoCommissionFee = $this->convertToMinorUnits($transaction['iyzico_commission_fee'], $currency);
        $iyzicoCommissionRateAmount = $this->convertToMinorUnits($transaction['iyzico_commission_rate_amount'], $currency);
        $merchantCommissionRateAmount = $this->convertToMinorUnits($transaction['merchant_commission_rate_amount'], $currency);

        // Total Iyzico fees (fixed + rate-based)
        $totalIyzicoFee = $iyzicoCommissionFee + $iyzicoCommissionRateAmount;

        // Calculate merchant payout
        // For direct merchants: API returns 0, so we calculate manually
        // Merchant Payout = Price - (Total Iyzico Fee - Installment Fees Paid by Customer)
        // merchant_commission_rate_amount = installment fees paid by CUSTOMER
        $merchantPayoutAmount = $transaction['merchant_payout_amount'] > 0
            ? $this->convertToMinorUnits($transaction['merchant_payout_amount'], $currency)
            : $price - ($totalIyzicoFee - $merchantCommissionRateAmount);

        // Calculate ACTUAL merchant cost (what merchant pays to Iyzico)
        // This is: Total Iyzico Fees - Customer Paid Installment Fees
        $actualMerchantCost = $totalIyzicoFee - $merchantCommissionRateAmount;

        // Split merchant cost into fixed fee + commission
        $merchantCommissionAmount = $actualMerchantCost - $iyzicoCommissionFee;

        // Calculate commission rate as percentage of total order amount
        // Example: If commission is 110.34 TRY on 2572.02 TRY order, rate = 4.29%
        $merchantCommissionRate = $price > 0
            ? round(($merchantCommissionAmount / $price) * 100, 4)
            : 0;

        // Update order with payment costs
        $order->update([
            'payment_gateway_fee' => $iyzicoCommissionFee,
            'payment_gateway_commission_rate' => $merchantCommissionRate,
            'payment_gateway_commission_amount' => $merchantCommissionAmount,
            'payment_payout_amount' => $merchantPayoutAmount,
        ]);

        // Log success
        activity()
            ->performedOn($order)
            ->withProperties([
                'payment_gateway' => 'iyzico',
                'payment_id' => $transaction['payment_id'],
                'payment_status' => $transaction['payment_status'],
                'installment' => $transaction['installment'],
                'price' => $price / 100,
                'paid_price' => $transaction['paid_price'],
                'merchant_payout' => $merchantPayoutAmount / 100,
                'actual_merchant_cost' => $actualMerchantCost / 100,
                'total_iyzico_fee' => $transaction['total_iyzico_fee'],
                'customer_paid_installment_fee' => $merchantCommissionRateAmount / 100,
                'synced_fields' => [
                    'payment_gateway_fee' => $iyzicoCommissionFee / 100,
                    'payment_gateway_commission_rate' => $merchantCommissionRate,
                    'payment_gateway_commission_amount' => $merchantCommissionAmount / 100,
                    'payment_payout_amount' => $merchantPayoutAmount / 100,
                ],
            ])
            ->log('payment_cost_synced');

        return true;
    }

    /**
     * Sync Stripe payment costs
     */
    protected function syncStripeCosts(Order $order): bool
    {
        // Not implemented yet - placeholder for future Stripe integration
        throw new \Exception('Stripe payment cost sync is not implemented yet');
    }

    /**
     * Convert amount to minor units based on currency
     */
    protected function convertToMinorUnits(float $amount, string $currency): int
    {
        // Most currencies use 2 decimal places (e.g., USD, EUR, TRY)
        // Some currencies use 0 decimal places (e.g., JPY, KRW)
        // For now, assuming TRY which uses 2 decimal places
        return (int) round($amount * 100);
    }
}
