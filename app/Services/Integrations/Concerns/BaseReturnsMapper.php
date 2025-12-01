<?php

namespace App\Services\Integrations\Concerns;

use App\Enums\Order\OrderChannel;
use App\Models\Order\OrderReturn;

abstract class BaseReturnsMapper
{
    abstract public function mapReturn(array $data): ?OrderReturn;

    abstract protected function getChannel(): OrderChannel;

    protected function convertToMinorUnits(float $amount, string $currency = 'TRY'): int
    {
        // Convert to minor units (cents/kuruş)
        return (int) round($amount * 100);
    }
}
