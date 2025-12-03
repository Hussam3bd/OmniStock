<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentPriceInfo
{
    public function __construct(
        public string $paymentMethod,
        public float $shipmentFee,
        public ?float $extraFee,
        public float $totalCost,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            paymentMethod: $data['paymentMethod'] ?? '',
            shipmentFee: $data['shipmentFee'] ?? 0.0,
            extraFee: $data['extraFee'] ?? null,
            totalCost: $data['totalCost'] ?? 0.0,
        );
    }
}
