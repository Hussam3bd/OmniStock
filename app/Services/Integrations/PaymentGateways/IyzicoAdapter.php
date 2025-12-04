<?php

namespace App\Services\Integrations\PaymentGateways;

use App\Models\Integration\Integration;
use App\Services\Integrations\Contracts\PaymentGatewayAdapter;
use App\Services\Integrations\PaymentGateways\Iyzico\DataTransferObjects\PaymentResponse;
use Cknow\Money\Money;
use Iyzipay\Model\Payment;
use Iyzipay\Options;
use Iyzipay\Request\RetrievePaymentRequest;

class IyzicoAdapter implements PaymentGatewayAdapter
{
    protected Options $options;

    public function __construct(
        protected Integration $integration
    ) {
        $this->options = new Options;
        $this->options->setApiKey($this->integration->settings['api_key'] ?? '');
        $this->options->setSecretKey($this->integration->settings['secret_key'] ?? '');

        // Set base URL based on test mode
        $testMode = $this->integration->settings['test_mode'] ?? false;
        $baseUrl = $testMode
            ? 'https://sandbox-api.iyzipay.com'
            : 'https://api.iyzipay.com';

        $this->options->setBaseUrl($baseUrl);
    }

    public function authenticate(): bool
    {
        // Iyzico doesn't have a dedicated auth endpoint
        // Authentication is verified on each API call via HMAC
        // We can validate that credentials are set
        return ! empty($this->integration->settings['api_key'])
            && ! empty($this->integration->settings['secret_key']);
    }

    public function createPaymentIntent(Money $amount, array $options): array
    {
        // Not implemented - this adapter is primarily for retrieving existing payments
        throw new \Exception('Payment creation is not supported in this adapter');
    }

    public function capturePayment(string $paymentId): bool
    {
        // Not implemented - this adapter is primarily for retrieving existing payments
        throw new \Exception('Payment capture is not supported in this adapter');
    }

    public function refundPayment(string $paymentId, Money $amount): bool
    {
        // Not implemented - this adapter is primarily for retrieving existing payments
        throw new \Exception('Payment refund is not supported in this adapter');
    }

    /**
     * Get payment transaction details by payment ID
     */
    public function getTransaction(string $transactionId): array
    {
        try {
            $request = new RetrievePaymentRequest;
            // Use setPaymentConversationId for string tokens from payment gateways
            // This accepts alphanumeric tokens like "rxHd6bSNEzKDEWXsy196D7YsT"
            $request->setPaymentConversationId($transactionId);
            $request->setLocale(\Iyzipay\Model\Locale::TR);

            $payment = Payment::retrieve($request, $this->options);

            // Check if the API call was successful
            if ($payment->getStatus() !== 'success') {
                throw new \Exception(
                    'Failed to retrieve payment: '.$payment->getErrorMessage()
                );
            }

            // Convert Iyzico response to array
            $paymentData = json_decode($payment->getRawResult(), true);

            // Create DTO from response
            $paymentDto = PaymentResponse::fromArray($paymentData);

            // Return standardized array format
            return [
                'payment_id' => $paymentDto->paymentId,
                'conversation_id' => $paymentDto->conversationId,
                'price' => $paymentDto->price,
                'paid_price' => $paymentDto->paidPrice,
                'currency' => $paymentDto->currency,
                'payment_status' => $paymentDto->paymentStatus,
                'installment' => $paymentDto->installment,
                'card_type' => $paymentDto->cardType,
                'card_association' => $paymentDto->cardAssociation,
                'card_family' => $paymentDto->cardFamily,
                'bin_number' => $paymentDto->binNumber,
                'last_four_digits' => $paymentDto->lastFourDigits,
                // Fee information (in TRY, not minor units)
                'iyzico_commission_fee' => $paymentDto->iyziCommissionFee,
                'iyzico_commission_rate_amount' => $paymentDto->iyziCommissionRateAmount,
                'merchant_commission_rate' => $paymentDto->merchantCommissionRate,
                'merchant_commission_rate_amount' => $paymentDto->merchantCommissionRateAmount,
                'merchant_payout_amount' => $paymentDto->merchantPayoutAmount,
                // Calculated totals
                'total_iyzico_fee' => $paymentDto->getTotalIyzicoFee(),
                'total_merchant_fee' => $paymentDto->getTotalMerchantFee(),
                // Status helpers
                'is_successful' => $paymentDto->isSuccessful(),
                'is_pending' => $paymentDto->isPending(),
                'is_failed' => $paymentDto->isFailed(),
                // Raw data
                'item_transactions' => $paymentDto->itemTransactions,
                'raw_data' => $paymentDto->rawData,
            ];
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'transaction_id' => $transactionId,
                    'error' => $e->getMessage(),
                ])
                ->log('iyzico_get_transaction_failed');

            throw $e;
        }
    }

    /**
     * Get payment details using DTO (for internal use)
     */
    public function getPaymentDetails(string $paymentId): PaymentResponse
    {
        $request = new RetrievePaymentRequest;
        // Use setPaymentConversationId for string tokens from payment gateways
        $request->setPaymentConversationId($paymentId);
        $request->setLocale(\Iyzipay\Model\Locale::TR);

        $payment = Payment::retrieve($request, $this->options);

        if ($payment->getStatus() !== 'success') {
            throw new \Exception(
                'Failed to retrieve payment: '.$payment->getErrorMessage()
            );
        }

        $paymentData = json_decode($payment->getRawResult(), true);

        return PaymentResponse::fromArray($paymentData);
    }
}
