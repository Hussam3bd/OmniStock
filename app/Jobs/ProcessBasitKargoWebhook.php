<?php

namespace App\Jobs;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
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

        // BasitKargo provides two webhook types:
        // 1. Simple status webhook: { "id", "barcode", "status", "handler", "handlerShipmentCode" }
        // 2. Detailed shipment webhook: { "id", "barcode", "type", "status", "shipmentInfo", "priceInfo", etc. }

        // Determine webhook type and extract common fields
        $isDetailedWebhook = isset($payload['shipmentInfo']);

        if ($isDetailedWebhook) {
            // Detailed webhook format
            $trackingNumber = $payload['barcode'] ?? null;
            $statusCode = $payload['status'] ?? null;
            $shipmentId = $payload['id'] ?? null;
            $handlerCode = $payload['shipmentInfo']['handler']['code'] ?? null;
        } else {
            // Simple status webhook format
            $trackingNumber = $payload['barcode'] ?? null;
            $statusCode = $payload['status'] ?? null;
            $shipmentId = $payload['id'] ?? null;
            $handlerCode = $payload['handler']['code'] ?? null;
        }

        if (! $trackingNumber || ! $statusCode) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'Missing required fields (barcode or status) in webhook payload',
                ])
                ->log('basitkargo_webhook_invalid');

            return;
        }

        // Find integration by matching API token from webhook headers
        $headers = $this->webhookCall->headers ?? [];
        $providedToken = $headers['authorization'][0] ?? $headers['Authorization'][0] ?? null;

        // Remove "Bearer " prefix if present
        if ($providedToken) {
            $providedToken = str_replace('Bearer ', '', $providedToken);
        }

        if (! $providedToken) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No API token found in webhook headers',
                ])
                ->log('basitkargo_webhook_no_token');

            return;
        }

        // Search for integration with matching API token
        $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->get()
            ->first(function ($integration) use ($providedToken) {
                return ($integration->settings['api_token'] ?? null) === $providedToken;
            });

        if (! $integration) {
            activity()
                ->withProperties([
                    'payload' => $payload,
                    'error' => 'No matching BasitKargo integration found for provided API token',
                    'provided_token_prefix' => substr($providedToken, 0, 8).'...',
                ])
                ->log('basitkargo_webhook_no_integration');

            return;
        }

        try {
            // Parse the BasitKargo status
            $shipmentStatus = ShipmentStatus::tryFrom($statusCode);

            if (! $shipmentStatus) {
                activity()
                    ->performedOn($integration)
                    ->withProperties([
                        'tracking_number' => $trackingNumber,
                        'status' => $statusCode,
                        'error' => 'Unknown shipment status code',
                    ])
                    ->log('basitkargo_webhook_unknown_status');

                return;
            }

            // Try to find a return shipment first
            $return = OrderReturn::where('return_tracking_number', $trackingNumber)
                ->orWhere('return_shipping_aggregator_shipment_id', $shipmentId)
                ->first();

            if ($return) {
                $this->handleReturnShipmentUpdate($return, $shipmentStatus, $payload, $integration);

                return;
            }

            // If not a return, try to find an outbound order shipment
            $order = Order::where('shipping_tracking_number', $trackingNumber)
                ->first();

            if (! $order && $shipmentId) {
                $order = Order::where('shipping_aggregator_shipment_id', $shipmentId)->first();
            }

            if ($order) {
                $this->handleOrderShipmentUpdate($order, $shipmentStatus, $payload, $integration, $isDetailedWebhook);

                return;
            }

            // No matching shipment found
            activity()
                ->performedOn($integration)
                ->withProperties([
                    'tracking_number' => $trackingNumber,
                    'shipment_id' => $shipmentId,
                    'status' => $statusCode,
                    'error' => 'No matching order or return found for tracking number',
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

    protected function handleReturnShipmentUpdate(
        OrderReturn $return,
        ShipmentStatus $status,
        array $payload,
        Integration $integration
    ): void {
        $updateData = [];

        // Map BasitKargo status to Return status
        if ($status === ShipmentStatus::SHIPPED || $status === ShipmentStatus::OUT_FOR_DELIVERY) {
            // Return is in transit
            if ($return->status === ReturnStatus::Approved || $return->status === ReturnStatus::LabelGenerated) {
                $updateData['status'] = ReturnStatus::InTransit;
                $updateData['shipped_at'] = now();
            }
        } elseif ($status === ShipmentStatus::COMPLETED) {
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
                    'tracking_number' => $payload['trackingNumber'],
                    'status' => $status->value,
                    'message' => $payload['statusMessage'] ?? 'Return shipment returned to sender',
                ])
                ->log('return_shipment_returned_to_sender');
        }

        // Update shipment details if provided
        if (isset($payload['shipmentId'])) {
            $updateData['return_shipping_aggregator_shipment_id'] = $payload['shipmentId'];
        }

        if (! empty($updateData)) {
            $return->update($updateData);

            activity()
                ->performedOn($return)
                ->withProperties([
                    'integration_id' => $integration->id,
                    'tracking_number' => $payload['trackingNumber'],
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
                    'tracking_number' => $payload['trackingNumber'],
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

        // Extract tracking number and shipment ID based on webhook type
        $trackingNumber = $payload['barcode'] ?? $payload['trackingNumber'] ?? null;
        $shipmentId = $payload['id'] ?? $payload['shipmentId'] ?? null;

        // Update shipment details if provided
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

        // Use unified shipping data sync service
        // This handles: cost updates, info updates, and auto-return creation
        if ($isDetailedWebhook) {
            // For detailed webhooks, we have all the data we need
            $adapter = new BasitKargoAdapter($integration);
            $shipmentData = $adapter->trackShipment($trackingNumber);

            if ($shipmentData) {
                $syncService = app(ShippingDataSyncService::class);
                $syncService->syncFromShipmentData($order, $shipmentData, $integration);
            }
        }

        // Extract status message based on webhook type
        $statusMessage = null;
        if ($isDetailedWebhook) {
            $statusMessage = $payload['shipmentInfo']['lastState'] ?? null;
        } else {
            $statusMessage = $payload['statusMessage'] ?? $status->getLabel();
        }

        activity()
            ->performedOn($order)
            ->withProperties([
                'integration_id' => $integration->id,
                'tracking_number' => $trackingNumber,
                'shipment_id' => $shipmentId,
                'basitkargo_status' => $status->value,
                'status_message' => $statusMessage,
                'webhook_type' => $isDetailedWebhook ? 'detailed' : 'simple',
            ])
            ->log('basitkargo_order_webhook_processed');
    }
}
