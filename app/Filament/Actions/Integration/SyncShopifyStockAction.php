<?php

namespace App\Filament\Actions\Integration;

use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class SyncShopifyStockAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sync_shopify_stock';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Sync Stock to Shopify'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('success')
            ->visible(function (ProductVariant $record): bool {
                return $record->platformMappings()
                    ->where('platform', OrderChannel::SHOPIFY->value)
                    ->exists();
            })
            ->requiresConfirmation()
            ->modalHeading(__('Sync Stock to Shopify'))
            ->modalDescription(function (ProductVariant $record): string {
                return __('Push current stock (:stock) for SKU :sku to Shopify. Shopify prices will be preserved.', [
                    'stock' => $record->inventory_quantity ?? 0,
                    'sku' => $record->sku,
                ]);
            })
            ->modalSubmitActionLabel(__('Sync Stock'))
            ->action(function (ProductVariant $record) {
                $integration = Integration::where('provider', 'shopify')
                    ->where('is_active', true)
                    ->first();

                if (! $integration) {
                    Notification::make()
                        ->title(__('No active Shopify integration found'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $adapter = new ShopifyAdapter($integration);
                    $result = $adapter->syncStock(collect([$record]));

                    if ($result['success'] && $result['synced'] > 0) {
                        Notification::make()
                            ->title(__('Stock synced to Shopify'))
                            ->body(__('Quantity :qty sent for SKU :sku', [
                                'qty' => $record->inventory_quantity ?? 0,
                                'sku' => $record->sku,
                            ]))
                            ->success()
                            ->send();
                    } else {
                        $errorMsg = implode('; ', $result['errors']);
                        Notification::make()
                            ->title(__('Stock sync failed'))
                            ->body($errorMsg ?: __('Variant was skipped — no Shopify mapping found.'))
                            ->danger()
                            ->send();
                    }
                } catch (\Exception $e) {
                    Notification::make()
                        ->title(__('Error syncing stock'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
