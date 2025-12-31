<?php

namespace App\Filament\Actions\Order;

use App\Enums\Order\OrderChannel;
use App\Models\Order\Order;
use App\Services\Integrations\AdapterFactory;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class ResyncPaymentTransactionIdAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'resync_payment_transaction_id';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resync Payment Transaction ID'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Payment Transaction ID'))
            ->modalDescription(__('This will fetch the latest payment transaction ID from Shopify and automatically sync payment costs if a transaction ID is found.'))
            ->visible(function (Order $record) {
                // Only visible for Shopify orders without transaction ID
                return $record->channel === OrderChannel::SHOPIFY &&
                       $record->isExternal() &&
                       ! $record->payment_transaction_id;
            })
            ->action(function (Order $record) {
                if (! $record->integration) {
                    Notification::make()
                        ->warning()
                        ->title(__('No Integration'))
                        ->body(__('This order does not have an integration.'))
                        ->send();

                    return;
                }

                try {
                    /** @var ShopifyAdapter $adapter */
                    $adapter = AdapterFactory::make($record->integration);

                    // Get Shopify order ID from platform mapping
                    $shopifyOrderId = $record->platformMappings()
                        ->where('platform', OrderChannel::SHOPIFY->value)
                        ->value('platform_id');

                    if (! $shopifyOrderId) {
                        throw new \Exception('Could not find Shopify order ID in platform mappings');
                    }

                    // Fetch order with transactions
                    $shopifyOrder = $adapter->fetchOrderWithTransactions($shopifyOrderId);

                    if (! $shopifyOrder) {
                        throw new \Exception('Could not fetch order from Shopify');
                    }

                    // Extract payment transaction ID
                    $transactionId = $this->extractPaymentTransactionId($shopifyOrder);

                    if (! $transactionId) {
                        Notification::make()
                            ->warning()
                            ->title(__('No Transaction Found'))
                            ->body(__('No successful payment transaction found for this order in Shopify.'))
                            ->send();

                        return;
                    }

                    // Update order with transaction ID
                    $record->update([
                        'payment_transaction_id' => $transactionId,
                    ]);

                    // Note: OrderObserver will automatically trigger payment cost sync
                    // when payment_transaction_id is updated

                    Notification::make()
                        ->success()
                        ->title(__('Transaction ID Synced'))
                        ->body(__('Successfully synced payment transaction ID: :id. Payment costs will be synced automatically.', [
                            'id' => $transactionId,
                        ]))
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Sync Failed'))
                        ->body(__('Failed to sync payment transaction ID: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }

    protected function extractPaymentTransactionId(array $shopifyOrder): ?string
    {
        if (! isset($shopifyOrder['transactions']) || ! is_array($shopifyOrder['transactions'])) {
            return null;
        }

        foreach ($shopifyOrder['transactions'] as $transaction) {
            if (($transaction['status'] ?? '') === 'success') {
                // Try authorization first, then payment_id, then transaction id
                $transactionId = $transaction['authorization']
                    ?? $transaction['payment_id']
                    ?? $transaction['id']
                    ?? null;

                if ($transactionId) {
                    return (string) $transactionId;
                }
            }
        }

        return null;
    }
}
