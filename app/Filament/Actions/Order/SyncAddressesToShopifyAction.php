<?php

namespace App\Filament\Actions\Order;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class SyncAddressesToShopifyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sync_addresses_to_shopify';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Sync Addresses to Shopify'))
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Sync Addresses to Shopify'))
            ->modalDescription(__('This will update the shipping and billing addresses in Shopify to match the addresses in this system. Are you sure you want to proceed?'))
            ->visible(function () {
                // Get order from either page context or relation manager context
                $order = $this->getOrder();

                return $order && $order->channel === OrderChannel::SHOPIFY;
            })
            ->action(function () {
                // Get order from either page context or relation manager context
                $order = $this->getOrder();

                if (! $order) {
                    Notification::make()
                        ->danger()
                        ->title(__('Error'))
                        ->body(__('Could not find order.'))
                        ->send();

                    return;
                }

                $record = $order;
                try {
                    // Find Shopify integration
                    $integration = Integration::where('type', IntegrationType::SALES_CHANNEL)
                        ->where('provider', IntegrationProvider::SHOPIFY)
                        ->where('is_active', true)
                        ->first();

                    if (! $integration) {
                        Notification::make()
                            ->danger()
                            ->title(__('No Shopify Integration Found'))
                            ->body(__('Please configure and activate a Shopify integration first.'))
                            ->send();

                        return;
                    }

                    $adapter = $integration->getAdapter();
                    $success = $adapter->updateOrderAddresses($record);

                    if ($success) {
                        Notification::make()
                            ->success()
                            ->title(__('Addresses Synced Successfully'))
                            ->body(__('The shipping and billing addresses have been updated in Shopify.'))
                            ->send();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title(__('Sync Failed'))
                            ->body(__('Failed to update addresses in Shopify. Check the activity log for details.'))
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Sync Error'))
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }

    /**
     * Get the order from either page context or relation manager context
     */
    protected function getOrder(): ?Order
    {
        $livewire = $this->getLivewire();

        // If used in a relation manager, get the owner record
        if (method_exists($livewire, 'getOwnerRecord')) {
            return $livewire->getOwnerRecord();
        }

        // If used in a page, get the record
        if (property_exists($livewire, 'record')) {
            return $livewire->record;
        }

        return null;
    }
}
