<?php

namespace App\Filament\Actions\Order;

use App\Models\Order\Order;
use App\Services\Order\OrderSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ResyncOrderAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resync';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resync'))
            ->icon('heroicon-o-arrow-path')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Order from Channel'))
            ->modalDescription(fn (Order $record) => __('This will fetch the latest data for order :number from :channel and update it.', [
                'number' => $record->order_number,
                'channel' => $record->channel->getLabel(),
            ]))
            ->visible(fn (Order $record) => $record->isExternal())
            ->action(function (Order $record) {
                $service = app(OrderSyncService::class);
                $result = $service->syncFulfillmentData($record);

                if (! $result['success']) {
                    $error = match ($result['error'] ?? 'unknown') {
                        'order_not_external' => __('This order is not from an external channel.'),
                        'no_platform_mapping' => __('No platform mapping found for this order.'),
                        'no_active_integration' => __('No active integration found for :channel.', ['channel' => $record->channel->getLabel()]),
                        default => $result['error'] ?? __('Unknown error occurred'),
                    };

                    Notification::make()
                        ->danger()
                        ->title(__('Resync Failed'))
                        ->body($error)
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Order Resynced'))
                    ->body(__('Order :number has been successfully resynced from :channel.', [
                        'number' => $record->order_number,
                        'channel' => $record->channel->getLabel(),
                    ]))
                    ->send();
            });
    }
}
