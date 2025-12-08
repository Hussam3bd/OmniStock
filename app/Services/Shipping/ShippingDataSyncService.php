<?php

namespace App\Services\Shipping;

use App\Actions\Shipping\ProcessReturnedShipmentAction;
use App\Actions\Shipping\UpdateShippingCostAction;
use App\Actions\Shipping\UpdateShippingInfoAction;
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

        return [
            'success' => true,
            'results' => $results,
            'return' => $return,
        ];
    }
}
