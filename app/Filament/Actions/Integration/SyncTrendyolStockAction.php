<?php

namespace App\Filament\Actions\Integration;

use App\Enums\Order\OrderChannel;
use App\Models\Integration\Integration;
use App\Models\Product\ProductVariant;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class SyncTrendyolStockAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'sync_trendyol_stock';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Sync Stock to Trendyol'))
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('warning')
            ->visible(function (ProductVariant $record): bool {
                return $record->platformMappings
                    ->where('platform', OrderChannel::TRENDYOL->value)
                    ->whereNotNull('platform_data')
                    ->contains(fn ($m) => isset($m->platform_data['barcode']));
            })
            ->requiresConfirmation()
            ->modalHeading(__('Sync Stock to Trendyol'))
            ->modalDescription(function (ProductVariant $record): string {
                return __('Push current stock (:stock) for SKU :sku to Trendyol. Trendyol prices will be preserved.', [
                    'stock' => $record->inventory_quantity ?? 0,
                    'sku' => $record->sku,
                ]);
            })
            ->modalSubmitActionLabel(__('Sync Stock'))
            ->action(function (ProductVariant $record) {
                $integration = Integration::where('provider', 'trendyol')
                    ->where('is_active', true)
                    ->first();

                if (! $integration) {
                    Notification::make()
                        ->title(__('No active Trendyol integration found'))
                        ->danger()
                        ->send();

                    return;
                }

                try {
                    $adapter = new TrendyolAdapter($integration);
                    $result = $adapter->syncStock(collect([$record]));

                    if ($result['success'] && $result['synced'] > 0) {
                        Notification::make()
                            ->title(__('Stock synced to Trendyol'))
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
                            ->body($errorMsg ?: __('Variant was skipped — no Trendyol mapping or prices found.'))
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
