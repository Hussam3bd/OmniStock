<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentContent
{
    public function __construct(
        public ?string $name,
        public string $code,
        public array $items,
        public array $packages,
        public float $totalDesiKg,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            code: $data['code'] ?? '',
            items: array_map(
                fn (array $item) => ShipmentItem::fromArray($item),
                $data['items'] ?? []
            ),
            packages: array_map(
                fn (array $package) => ShipmentPackage::fromArray($package),
                $data['packages'] ?? []
            ),
            totalDesiKg: $data['totalDesiKg'] ?? 0.0,
        );
    }
}
