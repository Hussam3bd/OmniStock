<?php

namespace App\Filament\Actions\Order;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Shipping\ShippingDataSyncService;
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

        $this->label(__('Resync Shipping Data'))
            ->icon('heroicon-o-truck')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Shipping Data from BasitKargo'))
            ->modalDescription(fn (Order $record) => __('This will fetch the latest shipping data for tracking number :number from BasitKargo. It will update costs, carrier info, desi, and automatically create a return if the shipment was returned.', [
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

                // Get active BasitKargo integration
                $integration = Integration::where('type', IntegrationType::SHIPPING_PROVIDER)
                    ->where('provider', IntegrationProvider::BASIT_KARGO)
                    ->where('is_active', true)
                    ->first();

                if (! $integration) {
                    Notification::make()
                        ->danger()
                        ->title(__('Integration Not Found'))
                        ->body(__('No active BasitKargo integration found.'))
                        ->send();

                    return;
                }

                try {
                    $service = app(ShippingDataSyncService::class);
                    $result = $service->syncShippingData($record, $integration, force: true);

                    if ($result['success']) {
                        $return = $result['return'] ?? null;

                        $message = __('Successfully synced shipping data.');
                        if ($return) {
                            $message .= ' '.__('A return was automatically created.');
                        }

                        Notification::make()
                            ->success()
                            ->title(__('Shipping Data Synced'))
                            ->body($message)
                            ->send();
                    } else {
                        Notification::make()
                            ->warning()
                            ->title(__('Sync Failed'))
                            ->body(__('Failed to sync: :error', ['error' => $result['error'] ?? 'unknown']))
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Sync Failed'))
                        ->body(__('Failed to sync shipping data: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
