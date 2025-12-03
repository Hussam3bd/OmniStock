<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentTrace
{
    public function __construct(
        public string $status,
        public string $time,
        public string $location,
        public string $locationDetail,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'] ?? '',
            time: $data['time'] ?? '',
            location: $data['location'] ?? '',
            locationDetail: $data['locationDetail'] ?? '',
        );
    }
}
