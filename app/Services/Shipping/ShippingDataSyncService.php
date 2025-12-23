<?php

namespace App\Services\Shipping;

use App\Actions\Shipping\ProcessReturnedShipmentAction;
use App\Actions\Shipping\UpdateShippingCostAction;
use App\Actions\Shipping\UpdateShippingInfoAction;
use App\Enums\Order\FulfillmentStatus;
use App\Events\Order\OrderAwaitingCustomerPickup;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;

class ShippingDataSyncService
{
    public function __construct(
        protected UpdateShippingCostAction $updateCostAction,
        protected UpdateShippingInfoAction $updateInfoAction,
        protected ProcessReturnedShipmentAction $processReturnAction
    ) {}

    /**
     * Main sync method - unified entry point for all shipping sync operations
     * Used by webhooks, manual resync, and artisan commands
     *
     * @return array{success: bool, results?: array, error?: string, return?: \App\Models\Order\OrderReturn|null}
     */
    public function syncShippingData(
        Order $order,
        Integration $integration,
        bool $force = false
    ): array {
        // Skip if order doesn't have tracking number
        if (! $order->shipping_tracking_number) {
            return [
                'success' => false,
                'error' => 'no_tracking_number',
            ];
        }

        // Skip if order already has costs (unless forced)
        if (! $force && ($order->shipping_cost_excluding_vat && $order->shipping_carrier)) {
            return [
                'success' => false,
                'error' => 'already_synced',
            ];
        }

        // Fetch latest shipment data from BasitKargo
        $adapter = new BasitKargoAdapter($integration);
        $shipmentData = $adapter->trackShipment($order->shipping_tracking_number);

        if (! $shipmentData) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                ])
                ->log('basitkargo_shipment_not_found');

            return [
                'success' => false,
                'error' => 'shipment_not_found',
            ];
        }

        $results = [
            'cost_updated' => false,
            'info_updated' => false,
            'return_created' => false,
        ];

        // 1. Update shipping costs (preserving shipping_amount)
        try {
            $results['cost_updated'] = $this->updateCostAction->execute(
                $order,
                $shipmentData,
                $integration
            );
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('shipping_cost_update_failed');
        }

        // 2. Update shipping info (carrier, desi)
        try {
            $results['info_updated'] = $this->updateInfoAction->execute(
                $order,
                $shipmentData
            );
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('shipping_info_update_failed');
        }

        // 3. Check if shipment is returned - auto-create return
        $isReturned = $shipmentData['is_returned'] ?? false;
        $return = null;

        if ($isReturned) {
            try {
                $return = $this->processReturnAction->execute(
                    $order,
                    $shipmentData,
                    $integration
                );
                $results['return_created'] = $return !== null;
            } catch (\Exception $e) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'tracking_number' => $order->shipping_tracking_number,
                        'error' => $e->getMessage(),
                    ])
                    ->log('return_creation_failed');
            }
        }

        // 4. Fill missing shipment IDs and aggregator integration ID
        $this->fillMissingShipmentIds($order, $shipmentData, $integration);

        // 5. Detect distribution center status and dispatch event if needed
        $this->detectAndHandleDistributionCenterStatus($order, $shipmentData);

        activity()
            ->performedOn($order)
            ->withProperties([
                'tracking_number' => $order->shipping_tracking_number,
                'results' => $results,
            ])
            ->log('shipping_data_synced');

        return [
            'success' => true,
            'results' => $results,
            'return' => $return,
        ];
    }

    /**
     * Sync shipping data from raw shipment data (for webhook use)
     * Skips the API call since we already have the data
     */
    public function syncFromShipmentData(
        Order $order,
        array $shipmentData,
        Integration $integration
    ): array {
        $results = [
            'cost_updated' => false,
            'info_updated' => false,
            'return_created' => false,
        ];

        // 1. Update shipping costs
        try {
            $results['cost_updated'] = $this->updateCostAction->execute(
                $order,
                $shipmentData,
                $integration
            );
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('shipping_cost_update_failed');
        }

        // 2. Update shipping info
        try {
            $results['info_updated'] = $this->updateInfoAction->execute(
                $order,
                $shipmentData
            );
        } catch (\Exception $e) {
            activity()
                ->performedOn($order)
                ->withProperties([
                    'tracking_number' => $order->shipping_tracking_number,
                    'error' => $e->getMessage(),
                ])
                ->log('shipping_info_update_failed');
        }

        // 3. Check if returned - auto-create return
        $isReturned = $shipmentData['is_returned'] ?? false;
        $return = null;

        if ($isReturned) {
            try {
                $return = $this->processReturnAction->execute(
                    $order,
                    $shipmentData,
                    $integration
                );
                $results['return_created'] = $return !== null;
            } catch (\Exception $e) {
                activity()
                    ->performedOn($order)
                    ->withProperties([
                        'tracking_number' => $order->shipping_tracking_number,
                        'error' => $e->getMessage(),
                    ])
                    ->log('return_creation_failed');
            }
        }

        // 4. Fill missing shipment IDs and aggregator integration ID
        $this->fillMissingShipmentIds($order, $shipmentData, $integration);

        // 5. Detect distribution center status and dispatch event if needed
        $this->detectAndHandleDistributionCenterStatus($order, $shipmentData);

        return [
            'success' => true,
            'results' => $results,
            'return' => $return,
        ];
    }

    /**
     * Fill in missing shipment tracking identifiers
     */
    protected function fillMissingShipmentIds(
        Order $order,
        array $shipmentData,
        Integration $integration
    ): void {
        $updateData = [];

        // Fill missing shipping_aggregator_shipment_id
        if (! $order->shipping_aggregator_shipment_id && isset($shipmentData['raw_data']['id'])) {
            $updateData['shipping_aggregator_shipment_id'] = $shipmentData['raw_data']['id'];
        }

        // Fill missing shipping_aggregator_integration_id
        if (! $order->shipping_aggregator_integration_id) {
            $updateData['shipping_aggregator_integration_id'] = $integration->id;
        }

        // Fill missing shipping_tracking_url
        if (! $order->shipping_tracking_url && isset($shipmentData['raw_data']['shipmentInfo']['handlerShipmentTrackingLink'])) {
            $updateData['shipping_tracking_url'] = $shipmentData['raw_data']['shipmentInfo']['handlerShipmentTrackingLink'];
        }

        // Fill missing shipping_tracking_number with handlerShipmentCode if available
        if (! $order->shipping_tracking_number && isset($shipmentData['raw_data']['shipmentInfo']['handlerShipmentCode'])) {
            $updateData['shipping_tracking_number'] = $shipmentData['raw_data']['shipmentInfo']['handlerShipmentCode'];
        }

        if (! empty($updateData)) {
            $order->update($updateData);

            activity()
                ->performedOn($order)
                ->withProperties($updateData)
                ->log('shipping_ids_filled');
        }
    }

    /**
     * Detect if shipment is at distribution center and handle accordingly
     */
    protected function detectAndHandleDistributionCenterStatus(
        Order $order,
        array $shipmentData
    ): void {
        // Get status message from shipment data
        $statusMessage = $shipmentData['raw_data']['shipmentInfo']['lastState'] ?? null;

        if (! $statusMessage) {
            return;
        }

        // Check if at distribution center
        if (! $this->isAtDistributionCenter($statusMessage)) {
            return;
        }

        // Only update if not already set to avoid duplicate events
        if ($order->fulfillment_status === FulfillmentStatus::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER) {
            return;
        }

        // Extract distribution center location from traces
        $distributionCenterName = null;
        $distributionCenterLocation = null;

        if (isset($shipmentData['raw_data']['traces']) && is_array($shipmentData['raw_data']['traces'])) {
            // Get the latest trace (first one in array)
            $latestTrace = $shipmentData['raw_data']['traces'][0] ?? null;

            if ($latestTrace) {
                $distributionCenterLocation = $latestTrace['location'] ?? null;
                $distributionCenterName = $latestTrace['locationDetail'] ?? $latestTrace['location'] ?? null;
            }
        }

        // Update order fulfillment status
        $order->update([
            'fulfillment_status' => FulfillmentStatus::AWAITING_PICKUP_AT_DISTRIBUTION_CENTER,
        ]);

        // Dispatch event for notifications (email, SMS, etc.)
        OrderAwaitingCustomerPickup::dispatch(
            $order,
            $distributionCenterName,
            $distributionCenterLocation
        );

        activity()
            ->performedOn($order)
            ->withProperties([
                'status_message' => $statusMessage,
                'distribution_center_name' => $distributionCenterName,
                'distribution_center_location' => $distributionCenterLocation,
            ])
            ->log('order_at_distribution_center_detected');
    }

    /**
     * Check if status message indicates package is at distribution center
     */
    protected function isAtDistributionCenter(?string $statusMessage): bool
    {
        if (! $statusMessage) {
            return false;
        }

        $statusMessage = mb_strtolower($statusMessage);

        // Turkish status messages indicating the package is at distribution center
        $distributionCenterIndicators = [
            'kargo devir',          // Transfer at distribution center
            'dağıtım merkezinde',   // At distribution center
            'şubede bekliyor',      // Waiting at branch
            'teslim alınmayı bekliyor', // Awaiting pickup
            'müşteri şubeye davet',  // Customer invited to branch
        ];

        foreach ($distributionCenterIndicators as $indicator) {
            if (str_contains($statusMessage, $indicator)) {
                return true;
            }
        }

        return false;
    }
}
