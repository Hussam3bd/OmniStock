<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentRecipient
{
    public function __construct(
        public string $name,
        public string $phone,
        public string $city,
        public string $town,
        public string $address,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            phone: $data['phone'] ?? '',
            city: $data['city'] ?? '',
            town: $data['town'] ?? '',
            address: $data['address'] ?? '',
        );
    }
}
