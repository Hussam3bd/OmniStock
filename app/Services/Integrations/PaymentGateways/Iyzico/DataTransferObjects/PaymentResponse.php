<?php

namespace App\Services\Integrations\PaymentGateways\Iyzico\DataTransferObjects;

class PaymentResponse
{
    public function __construct(
        public string $paymentId,
        public ?string $conversationId,
        public float $price,
        public float $paidPrice,
        public float $merchantCommissionRate,
        public float $merchantCommissionRateAmount,
        public float $iyziCommissionRateAmount,
        public float $iyziCommissionFee,
        public float $merchantPayoutAmount,
        public string $currency,
        public string $paymentStatus,
        public int $installment,
        public ?string $cardType,
        public ?string $cardAssociation,
        public ?string $cardFamily,
        public ?string $binNumber,
        public ?string $lastFourDigits,
        public array $itemTransactions,
        public array $rawData = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            paymentId: $data['paymentId'] ?? '',
            conversationId: $data['conversationId'] ?? null,
            price: (float) ($data['price'] ?? 0),
            paidPrice: (float) ($data['paidPrice'] ?? 0),
            merchantCommissionRate: (float) ($data['merchantCommissionRate'] ?? 0),
            merchantCommissionRateAmount: (float) ($data['merchantCommissionRateAmount'] ?? 0),
            iyziCommissionRateAmount: (float) ($data['iyziCommissionRateAmount'] ?? 0),
            iyziCommissionFee: (float) ($data['iyziCommissionFee'] ?? 0),
            merchantPayoutAmount: (float) ($data['merchantPayoutAmount'] ?? 0),
            currency: $data['currency'] ?? 'TRY',
            paymentStatus: $data['paymentStatus'] ?? 'UNKNOWN',
            installment: (int) ($data['installment'] ?? 1),
            cardType: $data['cardType'] ?? null,
            cardAssociation: $data['cardAssociation'] ?? null,
            cardFamily: $data['cardFamily'] ?? null,
            binNumber: $data['binNumber'] ?? null,
            lastFourDigits: $data['lastFourDigits'] ?? null,
            itemTransactions: array_map(
                fn (array $item) => PaymentItemTransaction::fromArray($item),
                $data['itemTransactions'] ?? []
            ),
            rawData: $data,
        );
    }

    /**
     * Get total Iyzico fees (commission + fixed fee)
     */
    public function getTotalIyzicoFee(): float
    {
        return $this->iyziCommissionRateAmount + $this->iyziCommissionFee;
    }

    /**
     * Get total merchant fees (commission only, rate-based)
     */
    public function getTotalMerchantFee(): float
    {
        return $this->merchantCommissionRateAmount;
    }

    /**
     * Check if payment is successful
     */
    public function isSuccessful(): bool
    {
        return in_array(strtoupper($this->paymentStatus), ['SUCCESS', 'APPROVED']);
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        return in_array(strtoupper($this->paymentStatus), ['PENDING', 'WAITING']);
    }

    /**
     * Check if payment failed
     */
    public function isFailed(): bool
    {
        return in_array(strtoupper($this->paymentStatus), ['FAILURE', 'DECLINED', 'FAILED']);
    }
}
