<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

use App\Services\Integrations\ShippingProviders\BasitKargo\Enums\ShipmentStatus;

class ShipmentResponse
{
    public function __construct(
        public string $id,
        public string $orderNumber,
        public string $barcode,
        public string $type,
        public ShipmentStatus $status,
        public bool $validationFailed,
        public ?string $validationFailedMessage,
        public string $createdTime,
        public string $updatedTime,
        public ShipmentContent $content,
        public ShipmentSender $sender,
        public string $foreignCode,
        public ShipmentRecipient $recipient,
        public ShipmentInfo $shipmentInfo,
        public ShipmentPriceInfo $priceInfo,
        public array $traces,
        public bool $printed,
        public array $rawData = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            orderNumber: $data['orderNumber'],
            barcode: $data['barcode'],
            type: $data['type'],
            status: ShipmentStatus::from($data['status']),
            validationFailed: $data['validationFailed'],
            validationFailedMessage: $data['validationFailedMessage'] ?? null,
            createdTime: $data['createdTime'],
            updatedTime: $data['updatedTime'],
            content: ShipmentContent::fromArray($data['content'] ?? []),
            sender: ShipmentSender::fromArray($data['sender'] ?? []),
            foreignCode: $data['foreignCode'],
            recipient: ShipmentRecipient::fromArray($data['recipient'] ?? []),
            shipmentInfo: ShipmentInfo::fromArray($data['shipmentInfo'] ?? []),
            priceInfo: ShipmentPriceInfo::fromArray($data['priceInfo'] ?? []),
            traces: array_map(
                fn (array $trace) => ShipmentTrace::fromArray($trace),
                $data['traces'] ?? []
            ),
            printed: $data['printed'],
            rawData: $data,
        );
    }

    public function getCarrierCode(): ?string
    {
        return $this->shipmentInfo->handler?->code;
    }

    public function getCarrierName(): ?string
    {
        return $this->shipmentInfo->handler?->name;
    }

    public function getTrackingNumber(): string
    {
        return $this->shipmentInfo->handlerShipmentCode;
    }

    public function getDesi(): float
    {
        return $this->content->totalDesiKg;
    }

    public function getTotalCost(): float
    {
        return $this->priceInfo->totalCost;
    }

    public function getShipmentFee(): float
    {
        return $this->priceInfo->shipmentFee;
    }

    public function getLastStatus(): string
    {
        return $this->shipmentInfo->lastState;
    }

    public function isDelivered(): bool
    {
        return $this->status->isDelivered();
    }

    public function isInTransit(): bool
    {
        return $this->status->isInTransit();
    }

    public function isProblematic(): bool
    {
        return $this->status->isProblematic();
    }

    public function isReturned(): bool
    {
        return $this->status->isReturned();
    }

    public function getDeliveredTime(): ?string
    {
        return $this->shipmentInfo->deliveredTime;
    }

    public function getShippedTime(): ?string
    {
        return $this->shipmentInfo->shippedTime;
    }
}
