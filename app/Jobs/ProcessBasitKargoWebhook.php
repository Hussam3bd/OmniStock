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
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;

class ProcessBasitKargoWebhook extends ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $payload = $this->webhookCall->payload;

        // Extract integration ID (validated before webhook reaches here)
        $integrationId = $payload['_basitkargo_integration_id'] ?? null;

        if (! $integrationId) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'Missing _basitkargo_integration_id in webhook payload',
                ])
                ->log('basitkargo_webhook_no_integration_id');

            return;
        }

        $integration = Integration::find($integrationId);

        if (! $integration) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'integration_id' => $integrationId,
                    'error' => 'Integration not found',
                ])
                ->log('basitkargo_webhook_integration_not_found');

            return;
        }

        // Extract tracking identifiers (all three)
        $barcode = $payload['barcode'] ?? null;
        $handlerShipmentCode = $payload['handlerShipmentCode'] ?? null;
        $shipmentId = $payload['id'] ?? null;
        $statusCode = $payload['status'] ?? null;

        if (! $statusCode) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'Missing status in webhook payload',
                ])
                ->log('basitkargo_webhook_invalid');

            return;
        }

        // Parse the BasitKargo status
        $shipmentStatus = ShipmentStatus::tryFrom($statusCode);

        if (! $shipmentStatus) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'barcode' => $barcode,
                    'handler_shipment_code' => $handlerShipmentCode,
                    'shipment_id' => $shipmentId,
                    'status' => $statusCode,
                    'error' => 'Unknown shipment status code',
                ])
                ->log('basitkargo_webhook_unknown_status');

            return;
        }

        // Determine if detailed webhook (has shipmentInfo)
        $isDetailedWebhook = isset($payload['shipmentInfo']);

        try {
            // Try to find return shipment first (search by all identifiers)
            $return = $this->findReturn($barcode, $handlerShipmentCode, $shipmentId);

            if ($return) {
                $this->handleReturnShipmentUpdate($return, $shipmentStatus, $payload, $integration);

                return;
            }

            // Try to find order shipment (search by all identifiers)
            $order = $this->findOrder($barcode, $handlerShipmentCode, $shipmentId);

            if ($order) {
                $this->handleOrderShipmentUpdate($order, $shipmentStatus, $payload, $integration, $isDetailedWebhook);

                return;
            }

            // No matching shipment found
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'barcode' => $barcode,
                    'handler_shipment_code' => $handlerShipmentCode,
                    'shipment_id' => $shipmentId,
                    'status' => $statusCode,
                    'error' => 'No matching order or return found',
                ])
                ->log('basitkargo_webhook_no_shipment');
        } catch (\Exception $e) {
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'payload' => $payload,
                    'trace' => $e->getTraceAsString(),
                ])
                ->log('basitkargo_webhook_failed');

            throw $e;
        }
    }

    /**
     * Find return by searching all three identifiers
     */
    protected function findReturn(?string $barcode, ?string $handlerShipmentCode, ?string $shipmentId): ?OrderReturn
    {
        $query = OrderReturn::query();

        $query->where(function ($q) use ($barcode, $handlerShipmentCode, $shipmentId) {
            if ($barcode) {
                $q->orWhere('return_tracking_number', $barcode);
            }

            if ($handlerShipmentCode) {
                $q->orWhere('return_tracking_number', $handlerShipmentCode);
            }

            if ($shipmentId) {
                $q->orWhere('return_shipping_aggregator_shipment_id', $shipmentId);
            }
        });

        return $query->first();
    }

    /**
     * Find order by searching all three identifiers
     */
    protected function findOrder(?string $barcode, ?string $handlerShipmentCode, ?string $shipmentId): ?Order
    {
        $query = Order::query();

        $query->where(function ($q) use ($barcode, $handlerShipmentCode, $shipmentId) {
            if ($barcode) {
                $q->orWhere('shipping_tracking_number', $barcode);
            }

            if ($handlerShipmentCode) {
                $q->orWhere('shipping_tracking_number', $handlerShipmentCode);
            }

            if ($shipmentId) {
                $q->orWhere('shipping_aggregator_shipment_id', $shipmentId);
            }
        });

        return $query->first();
    }

    protected function handleReturnShipmentUpdate(
        OrderReturn $return,
        ShipmentStatus $status,
        array $payload,
        Integration $integration
    ): void {
        $updateData = [];

        // Extract identifiers
        $barcode = $payload['barcode'] ?? null;
        $handlerShipmentCode = $payload['handlerShipmentCode'] ?? null;
        $shipmentId = $payload['id'] ?? null;

        // Map BasitKargo status to Return status
        if ($status === ShipmentStatus::SHIPPED || $status === ShipmentStatus::OUT_FOR_DELIVERY) {
            // Return is in transit
            if ($return->status === ReturnStatus::Approved || $return->status === ReturnStatus::LabelGenerated) {
                $updateData['status'] = ReturnStatus::InTransit;
                $updateData['shipped_at'] = now();
            }
        } elseif ($status === ShipmentStatus::DELIVERED || $status === ShipmentStatus::COMPLETED) {
            // Return has been delivered to warehouse
            if (! in_array($return->status, [ReturnStatus::Inspecting, ReturnStatus::Completed, ReturnStatus::Rejected])) {
                $updateData['status'] = ReturnStatus::Received;
                $updateData['received_at'] = now();
            }
        } elseif ($status === ShipmentStatus::RETURNED || $status === ShipmentStatus::RETURNING) {
            // Return shipment failed and is being returned to customer
            activity()
                ->performedOn($return)
                ->withProperties([
                    'barcode' => $barcode,
                    'handler_shipment_code' => $handlerShipmentCode,
                    'status' => $status->value,
                    'message' => $payload['statusMessage'] ?? 'Return shipment returned to sender',
                ])
                ->log('return_shipment_returned_to_sender');
        }

        // Update tracking number with handlerShipmentCode if available and not set
        if ($handlerShipmentCode && ! $return->return_tracking_number) {
            $updateData['return_tracking_number'] = $handlerShipmentCode;
        }

        // Update shipment ID if not set
        if ($shipmentId && ! $return->return_shipping_aggregator_shipment_id) {
            $updateData['return_shipping_aggregator_shipment_id'] = $shipmentId;
        }

        if (! empty($updateData)) {
            $return->update($updateData);

            activity()
                ->performedOn($return)
                ->withProperties([
                    'integration_id' => $integration->id,
                    'barcode' => $barcode,
                    'handler_shipment_code' => $handlerShipmentCode,
                    'shipment_id' => $shipmentId,
                    'old_status' => $return->getOriginal('status'),
                    'new_status' => $updateData['status'] ?? $return->status,
                    'basitkargo_status' => $status->value,
                ])
                ->log('basitkargo_return_webhook_processed');
        } else {
            activity()
                ->performedOn($return)
                ->withProperties([
                    'integration_id' => $integration->id,
                    'barcode' => $barcode,
                    'handler_shipment_code' => $handlerShipmentCode,
                    'shipment_id' => $shipmentId,
                    'status' => $status->value,
                    'reason' => 'No status update needed',
                ])
                ->log('basitkargo_return_webhook_skipped');
        }
    }

    protected function handleOrderShipmentUpdate(
        Order $order,
        ShipmentStatus $status,
        array $payload,
        Integration $integration,
        bool $isDetailedWebhook = false
    ): void {
        $updateData = [];

        // Extract identifiers
        $barcode = $payload['barcode'] ?? null;
        $handlerShipmentCode = $payload['handlerShipmentCode'] ?? null;
        $shipmentId = $payload['id'] ?? null;

        // Update tracking number with handlerShipmentCode if available
        if ($handlerShipmentCode && ! $order->shipping_tracking_number) {
            $updateData['shipping_tracking_number'] = $handlerShipmentCode;
        }

        // Update shipment ID if not set
        if ($shipmentId && ! $order->shipping_aggregator_shipment_id) {
            $updateData['shipping_aggregator_shipment_id'] = $shipmentId;
        }

        // Update aggregator integration ID if not set
        if (! $order->shipping_aggregator_integration_id) {
            $updateData['shipping_aggregator_integration_id'] = $integration->id;
        }

        // Update order fields
        if (! empty($updateData)) {
            $order->update($updateData);
        }

        // Use unified shipping data sync service for detailed webhooks
        // This handles: cost updates, info updates, and auto-return creation
        if ($isDetailedWebhook) {
            $adapter = new BasitKargoAdapter($integration);

            // Use handlerShipmentCode if available, fallback to barcode
            $trackingToQuery = $handlerShipmentCode ?? $barcode;

            if ($trackingToQuery) {
                $shipmentData = $adapter->trackShipment($trackingToQuery);

                if ($shipmentData) {
                    $syncService = app(ShippingDataSyncService::class);
                    $syncService->syncFromShipmentData($order, $shipmentData, $integration);
                }
            }
        }

        // Extract status message for logging
        $statusMessage = null;
        if ($isDetailedWebhook) {
            $statusMessage = $payload['shipmentInfo']['lastState'] ?? null;
        } else {
            $statusMessage = $payload['statusMessage'] ?? $status->getLabel();
        }

        // Note: Distribution center detection is now handled by ShippingDataSyncService
        // when syncFromShipmentData is called above (in detailed webhooks)

        activity()
            ->performedOn($order)
            ->withProperties([
                'integration_id' => $integration->id,
                'barcode' => $barcode,
                'handler_shipment_code' => $handlerShipmentCode,
                'shipment_id' => $shipmentId,
                'basitkargo_status' => $status->value,
                'status_message' => $statusMessage,
                'webhook_type' => $isDetailedWebhook ? 'detailed' : 'simple',
            ])
            ->log('basitkargo_order_webhook_processed');
    }
}
