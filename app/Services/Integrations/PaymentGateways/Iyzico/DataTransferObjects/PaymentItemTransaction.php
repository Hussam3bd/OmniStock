<?php

namespace App\Services\Integrations\PaymentGateways\Iyzico\DataTransferObjects;

class PaymentItemTransaction
{
    public function __construct(
        public string $itemId,
        public ?string $paymentTransactionId,
        public float $price,
        public float $paidPrice,
        public float $merchantCommissionRate,
        public float $merchantCommissionRateAmount,
        public float $iyziCommissionRateAmount,
        public float $iyziCommissionFee,
        public float $merchantPayoutAmount,
        public ?string $transactionStatus,
        public array $rawData = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            itemId: $data['itemId'] ?? '',
            paymentTransactionId: $data['paymentTransactionId'] ?? null,
            price: (float) ($data['price'] ?? 0),
            paidPrice: (float) ($data['paidPrice'] ?? 0),
            merchantCommissionRate: (float) ($data['merchantCommissionRate'] ?? 0),
            merchantCommissionRateAmount: (float) ($data['merchantCommissionRateAmount'] ?? 0),
            iyziCommissionRateAmount: (float) ($data['iyziCommissionRateAmount'] ?? 0),
            iyziCommissionFee: (float) ($data['iyziCommissionFee'] ?? 0),
            merchantPayoutAmount: (float) ($data['merchantPayoutAmount'] ?? 0),
            transactionStatus: $data['transactionStatus'] ?? null,
            rawData: $data,
        );
    }

    /**
     * Get total fees for this item (Iyzico commission + fee)
     */
    public function getTotalFees(): float
    {
        return $this->iyziCommissionRateAmount + $this->iyziCommissionFee;
    }
}
