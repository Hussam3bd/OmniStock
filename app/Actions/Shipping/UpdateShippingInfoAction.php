<?php

namespace App\Actions\Shipping;

use App\Enums\Shipping\ShippingCarrier;
use App\Models\Order\Order;

class UpdateShippingInfoAction
{
    /**
     * Update shipping information (carrier, desi, tracking)
     * Uses handlerDesi (carrier-updated value) instead of original desi
     */
    public function execute(Order $order, array $shipmentData): bool
    {
        // Use handlerDesi (updated by carrier), not original desi
        // This is the correct weight/volume after carrier weighs the package
        $desi = $shipmentData['raw_data']['shipmentInfo']['handlerDesi']
             ?? $shipmentData['desi']
             ?? $order->shipping_desi;

        // Map carrier code to our enum
        $carrier = null;
        $carrierCode = $shipmentData['carrier_code'] ?? null;
        if ($carrierCode) {
            $carrier = $this->mapBasitKargoCodeToCarrier($carrierCode);
        }

        // Update order info
        $order->update([
            'shipping_carrier' => $carrier?->value ?? $order->shipping_carrier,
            'shipping_desi' => $desi,
        ]);

        activity()
            ->performedOn($order)
            ->withProperties([
                'tracking_number' => $order->shipping_tracking_number,
                'carrier' => $carrier?->value,
                'carrier_code' => $carrierCode,
                'desi' => $desi,
                'used_handler_desi' => isset($shipmentData['raw_data']['shipmentInfo']['handlerDesi']),
            ])
            ->log('shipping_info_updated');

        return true;
    }

    /**
     * Map BasitKargo carrier codes to our ShippingCarrier enum
     */
    protected function mapBasitKargoCodeToCarrier(string $code): ?ShippingCarrier
    {
        return match (strtoupper($code)) {
            'MNG' => null, // MNG not in our enum
            'YURTICI' => ShippingCarrier::YURTICI,
            'ARAS' => ShippingCarrier::ARAS,
            'SURAT' => ShippingCarrier::SURAT,
            'PTT' => ShippingCarrier::PTT,
            'DHL' => ShippingCarrier::DHL,
            'HEPSIJET' => ShippingCarrier::HEPSIJET,
            default => null,
        };
    }
}
