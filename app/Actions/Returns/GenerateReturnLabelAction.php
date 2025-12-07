<?php

namespace App\Actions\Returns;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Order\ReturnStatus;
use App\Enums\Shipping\ShippingCarrier;
use App\Models\Integration\Integration;
use App\Models\Order\OrderReturn;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects\LabelResponse;
use App\Services\Integrations\ShippingProviders\BasitKargo\DataTransferObjects\ReturnShipmentResponse;

class GenerateReturnLabelAction extends BaseReturnAction
{
    /**
     * Execute the action to generate return label
     *
     * @param  array  $options  Optional parameters:
     *                          - 'integration_id' => Integration ID to use (defaults to order's shipping integration or first active BasitKargo)
     *                          - 'save_label_file' => Whether to save label file to media library (default: true)
     */
    public function execute(OrderReturn $return, array $options = []): OrderReturn
    {
        if (! $this->validate($return)) {
            throw new \Exception('Return must be approved before generating label');
        }

        try {
            // Get the shipping aggregator integration
            $integration = $this->getShippingIntegration($return, $options['integration_id'] ?? null);

            if (! $integration) {
                throw new \Exception('No active shipping aggregator integration found');
            }

            // Create adapter
            $adapter = new BasitKargoAdapter($integration);

            // Step 1: Create return shipment
            $returnShipment = $adapter->createReturnShipment($return);

            // Step 2: Get the label using shipment ID (not tracking number)
            $label = $adapter->getReturnLabel($returnShipment->shipmentId, $returnShipment->trackingNumber);

            // Step 3: Update the return with shipment details
            $this->updateReturnWithShipmentDetails($return, $integration, $returnShipment, $label);

            // Step 4: Optionally save label file to media library
            if ($options['save_label_file'] ?? true) {
                $this->saveLabelFile($return, $label);
            }

            // Log success
            $this->logAction($return, 'return_label_generated', [
                'integration_id' => $integration->id,
                'integration_name' => $integration->name,
                'tracking_number' => $returnShipment->trackingNumber,
                'shipment_id' => $returnShipment->shipmentId,
                'label_format' => $label->format,
            ]);

            return $return->fresh();
        } catch (\Exception $e) {
            $this->logFailure($return, 'return_label_generation', $e, [
                'integration_id' => $integration->id ?? null,
            ]);

            throw $e;
        }
    }

    /**
     * Validate if label can be generated
     */
    public function validate(OrderReturn $return): bool
    {
        // Return must be approved
        if ($return->status !== ReturnStatus::Approved) {
            return false;
        }

        // Order must have outbound tracking number (required to create return shipment)
        if (! $return->order->shipping_tracking_number) {
            return false;
        }

        return true;
    }

    /**
     * Get the shipping integration to use for return
     */
    protected function getShippingIntegration(OrderReturn $return, ?int $integrationId = null): ?Integration
    {
        // If specific integration ID provided, use it
        if ($integrationId) {
            return Integration::query()
                ->where('id', $integrationId)
                ->where('provider', IntegrationProvider::BASIT_KARGO)
                ->where('is_active', true)
                ->first();
        }

        // Try to use the same integration that was used for outbound shipment
        if ($return->order->shipping_aggregator_integration_id) {
            $integration = Integration::find($return->order->shipping_aggregator_integration_id);

            if ($integration && $integration->is_active) {
                return $integration;
            }
        }

        // Fall back to first active BasitKargo integration
        return Integration::query()
            ->where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Update return with shipment details
     */
    protected function updateReturnWithShipmentDetails(
        OrderReturn $return,
        Integration $integration,
        ReturnShipmentResponse $shipment,
        LabelResponse $label
    ): void {
        // Parse carrier from shipment data if available
        $carrier = null;
        if (isset($shipment->rawData['handlerCode'])) {
            $carrier = ShippingCarrier::fromString($shipment->rawData['handlerCode']);
        }

        // Update return
        $return->update([
            'return_tracking_number' => $shipment->trackingNumber,
            'return_shipping_carrier' => $carrier,
            'return_shipping_aggregator_integration_id' => $integration->id,
            'return_shipping_aggregator_shipment_id' => $shipment->shipmentId,
            'return_shipping_aggregator_data' => $shipment->rawData,
            'label_generated_at' => now(),
            'status' => ReturnStatus::LabelGenerated,
        ]);
    }

    /**
     * Save label file to media library
     */
    protected function saveLabelFile(OrderReturn $return, LabelResponse $label): void
    {
        // Save label content to temporary file
        $tempPath = tempnam(sys_get_temp_dir(), 'return_label_');
        file_put_contents($tempPath, $label->labelContent);

        // Add to media library
        $return->addMedia($tempPath)
            ->usingFileName("return_label_{$label->trackingNumber}{$label->getFileExtension()}")
            ->withCustomProperties([
                'tracking_number' => $label->trackingNumber,
                'format' => $label->format,
                'generated_at' => now()->toIso8601String(),
            ])
            ->toMediaCollection('documents');

        // Clean up temp file
        @unlink($tempPath);
    }
}
