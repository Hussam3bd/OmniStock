<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentItem
{
    public function __construct(
        public ?string $name,
        public string $code,
        public string $productId,
        public ?string $variantId,
        public int $quantity,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            code: $data['code'] ?? '',
            productId: $data['productId'] ?? '',
            variantId: $data['variantId'] ?? null,
            quantity: $data['quantity'] ?? 1,
        );
    }
}
