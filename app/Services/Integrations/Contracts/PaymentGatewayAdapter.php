<?php

namespace App\Services\Integrations\Contracts;

use Cknow\Money\Money;

interface PaymentGatewayAdapter
{
    public function authenticate(): bool;

    public function createPaymentIntent(Money $amount, array $options): array;

    public function capturePayment(string $paymentId): bool;

    public function refundPayment(string $paymentId, Money $amount): bool;

    public function getTransaction(string $transactionId): array;
}
