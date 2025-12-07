<?php

namespace App\Filament\Actions\Order;

use App\Models\Order\Order;
use App\Services\Shipping\ShippingCostSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ResyncShippingCostAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resync_shipping_cost';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resync Shipping Cost'))
            ->icon('heroicon-o-truck')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Shipping Cost from BasitKargo'))
            ->modalDescription(fn (Order $record) => __('This will fetch the latest shipping cost data for tracking number :number from BasitKargo and update the order.', [
                'number' => $record->shipping_tracking_number ?? 'N/A',
            ]))
            ->visible(fn (Order $record) => $record->shipping_tracking_number !== null)
            ->action(function (Order $record) {
                if (! $record->shipping_tracking_number) {
                    Notification::make()
                        ->warning()
                        ->title(__('No Tracking Number'))
                        ->body(__('This order does not have a shipping tracking number.'))
                        ->send();

                    return;
                }

                try {
                    $service = app(ShippingCostSyncService::class);
                    $synced = $service->syncShippingCostFromBasitKargo($record, force: true);

                    if ($synced) {
                        Notification::make()
                            ->success()
                            ->title(__('Shipping Cost Synced'))
                            ->body(__('Successfully synced shipping cost from BasitKargo for tracking number :number.', [
                                'number' => $record->shipping_tracking_number,
                            ]))
                            ->send();
                    } else {
                        Notification::make()
                            ->warning()
                            ->title(__('Sync Skipped'))
                            ->body(__('Shipping cost was not synced. This may be because the cost data is not available in BasitKargo or already exists.'))
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Sync Failed'))
                        ->body(__('Failed to sync shipping cost: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
