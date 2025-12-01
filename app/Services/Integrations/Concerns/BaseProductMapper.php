<?php

namespace App\Services\Integrations\Concerns;

use App\Enums\Order\OrderChannel;
use App\Models\Product\Product;

abstract class BaseProductMapper
{
    abstract public function mapProduct(array $data): ?Product;

    abstract protected function getChannel(): OrderChannel;

    protected function convertToMinorUnits(float $amount, string $currency = 'TRY'): int
    {
        // Convert to minor units (cents/kuruş)
        return (int) round($amount * 100);
    }

    protected function convertFromMinorUnits(int $amount): float
    {
        // Convert from minor units to major units
        return $amount / 100;
    }
}
