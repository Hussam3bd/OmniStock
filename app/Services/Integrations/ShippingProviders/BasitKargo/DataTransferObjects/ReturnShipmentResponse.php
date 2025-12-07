<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects;

class ReturnShipmentResponse
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $shipmentId,
        public readonly ?string $labelUrl,
        public readonly string $status,
        public readonly array $rawData = []
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            trackingNumber: $data['returnBarcode'] ?? $data['barcode'] ?? $data['trackingNumber'] ?? '',
            shipmentId: $data['id'] ?? $data['shipmentId'] ?? '',
            labelUrl: $data['labelUrl'] ?? null,
            status: $data['status'] ?? 'NEW',
            rawData: $data
        );
    }

    public function toArray(): array
    {
        return [
            'tracking_number' => $this->trackingNumber,
            'shipment_id' => $this->shipmentId,
            'label_url' => $this->labelUrl,
            'status' => $this->status,
            'raw_data' => $this->rawData,
        ];
    }
}
