<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo;

use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\Contracts\ShippingProviderAdapter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class BasitKargoAdapter implements ShippingProviderAdapter
{
    protected string $baseUrl = 'https://basitkargo.com/api';

    protected ?string $apiToken = null;

    public function __construct(
        protected Integration $integration
    ) {
        $this->apiToken = $this->integration->settings['api_token'] ?? null;
    }

    public function authenticate(): bool
    {
        if (! $this->apiToken) {
            return false;
        }

        try {
            // Test authentication by making a simple API call
            $response = $this->client()->get('/v2/handlers');

            return $response->successful();
        } catch (\Exception $e) {
            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_authentication_failed');

            return false;
        }
    }

    public function getRates(Order $order): Collection
    {
        if (! $order->shipping_desi) {
            throw new \Exception('Order must have shipping_desi to get rates');
        }

        try {
            $response = $this->client()->get("/handlers/fee/desiKg/{$order->shipping_desi}");

            if (! $response->successful()) {
                throw new \Exception('Failed to fetch rates: '.$response->body());
            }

            $rates = $response->json();
            $vatIncluded = $this->integration->settings['vat_included'] ?? true;

            return collect($rates)->map(function ($rate) use ($vatIncluded) {
                $price = $rate['price'] * 100; // Convert to minor units

                // Calculate VAT breakdown
                if ($vatIncluded) {
                    // Price includes VAT - extract it
                    $priceExcludingVat = (int) round($price / 1.20);
                    $vatAmount = $price - $priceExcludingVat;
                    $totalPrice = $price;
                } else {
                    // Price excludes VAT - add it
                    $priceExcludingVat = $price;
                    $vatAmount = (int) round($price * 0.20);
                    $totalPrice = $priceExcludingVat + $vatAmount;
                }

                return [
                    'carrier_code' => $rate['handlerCode'],
                    'carrier_name' => $this->getCarrierName($rate['handlerCode']),
                    'price' => $totalPrice,
                    'price_excluding_vat' => $priceExcludingVat,
                    'vat_amount' => $vatAmount,
                    'vat_rate' => 20.00,
                    'currency' => 'TRY',
                    'desi' => $rate['desiKg'],
                ];
            });
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_get_rates_failed');

            throw $e;
        }
    }

    public function createShipment(Order $order, array $options): array
    {
        // Implementation for creating shipment
        // This would be used when you want to create shipments through BasitKargo
        throw new \Exception('Not implemented yet');
    }

    public function trackShipment(string $trackingNumber): array
    {
        try {
            $response = $this->client()->get("/v2/order/handler-shipment-code/{$trackingNumber}");

            if (! $response->successful()) {
                throw new \Exception("Failed to track shipment: {$trackingNumber}");
            }

            $data = $response->json();

            // Extract shipping details
            return [
                'tracking_number' => $trackingNumber,
                'carrier_code' => $data['handlerCode'] ?? null,
                'carrier_name' => $this->getCarrierName($data['handlerCode'] ?? null),
                'status' => $data['status'] ?? null,
                'desi' => $data['desiKg'] ?? null,
                'price' => isset($data['price']) ? $data['price'] * 100 : null, // Convert to minor units
                'currency' => 'TRY',
                'created_at' => $data['createdAt'] ?? null,
                'delivered_at' => $data['deliveredAt'] ?? null,
                'raw_data' => $data,
            ];
        } catch (\Exception $e) {
            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'tracking_number' => $trackingNumber,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_track_shipment_failed');

            throw $e;
        }
    }

    public function cancelShipment(string $shipmentId): bool
    {
        // Implementation for canceling shipment
        throw new \Exception('Not implemented yet');
    }

    public function printLabel(string $shipmentId): string
    {
        // Implementation for printing label
        throw new \Exception('Not implemented yet');
    }

    /**
     * Get shipment cost by tracking number
     */
    public function getShipmentCost(string $trackingNumber): ?array
    {
        try {
            $shipment = $this->trackShipment($trackingNumber);

            if (! isset($shipment['price']) || ! isset($shipment['desi'])) {
                return null;
            }

            $price = $shipment['price'];
            $vatRate = 20.00;
            $vatIncluded = $this->integration->settings['vat_included'] ?? true;

            // Calculate VAT based on whether API prices include VAT
            if ($vatIncluded) {
                // Price includes VAT - extract it
                $totalPrice = $price;
                $priceExcludingVat = (int) round($price / 1.20);
                $vatAmount = $totalPrice - $priceExcludingVat;
            } else {
                // Price excludes VAT - add it
                $priceExcludingVat = $price;
                $vatAmount = (int) round($priceExcludingVat * 0.20);
                $totalPrice = $priceExcludingVat + $vatAmount;
            }

            return [
                'carrier_code' => $shipment['carrier_code'],
                'carrier_name' => $shipment['carrier_name'],
                'desi' => $shipment['desi'],
                'price_excluding_vat' => $priceExcludingVat,
                'vat_rate' => $vatRate,
                'vat_amount' => $vatAmount,
                'total_price' => $totalPrice,
                'currency' => 'TRY',
            ];
        } catch (\Exception $e) {
            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'tracking_number' => $trackingNumber,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_get_shipment_cost_failed');

            return null;
        }
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiToken,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])
            ->timeout(30);
    }

    protected function getCarrierName(?string $code): ?string
    {
        return match (strtoupper($code ?? '')) {
            'MNG' => 'MNG Kargo',
            'YURTICI' => 'Yurtiçi Kargo',
            'ARAS' => 'Aras Kargo',
            'SURAT' => 'Sürat Kargo',
            'PTT' => 'PTT Kargo',
            'UPS' => 'UPS Kargo',
            'DHL' => 'DHL Express',
            'FEDEX' => 'FedEx',
            'ECONOMIC' => 'Most Economic',
            'FAST' => 'Fastest',
            default => $code,
        };
    }
}
