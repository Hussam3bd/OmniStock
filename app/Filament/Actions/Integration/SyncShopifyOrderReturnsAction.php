<?php

namespace App\Filament\Actions\Integration;

use App\Enums\Integration\IntegrationProvider;
use App\Jobs\SyncShopifyReturnRequests;
use App\Models\Integration\Integration;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class SyncShopifyOrderReturnsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sync_shopify_order_returns';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Sync Returns'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->visible(fn (Integration $record) => $record->provider === IntegrationProvider::SHOPIFY && $record->is_active)
            ->requiresConfirmation()
            ->modalHeading(__('Sync Shopify Order Returns'))
            ->modalDescription(__('This will fetch and sync return requests from Shopify orders. Only orders with return statuses will be fetched using optimized filtering. The process will run in the background.'))
            ->modalSubmitActionLabel(__('Start Sync'))
            ->action(function (Integration $record) {
                try {
                    // Dispatch single job that will handle all returns efficiently
                    // Pass current user ID for completion notifications
                    SyncShopifyReturnRequests::dispatch($record, auth()->id());

                    Notification::make()
                        ->title(__('Return sync started'))
                        ->body(__('Syncing return requests from Shopify in the background. You will be notified when complete.'))
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title(__('Error starting return sync'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
