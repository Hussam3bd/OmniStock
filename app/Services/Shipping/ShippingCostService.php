<?php

namespace App\Services\Shipping;

use App\Enums\Shipping\ShippingCarrier;
use App\Models\Shipping\ShippingRate;
use App\Models\Shipping\ShippingRateTable;

class ShippingCostService
{
    /**
     * Calculate shipping cost for given carrier and desi
     *
     * @return array{
     *     rate_id: int|null,
     *     carrier: ShippingCarrier,
     *     desi: float,
     *     cost_excluding_vat: int,
     *     vat_rate: float,
     *     vat_amount: int,
     *     total_cost: int
     * }|null
     */
    public function calculateCost(ShippingCarrier $carrier, float $desi, ?int $rateTableId = null): ?array
    {
        // Find the applicable rate
        $rate = ShippingRate::findRateForDesi($carrier, $desi, $rateTableId);

        if (! $rate) {
            activity()
                ->withProperties([
                    'carrier' => $carrier->value,
                    'desi' => $desi,
                    'rate_table_id' => $rateTableId,
                ])
                ->log('shipping_rate_not_found');

            return null;
        }

        return [
            'rate_id' => $rate->id,
            'carrier' => $carrier,
            'desi' => $desi,
            'cost_excluding_vat' => $rate->price_excluding_vat,
            'vat_rate' => (float) $rate->vat_rate,
            'vat_amount' => $rate->vat_amount,
            'total_cost' => $rate->total_price,
        ];
    }

    /**
     * Parse carrier name from string (e.g., from Trendyol API)
     */
    public function parseCarrier(string $carrierName): ?ShippingCarrier
    {
        return ShippingCarrier::fromString($carrierName);
    }

    /**
     * Get active rate table
     */
    public function getActiveRateTable(): ?ShippingRateTable
    {
        return ShippingRateTable::active();
    }

    /**
     * Check if rates are available for calculation
     */
    public function hasActiveRates(): bool
    {
        return $this->getActiveRateTable() !== null;
    }
}
