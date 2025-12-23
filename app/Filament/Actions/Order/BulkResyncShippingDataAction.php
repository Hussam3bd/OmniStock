<?php

namespace App\Filament\Actions\Order;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Integration\IntegrationType;
use App\Models\Integration\Integration;
use App\Services\Shipping\ShippingDataSyncService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;

class BulkResyncShippingDataAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'bulk_resync_shipping_data';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Resync Shipping Data'))
            ->icon('heroicon-o-truck')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Resync Shipping Data from BasitKargo'))
            ->modalDescription(__('This will fetch the latest shipping data for all selected orders with tracking numbers from BasitKargo. It will update costs, carrier info, desi, and automatically create returns if shipments were returned.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) {
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

                $service = app(ShippingDataSyncService::class);

                $successCount = 0;
                $failedCount = 0;
                $skippedCount = 0;
                $errors = [];

                foreach ($records as $order) {
                    if (! $order->shipping_tracking_number) {
                        $skippedCount++;

                        continue;
                    }

                    try {
                        $result = $service->syncShippingData($order, $integration, force: true);

                        if ($result['success']) {
                            $successCount++;
                        } else {
                            $failedCount++;
                            $errors[] = "#{$order->order_number}: ".($result['error'] ?? 'unknown');
                        }
                    } catch (\Exception $e) {
                        $failedCount++;
                        $errors[] = "#{$order->order_number}: ".$e->getMessage();
                    }
                }

                // Build notification message
                $title = __('Shipping Data Sync Complete');
                $messages = [];

                if ($successCount > 0) {
                    $messages[] = __(':count orders synced successfully', ['count' => $successCount]);
                }

                if ($skippedCount > 0) {
                    $messages[] = __(':count orders skipped (no tracking number)', ['count' => $skippedCount]);
                }

                if ($failedCount > 0) {
                    $messages[] = __(':count orders failed', ['count' => $failedCount]);
                }

                $body = implode('. ', $messages).'.';

                // Show errors if any
                if (! empty($errors) && count($errors) <= 5) {
                    $body .= "\n\n".__('Errors:')."\n".implode("\n", $errors);
                } elseif (! empty($errors)) {
                    $body .= "\n\n".__('Showing first 5 errors:')."\n".implode("\n", array_slice($errors, 0, 5));
                }

                Notification::make()
                    ->title($title)
                    ->body($body)
                    ->success($failedCount === 0)
                    ->warning($failedCount > 0 && $successCount > 0)
                    ->danger($failedCount > 0 && $successCount === 0)
                    ->send();
            });
    }
}
