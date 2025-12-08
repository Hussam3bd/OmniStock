<?php

namespace App\Filament\Actions\Address;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class SyncAddressToShopifyAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sync_address_to_shopify';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Sync to Shopify')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Sync Address to Shopify')
            ->modalDescription('This will update this address in Shopify. Are you sure you want to proceed?')
            ->visible(function ($livewire) {
                // Only show if the owner is an Order and from Shopify channel
                if (! method_exists($livewire, 'getOwnerRecord')) {
                    return false;
                }

                $order = $livewire->getOwnerRecord();

                return $order && $order->channel === OrderChannel::SHOPIFY;
            })
            ->action(function ($livewire) {
                // Get the order
                $order = $livewire->getOwnerRecord();

                if (! $order) {
                    Notification::make()
                        ->danger()
                        ->title('Error')
                        ->body('Could not find order.')
                        ->send();

                    return;
                }

                try {
                    // Find Shopify integration
                    $integration = Integration::where('type', IntegrationType::SALES_CHANNEL)
                        ->where('provider', IntegrationProvider::SHOPIFY)
                        ->where('is_active', true)
                        ->first();

                    if (! $integration) {
                        Notification::make()
                            ->danger()
                            ->title('No Shopify Integration Found')
                            ->body('Please configure and activate a Shopify integration first.')
                            ->send();

                        return;
                    }

                    $adapter = $integration->adapter();
                    $success = $adapter->updateOrderAddresses($order);

                    if ($success) {
                        Notification::make()
                            ->success()
                            ->title('Address Synced Successfully')
                            ->body('The address has been updated in Shopify.')
                            ->send();
                    } else {
                        Notification::make()
                            ->danger()
                            ->title('Sync Failed')
                            ->body('Failed to update address in Shopify. Check the activity log for details.')
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title('Sync Error')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
