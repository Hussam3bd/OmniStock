<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentHandler
{
    public function __construct(
        public string $name,
        public string $code,
        public string $logo,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            code: $data['code'] ?? '',
            logo: $data['logo'] ?? '',
        );
    }
}
