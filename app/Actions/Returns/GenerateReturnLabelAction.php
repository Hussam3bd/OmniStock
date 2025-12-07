<?php

namespace App\Actions\Returns;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Order\OrderChannel;
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

            // Step 5: If return is from Shopify, attach label to Shopify
            if ($return->channel === OrderChannel::SHOPIFY) {
                try {
                    $this->attachLabelToShopify($return, $returnShipment, $label);
                } catch (\Exception $e) {
                    // Log but don't fail - label was generated successfully
                    activity()
                        ->performedOn($return)
                        ->withProperties([
                            'error' => $e->getMessage(),
                            'tracking_number' => $returnShipment->trackingNumber,
                        ])
                        ->log('shopify_label_attachment_failed_after_generation');
                }
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

        // Prevent duplicate label generation - if label already exists, don't generate another
        if ($return->label_generated_at && $return->return_tracking_number) {
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
     * Note: Label can also be regenerated on-demand from barcode, so saving is optional
     */
    protected function saveLabelFile(OrderReturn $return, LabelResponse $label): void
    {
        try {
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
        } catch (\Exception $e) {
            // Log but don't fail - label can be regenerated from barcode if needed
            activity()
                ->performedOn($return)
                ->withProperties([
                    'error' => $e->getMessage(),
                    'tracking_number' => $label->trackingNumber,
                ])
                ->log('return_label_file_save_failed');
        }
    }

    /**
     * Attach label to Shopify reverse delivery
     * Tries BasitKargo's labelUrl first, then falls back to media library public URL
     */
    protected function attachLabelToShopify(
        OrderReturn $return,
        ReturnShipmentResponse $shipment,
        LabelResponse $label
    ): void {
        // Get Shopify integration by provider (orders don't have a direct relationship to sales channel integration)
        $integration = Integration::where('provider', IntegrationProvider::SHOPIFY)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            activity()
                ->performedOn($return)
                ->withProperties(['error' => 'No active Shopify integration found'])
                ->log('shopify_label_attachment_skipped');

            return;
        }

        // Create Shopify adapter
        $adapter = new \App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter($integration);

        // Step 1: Approve the return in Shopify (changes status from REQUESTED to OPEN)
        if ($return->external_return_id) {
            $shopifyReturnId = 'gid://shopify/Return/'.$return->external_return_id;

            activity()
                ->performedOn($return)
                ->withProperties([
                    'shopify_return_id' => $shopifyReturnId,
                    'action' => 'approving_return',
                ])
                ->log('shopify_return_approval_attempt');

            $approvalResult = $adapter->approveReturn($shopifyReturnId);

            if (! $approvalResult) {
                throw new \Exception('Failed to approve return in Shopify. Check activity log for details.');
            }

            activity()
                ->performedOn($return)
                ->withProperties([
                    'shopify_return_id' => $shopifyReturnId,
                    'result' => $approvalResult,
                ])
                ->log('shopify_return_approved');
        }

        // Step 2: Get label URL
        // Option 1: Use BasitKargo's label URL if provided
        $labelUrl = $shipment->labelUrl;

        // Option 2: Get public URL from media library if BasitKargo doesn't provide one
        if (! $labelUrl) {
            $media = $return->getFirstMedia('documents');
            if ($media) {
                $labelUrl = $media->getFullUrl();
            }
        }

        // If we still don't have a label URL, we can't attach to Shopify
        if (! $labelUrl) {
            throw new \Exception('No public label URL available for Shopify attachment');
        }

        activity()
            ->performedOn($return)
            ->withProperties([
                'label_url' => $labelUrl,
                'tracking_number' => $shipment->trackingNumber,
                'action' => 'attaching_label',
            ])
            ->log('shopify_label_attachment_attempt');

        // Step 3: Call the action to attach label to Shopify
        $action = app(AttachReturnLabelToShopifyAction::class);
        $action->execute($return, [
            'label_url' => $labelUrl,
            'tracking_number' => $shipment->trackingNumber,
            'tracking_url' => null, // BasitKargo doesn't provide tracking URL
            'notify_customer' => true,
        ]);
    }
}
