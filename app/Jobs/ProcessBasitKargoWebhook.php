<?php

namespace App\Jobs;

use App\Enums\Order\ReturnStatus;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Models\Order\OrderReturn;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use App\Services\Integrations\ShippingProviders\BasitKargo\Enums\ShipmentStatus;
use App\Services\Shipping\ShippingDataSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessBasitKargoWebhook extends ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $payload;

    protected Integration $integration;

    protected ?string $barcode = null;

    protected ?string $handlerShipmentCode = null;

    protected ?string $shipmentId = null;

    protected ShipmentStatus $shipmentStatus;

    protected bool $isDetailedWebhook = false;

    public function handle(): void
    {
        $this->payload = $this->webhookCall->payload;

        if (! $this->validateAndLoadIntegration()) {
            return;
        }

        $this->extractIdentifiers();

        if (! $this->validateAndParseStatus()) {
            return;
        }

        $this->isDetailedWebhook = isset($this->payload['shipmentInfo']);

        $this->processWebhook();
    }

    /**
     * Validate webhook has integration and load it
     */
    protected function validateAndLoadIntegration(): bool
    {
        $integrationId = $this->payload['_basitkargo_integration_id'] ?? null;

        if (! $integrationId) {
            activity()
                ->withProperties([
                    'payload' => $this->payload,
                    'error' => 'Missing _basitkargo_integration_id in webhook payload',
                ])
                ->log('basitkargo_webhook_no_integration_id');

            return false;
        }

        $integration = Integration::find($integrationId);

        if (! $integration) {
            activity()
                ->withProperties([
                    'payload' => $this->payload,
                    'integration_id' => $integrationId,
                    'error' => 'Integration not found',
                ])
                ->log('basitkargo_webhook_integration_not_found');

            return false;
        }

        $this->integration = $integration;

        return true;
    }

    /**
     * Extract all tracking identifiers from webhook payload
     */
    protected function extractIdentifiers(): void
    {
        // Barcode is always at top level
        $this->barcode = $this->payload['barcode'] ?? null;

        // Handler shipment code can be in two places
        $this->handlerShipmentCode = $this->payload['handlerShipmentCode']
            ?? $this->payload['shipmentInfo']['handlerShipmentCode']
            ?? null;

        // BasitKargo shipment ID
        $this->shipmentId = $this->payload['id'] ?? null;
    }

    /**
     * Validate status exists and parse it
     */
    protected function validateAndParseStatus(): bool
    {
        $statusCode = $this->payload['status'] ?? null;

        if (! $statusCode) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'payload' => $this->payload,
                    'error' => 'Missing status in webhook payload',
                ])
                ->log('basitkargo_webhook_invalid');

            return false;
        }

        $shipmentStatus = ShipmentStatus::tryFrom($statusCode);

        if (! $shipmentStatus) {
            activity()
                ->performedOn($this->integration)
                ->withProperties($this->getIdentifierProperties() + [
                    'status' => $statusCode,
                    'error' => 'Unknown shipment status code',
                ])
                ->log('basitkargo_webhook_unknown_status');

            return false;
        }

        $this->shipmentStatus = $shipmentStatus;

        return true;
    }

    /**
     * Process the webhook - find shipment and update it
     */
    protected function processWebhook(): void
    {
        try {
            // Try to find return shipment first
            $return = $this->findReturn();

            if ($return) {
                $this->handleReturnShipmentUpdate($return);

                return;
            }

            // Try to find order shipment
            $order = $this->findOrder();

            if ($order) {
                $this->handleOrderShipmentUpdate($order);

                return;
            }

            // No matching shipment found
            activity()
                ->performedOn($this->integration)
                ->withProperties($this->getIdentifierProperties() + [
                    'status' => $this->payload['status'],
                    'error' => 'No matching order or return found',
                ])
                ->log('basitkargo_webhook_no_shipment');
        } catch (\Exception $e) {
            activity()
                ->performedOn($this->integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'payload' => $this->payload,
                    'trace' => $e->getTraceAsString(),
                ])
                ->log('basitkargo_webhook_failed');

            throw $e;
        }
    }

    /**
     * Find return by searching all identifiers
     */
    protected function findReturn(): ?OrderReturn
    {
        return OrderReturn::query()
            ->where(function ($q) {
                if ($this->barcode) {
                    $q->orWhere('return_tracking_number', $this->barcode);
                }

                if ($this->handlerShipmentCode) {
                    $q->orWhere('return_tracking_number', $this->handlerShipmentCode);
                }

                if ($this->shipmentId) {
                    $q->orWhere('return_shipping_aggregator_shipment_id', $this->shipmentId);
                }
            })
            ->first();
    }

    /**
     * Find order by searching all identifiers
     */
    protected function findOrder(): ?Order
    {
        return Order::query()
            ->where(function ($q) {
                if ($this->barcode) {
                    $q->orWhere('shipping_tracking_number', $this->barcode);
                }

                if ($this->handlerShipmentCode) {
                    $q->orWhere('shipping_tracking_number', $this->handlerShipmentCode);
                }

                if ($this->shipmentId) {
                    $q->orWhere('shipping_aggregator_shipment_id', $this->shipmentId);
                }
            })
            ->first();
    }

    /**
     * Handle return shipment webhook update
     */
    protected function handleReturnShipmentUpdate(OrderReturn $return): void
    {
        $updateData = $this->getReturnUpdateData($return);

        if (! empty($updateData)) {
            $return->update($updateData);

            activity()
                ->performedOn($return)
                ->withProperties($this->getIdentifierProperties() + [
                    'integration_id' => $this->integration->id,
                    'old_status' => $return->getOriginal('status'),
                    'new_status' => $updateData['status'] ?? $return->status,
                    'basitkargo_status' => $this->shipmentStatus->value,
                ])
                ->log('basitkargo_return_webhook_processed');
        } else {
            activity()
                ->performedOn($return)
                ->withProperties($this->getIdentifierProperties() + [
                    'integration_id' => $this->integration->id,
                    'status' => $this->shipmentStatus->value,
                    'reason' => 'No status update needed',
                ])
                ->log('basitkargo_return_webhook_skipped');
        }
    }

    /**
     * Get update data for return based on shipment status
     */
    protected function getReturnUpdateData(OrderReturn $return): array
    {
        $updateData = [];

        // Map BasitKargo status to Return status
        if ($this->shipmentStatus === ShipmentStatus::SHIPPED || $this->shipmentStatus === ShipmentStatus::OUT_FOR_DELIVERY) {
            if ($return->status === ReturnStatus::Approved || $return->status === ReturnStatus::LabelGenerated) {
                $updateData['status'] = ReturnStatus::InTransit;
                $updateData['shipped_at'] = now();
            }
        } elseif ($this->shipmentStatus === ShipmentStatus::DELIVERED || $this->shipmentStatus === ShipmentStatus::COMPLETED) {
            if (! in_array($return->status, [ReturnStatus::Inspecting, ReturnStatus::Completed, ReturnStatus::Rejected])) {
                $updateData['status'] = ReturnStatus::Received;
                $updateData['received_at'] = now();
            }
        } elseif ($this->shipmentStatus === ShipmentStatus::RETURNED || $this->shipmentStatus === ShipmentStatus::RETURNING) {
            activity()
                ->performedOn($return)
                ->withProperties($this->getIdentifierProperties() + [
                    'status' => $this->shipmentStatus->value,
                    'message' => $this->payload['statusMessage'] ?? 'Return shipment returned to sender',
                ])
                ->log('return_shipment_returned_to_sender');
        }

        // Update tracking number if not set
        if ($this->handlerShipmentCode && ! $return->return_tracking_number) {
            $updateData['return_tracking_number'] = $this->handlerShipmentCode;
        }

        // Update shipment ID if not set
        if ($this->shipmentId && ! $return->return_shipping_aggregator_shipment_id) {
            $updateData['return_shipping_aggregator_shipment_id'] = $this->shipmentId;
        }

        return $updateData;
    }

    /**
     * Handle order shipment webhook update
     */
    protected function handleOrderShipmentUpdate(Order $order): void
    {
        $this->updateOrderIdentifiers($order);

        if ($this->isDetailedWebhook) {
            $this->fetchAndSyncShipmentData($order);
        }

        $this->logOrderWebhookProcessed($order);
    }

    /**
     * Update order tracking identifiers if not set
     */
    protected function updateOrderIdentifiers(Order $order): void
    {
        $updateData = [];

        if ($this->handlerShipmentCode && ! $order->shipping_tracking_number) {
            $updateData['shipping_tracking_number'] = $this->handlerShipmentCode;
        }

        if ($this->shipmentId && ! $order->shipping_aggregator_shipment_id) {
            $updateData['shipping_aggregator_shipment_id'] = $this->shipmentId;
        }

        if (! $order->shipping_aggregator_integration_id) {
            $updateData['shipping_aggregator_integration_id'] = $this->integration->id;
        }

        if (! empty($updateData)) {
            $order->update($updateData);
        }
    }

    /**
     * Fetch shipment details from API and sync data
     */
    protected function fetchAndSyncShipmentData(Order $order): void
    {
        $adapter = new BasitKargoAdapter($this->integration);
        $shipmentData = null;

        try {
            // Call the appropriate endpoint based on available identifier
            // Priority: shipmentId > handlerShipmentCode > barcode
            if ($this->shipmentId) {
                $shipmentData = $adapter->getShipmentById($this->shipmentId);
            } elseif ($this->handlerShipmentCode) {
                $shipmentData = $adapter->getShipmentByTrackingNumber($this->handlerShipmentCode);
            } elseif ($this->barcode) {
                $shipmentData = $adapter->getShipmentByBarcode($this->barcode);
            }

            if ($shipmentData) {
                $syncService = app(ShippingDataSyncService::class);
                $syncService->syncFromShipmentData($order, $shipmentData, $this->integration);
            } else {
                Log::warning('Failed to fetch shipment details from BasitKargo API', [
                    'order_id' => $order->id,
                ] + $this->getIdentifierProperties());
            }
        } catch (\Exception $e) {
            Log::warning('Exception while fetching shipment details from BasitKargo API', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ] + $this->getIdentifierProperties());

            activity()
                ->performedOn($order)
                ->withProperties($this->getIdentifierProperties() + [
                    'error' => $e->getMessage(),
                ])
                ->log('basitkargo_track_shipment_failed');
        }
    }

    /**
     * Log order webhook processing
     */
    protected function logOrderWebhookProcessed(Order $order): void
    {
        $statusMessage = $this->isDetailedWebhook
            ? ($this->payload['shipmentInfo']['lastState'] ?? null)
            : ($this->payload['statusMessage'] ?? $this->shipmentStatus->getLabel());

        activity()
            ->performedOn($order)
            ->withProperties($this->getIdentifierProperties() + [
                'integration_id' => $this->integration->id,
                'basitkargo_status' => $this->shipmentStatus->value,
                'status_message' => $statusMessage,
                'webhook_type' => $this->isDetailedWebhook ? 'detailed' : 'simple',
            ])
            ->log('basitkargo_order_webhook_processed');
    }

    /**
     * Get identifier properties for logging
     */
    protected function getIdentifierProperties(): array
    {
        return [
            'barcode' => $this->barcode,
            'handler_shipment_code' => $this->handlerShipmentCode,
            'shipment_id' => $this->shipmentId,
        ];
    }
}
