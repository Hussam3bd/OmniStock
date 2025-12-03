<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentPackage
{
    public function __construct(
        public float $height,
        public float $width,
        public float $depth,
        public float $weight,
        public float $desi,
        public float $kgDesi,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            height: $data['height'] ?? 0.0,
            width: $data['width'] ?? 0.0,
            depth: $data['depth'] ?? 0.0,
            weight: $data['weight'] ?? 0.0,
            desi: $data['desi'] ?? 0.0,
            kgDesi: $data['kgDesi'] ?? 0.0,
        );
    }
}
