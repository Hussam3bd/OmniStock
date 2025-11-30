<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Order\Order;
use Illuminate\Support\Collection;

interface ShippingProviderAdapter
{
    public function authenticate(): bool;

    public function getRates(Order $order): Collection;

    public function createShipment(Order $order, array $options): array;

    public function trackShipment(string $trackingNumber): array;

    public function cancelShipment(string $shipmentId): bool;

    public function printLabel(string $shipmentId): string;
}
