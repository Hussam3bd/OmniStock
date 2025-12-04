<?php

namespace App\Filament\Actions\Order;

use App\Enums\Order\PaymentGateway;
use App\Models\Order\Order;
use App\Services\Payment\PaymentCostSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ResyncPaymentCostAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resync_payment_cost';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resync Payment Cost'))
            ->icon('heroicon-o-banknotes')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Payment Gateway Cost'))
            ->modalDescription(fn (Order $record) => __('This will fetch the latest payment cost and fee data from :gateway for transaction :id.', [
                'gateway' => $record->payment_gateway ?? 'N/A',
                'id' => $record->payment_transaction_id ?? 'N/A',
            ]))
            ->visible(function (Order $record) {
                // Only visible if order has payment transaction ID
                if (! $record->payment_transaction_id) {
                    return false;
                }

                // Only visible for supported payment gateways
                $gateway = PaymentGateway::parse($record->payment_gateway);

                return $gateway && $gateway->supportsAutomatedSync();
            })
            ->action(function (Order $record) {
                if (! $record->payment_transaction_id) {
                    Notification::make()
                        ->warning()
                        ->title(__('No Transaction ID'))
                        ->body(__('This order does not have a payment transaction ID.'))
                        ->send();

                    return;
                }

                try {
                    $service = app(PaymentCostSyncService::class);
                    $synced = $service->syncPaymentCosts($record);

                    if ($synced) {
                        Notification::make()
                            ->success()
                            ->title(__('Payment Cost Synced'))
                            ->body(__('Successfully synced payment gateway costs from :gateway.', [
                                'gateway' => $record->payment_gateway,
                            ]))
                            ->send();

                        // Refresh the page to show updated data
                        $this->redirect(request()->url());
                    } else {
                        Notification::make()
                            ->warning()
                            ->title(__('Sync Skipped'))
                            ->body(__('Payment cost was not synced. The payment gateway may not support automated syncing.'))
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Sync Failed'))
                        ->body(__('Failed to sync payment cost: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
