<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ShipmentInfo
{
    public function __construct(
        public ShipmentHandler $handler,
        public string $handlerShipmentCode,
        public string $handlerShipmentTrackingLink,
        public ?float $handlerDesi,
        public string $lastState,
        public ?string $shippedTime,
        public ?string $deliveredTime,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            handler: ShipmentHandler::fromArray($data['handler'] ?? []),
            handlerShipmentCode: $data['handlerShipmentCode'] ?? '',
            handlerShipmentTrackingLink: $data['handlerShipmentTrackingLink'] ?? '',
            handlerDesi: $data['handlerDesi'] ?? null,
            lastState: $data['lastState'] ?? '',
            shippedTime: $data['shippedTime'] ?? null,
            deliveredTime: $data['deliveredTime'] ?? null,
        );
    }
}
