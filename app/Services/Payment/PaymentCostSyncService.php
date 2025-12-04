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

        // Convert fees from TRY to minor units
        // Iyzico returns fees in TRY (e.g., 0.25, 55.77)
        // We store in minor units (e.g., 25, 5577)
        $currency = $transaction['currency'] ?? 'TRY';
        $iyzicoCommissionFee = $this->convertToMinorUnits($transaction['iyzico_commission_fee'], $currency);
        $iyzicoCommissionRateAmount = $this->convertToMinorUnits($transaction['iyzico_commission_rate_amount'], $currency);
        $merchantPayoutAmount = $this->convertToMinorUnits($transaction['merchant_payout_amount'], $currency);

        // Update order with payment costs
        $order->update([
            'payment_gateway_fee' => $iyzicoCommissionFee,
            'payment_gateway_commission_rate' => $transaction['merchant_commission_rate'],
            'payment_gateway_commission_amount' => $iyzicoCommissionRateAmount,
            'payment_payout_amount' => $merchantPayoutAmount,
        ]);

        // Log success
        activity()
            ->performedOn($order)
            ->withProperties([
                'payment_gateway' => 'iyzico',
                'payment_id' => $transaction['payment_id'],
                'total_fee' => $transaction['total_iyzico_fee'],
                'payment_status' => $transaction['payment_status'],
                'synced_fields' => [
                    'payment_gateway_fee' => $iyzicoCommissionFee,
                    'payment_gateway_commission_amount' => $iyzicoCommissionRateAmount,
                    'payment_payout_amount' => $merchantPayoutAmount,
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
