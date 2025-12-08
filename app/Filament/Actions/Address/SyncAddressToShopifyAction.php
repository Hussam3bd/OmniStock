<?php

namespace App\Filament\Actions\Address;

use App\Enums\Order\OrderChannel;
use App\Jobs\SyncAddressToShopify;
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
                    // Dispatch job to queue for async processing
                    SyncAddressToShopify::dispatch($order);

                    Notification::make()
                        ->success()
                        ->title('Sync Queued')
                        ->body('The address sync has been queued. It will be processed shortly.')
                        ->send();

                    activity()
                        ->performedOn($order)
                        ->log('shopify_address_sync_queued');
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title('Queue Error')
                        ->body($e->getMessage())
                        ->send();
                }
            });
    }
}
