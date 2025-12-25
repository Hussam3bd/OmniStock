<?php

namespace App\Services\Integrations\ShippingProviders\BasitKargo;

use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Services\Integrations\Contracts\ShippingProviderAdapter;
use App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects\LabelResponse;
use App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects\ReturnShipmentResponse;
use App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects\ShipmentResponse;
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
            $shipment = $this->getShipmentDetails($trackingNumber);

            // Convert DTO to array for interface compatibility
            return [
                'tracking_number' => $shipment->getTrackingNumber(),
                'carrier_code' => $shipment->getCarrierCode(),
                'carrier_name' => $shipment->getCarrierName(),
                'status' => $shipment->status->value,
                'status_label' => $shipment->status->getLabel(),
                'desi' => $shipment->getDesi(),
                'price' => (int) round($shipment->getTotalCost() * 100), // Convert to minor units
                'currency' => 'TRY',
                'created_at' => $shipment->createdTime,
                'delivered_at' => $shipment->getDeliveredTime(),
                'is_delivered' => $shipment->isDelivered(),
                'is_in_transit' => $shipment->isInTransit(),
                'is_problematic' => $shipment->isProblematic(),
                'is_returned' => $shipment->isReturned(),
                'raw_data' => $shipment->rawData,
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

    /**
     * Get shipment details by BasitKargo shipment ID (e.g., "GKG-MMZ-4Y4")
     */
    public function getShipmentById(string $shipmentId): ?array
    {
        $response = $this->client()->get("/v2/order/{$shipmentId}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Get shipment details by tracking number (handler shipment code)
     */
    public function getShipmentByTrackingNumber(string $trackingNumber): ?array
    {
        $response = $this->client()->get("/v2/order/handler-shipment-code/{$trackingNumber}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Get shipment details by barcode
     */
    public function getShipmentByBarcode(string $barcode): ?array
    {
        $response = $this->client()->get("/v2/order/barcode/{$barcode}");

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Get shipment details as DTO (internal use for type safety)
     * Deprecated: Use specific methods (getShipmentById, getShipmentByTrackingNumber, getShipmentByBarcode) instead
     */
    protected function getShipmentDetails(string $trackingNumber): ShipmentResponse
    {
        // Try handler shipment code first (most common)
        $response = $this->client()->get("/v2/order/handler-shipment-code/{$trackingNumber}");

        if (! $response->successful()) {
            $errorBody = $response->body();
            $statusCode = $response->status();
            throw new \Exception("Failed to track shipment {$trackingNumber} (HTTP {$statusCode}): {$errorBody}");
        }

        $data = $response->json();

        return ShipmentResponse::fromArray($data);
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
     * Create a return shipment from original outbound shipment
     */
    public function createReturnShipment(OrderReturn $return): ReturnShipmentResponse
    {
        // Get the original outbound tracking number
        $outboundTrackingNumber = $return->order->shipping_tracking_number;

        if (! $outboundTrackingNumber) {
            throw new \Exception('Order must have a shipping tracking number to create return shipment');
        }

        try {
            // BasitKargo API: GET /v2/order/return/barcode/{barcode}
            // Creates a return shipment from the original outbound shipment
            $response = $this->client()->get("/v2/order/return/barcode/{$outboundTrackingNumber}");

            if (! $response->successful()) {
                throw new \Exception('Failed to create return shipment: '.$response->body());
            }

            $data = $response->json();

            // Create response DTO
            $returnShipment = ReturnShipmentResponse::fromArray($data);

            // Log success
            activity()
                ->performedOn($return)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'outbound_tracking' => $outboundTrackingNumber,
                    'return_tracking' => $returnShipment->trackingNumber,
                    'shipment_id' => $returnShipment->shipmentId,
                ])
                ->log('basitkargo_return_shipment_created');

            return $returnShipment;
        } catch (\Exception $e) {
            activity()
                ->performedOn($return)
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'outbound_tracking' => $outboundTrackingNumber,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_return_shipment_failed');

            throw $e;
        }
    }

    /**
     * Get shipping label for a shipment using shipment ID
     */
    public function getReturnLabel(string $shipmentId, string $trackingNumber): LabelResponse
    {
        try {
            // BasitKargo API: GET /label/svg/{id}
            // Downloads the shipping label in SVG format using shipment ID
            $response = $this->client()->get("/label/svg/{$shipmentId}");

            if (! $response->successful()) {
                throw new \Exception("Failed to get label for shipment {$shipmentId}: ".$response->body());
            }

            $svgContent = $response->body();

            // Create label response
            $label = LabelResponse::fromSvg($svgContent, $trackingNumber);

            // Log success
            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'tracking_number' => $trackingNumber,
                    'shipment_id' => $shipmentId,
                    'label_format' => 'svg',
                ])
                ->log('basitkargo_label_downloaded');

            return $label;
        } catch (\Exception $e) {
            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'tracking_number' => $trackingNumber,
                    'shipment_id' => $shipmentId,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_label_download_failed');

            throw $e;
        }
    }

    /**
     * Get shipment cost by tracking number
     */
    public function getShipmentCost(string $trackingNumber): ?array
    {
        try {
            // Use DTO method directly for type safety
            $shipment = $this->getShipmentDetails($trackingNumber);

            // Get price from DTO (API returns price in TRY, not minor units)
            $price = (int) round($shipment->getTotalCost() * 100); // Convert to minor units
            $desi = $shipment->getDesi();

            if (! $price || ! $desi) {
                return null;
            }

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
                'carrier_code' => $shipment->getCarrierCode(),
                'carrier_name' => $shipment->getCarrierName(),
                'desi' => $desi,
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

    /**
     * Filter orders by date range and other criteria
     */
    public function filterOrders(
        string $startDate,
        string $endDate,
        ?array $statusList = null,
        ?string $handlerCode = null,
        string $sortBy = 'CREATED_TIME',
        int $page = 0,
        int $size = 100
    ): array {
        try {
            $payload = [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'sortBy' => $sortBy,
                'page' => $page,
                'size' => min($size, 100), // Max 100 per API docs
            ];

            if ($statusList) {
                $payload['statusList'] = $statusList;
            }

            if ($handlerCode) {
                $payload['handlerCode'] = $handlerCode;
            }

            $response = $this->client()->post('/v2/order/filter', $payload);

            if (! $response->successful()) {
                activity()
                    ->withProperties([
                        'integration_id' => $this->integration->id,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'status_code' => $response->status(),
                        'response_body' => $response->body(),
                        'payload' => $payload,
                        'error' => 'HTTP '.$response->status(),
                    ])
                    ->log('basitkargo_filter_orders_http_error');

                throw new \Exception('Failed to filter orders: HTTP '.$response->status().' - '.$response->body());
            }

            $data = $response->json();

            // Log response for debugging
            // API returns orders directly as an array, not wrapped in 'content'
            $orders = is_array($data) && isset($data[0]) ? $data : ($data['content'] ?? []);

            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'payload' => $payload,
                    'response_structure' => is_array($data) && isset($data[0]) ? 'direct_array' : 'object',
                    'orders_count' => count($orders),
                    'total_elements' => $data['totalElements'] ?? null,
                ])
                ->log('basitkargo_filter_orders_success');

            return $orders;
        } catch (\Exception $e) {
            activity()
                ->withProperties([
                    'integration_id' => $this->integration->id,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'payload' => $payload ?? null,
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_filter_orders_failed');

            throw $e;
        }
    }

    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiToken,
                'Content-Type' => 'application/json',
            ])
            ->acceptJson()
            ->asJson()
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
