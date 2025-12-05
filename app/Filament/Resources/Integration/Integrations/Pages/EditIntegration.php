<?php

namespace App\Filament\Resources\Integration\Integrations\Pages;

use App\Enums\Integration\IntegrationProvider;
use App\Filament\Resources\Integration\Integrations\IntegrationResource;
use App\Jobs\SyncPaymentFees;
use App\Models\Order\Order;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class EditIntegration extends EditRecord
{
    protected static string $resource = IntegrationResource::class;

    protected function getHeaderActions(): array
    {
        $actions = [
            DeleteAction::make(),
        ];

        // Add webhook action for sales channel integrations
        if (in_array($this->record->provider, ['trendyol', 'shopify']) && $this->record->is_active) {
            $actions[] = Action::make('register_webhook')
                ->label(isset($this->record->config['webhook'])
                    ? __('Update Webhook')
                    : __('Register Webhook'))
                ->icon(Heroicon::OutlinedSignal)
                ->color(isset($this->record->config['webhook']) ? 'info' : 'warning')
                ->requiresConfirmation()
                ->modalHeading(isset($this->record->config['webhook'])
                    ? __('Update :provider Webhook', ['provider' => ucfirst($this->record->provider)])
                    : __('Register :provider Webhook', ['provider' => ucfirst($this->record->provider)]))
                ->modalDescription(__('This will create/update webhooks to receive real-time updates.'))
                ->action(function () {
                    try {
                        $adapter = match ($this->record->provider) {
                            'trendyol' => new TrendyolAdapter($this->record),
                            'shopify' => new ShopifyAdapter($this->record),
                            default => null,
                        };

                        if (! $adapter) {
                            throw new \Exception('Unsupported provider');
                        }

                        if ($adapter->registerWebhooks()) {
                            $this->refreshFormData(['config']);

                            Notification::make()
                                ->title(__('Webhooks registered successfully'))
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('Failed to register webhooks'))
                                ->body(__('Please check your integration settings and try again.'))
                                ->danger()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('Error registering webhooks'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                });
        }

        // Add sync payment fees action for Iyzico integration
        if ($this->record->provider === IntegrationProvider::IYZICO && $this->record->is_active) {
            $actions[] = Action::make('sync_payment_fees')
                ->label(__('Sync Payment Fees'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading(__('Sync Payment Fees for All Orders'))
                ->modalDescription(function () {
                    $ordersCount = Order::where('payment_gateway', 'LIKE', '%iyzico%')
                        ->whereNotNull('payment_transaction_id')
                        ->count();

                    return __('This will sync payment gateway fees from Iyzico for :count orders with payment transaction IDs. The process will run in the background.', [
                        'count' => $ordersCount,
                    ]);
                })
                ->modalSubmitActionLabel(__('Start Sync'))
                ->action(function () {
                    try {
                        // Get all orders with Iyzico payment gateway
                        $orders = Order::where('payment_gateway', 'LIKE', '%iyzico%')
                            ->whereNotNull('payment_transaction_id')
                            ->get();

                        $totalOrders = count($orders);

                        if ($totalOrders === 0) {
                            Notification::make()
                                ->title(__('No orders found'))
                                ->body(__('No Iyzico orders with payment transaction IDs to sync'))
                                ->warning()
                                ->send();

                            return;
                        }

                        // Create jobs for each order
                        $jobs = $orders->map(function ($order) {
                            return new SyncPaymentFees($order);
                        })->toArray();

                        // Get current user for notifications
                        $user = auth()->user();

                        // Dispatch batch
                        Bus::batch($jobs)
                            ->name("Sync {$totalOrders} payment fees from Iyzico")
                            ->allowFailures()
                            ->onQueue('default')
                            ->then(function (Batch $batch) use ($user, $totalOrders) {
                                // All jobs completed successfully
                                Notification::make()
                                    ->title(__('Payment fees sync completed'))
                                    ->body(__('Successfully synced payment fees for all :count orders from Iyzico', [
                                        'count' => $totalOrders,
                                    ]))
                                    ->success()
                                    ->sendToDatabase($user);
                            })
                            ->catch(function (Batch $batch, \Throwable $e) use ($user) {
                                // First batch job failure
                                Notification::make()
                                    ->title(__('Payment fees sync error'))
                                    ->body(__('An error occurred during payment fees sync from Iyzico: :error', [
                                        'error' => $e->getMessage(),
                                    ]))
                                    ->danger()
                                    ->sendToDatabase($user);
                            })
                            ->finally(function (Batch $batch) use ($user, $totalOrders) {
                                // Always runs, even with failures
                                if ($batch->failedJobs > 0) {
                                    $successCount = $batch->totalJobs - $batch->failedJobs;

                                    Notification::make()
                                        ->title(__('Payment fees sync completed with errors'))
                                        ->body(__('Synced :success of :total orders from Iyzico. :failed failed.', [
                                            'success' => $successCount,
                                            'total' => $totalOrders,
                                            'failed' => $batch->failedJobs,
                                        ]))
                                        ->warning()
                                        ->sendToDatabase($user);
                                }
                            })
                            ->dispatch();

                        Notification::make()
                            ->title(__('Payment fees sync started'))
                            ->body(__('Syncing payment fees for :count orders from Iyzico in the background. You will be notified when complete.', [
                                'count' => $totalOrders,
                            ]))
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('Error starting payment fees sync'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                });
        }

        return $actions;
    }

    public function infolist(Infolist $infolist): Infolist
    {
        $components = [];

        // Show webhook info for sales channel integrations
        if (isset($this->record->config['webhook'])) {
            $webhook = $this->record->config['webhook'];

            if ($this->record->provider === 'trendyol') {
                $components[] = Section::make(__('Webhook Configuration'))
                    ->description(__('Webhook details for receiving real-time order updates from Trendyol.'))
                    ->schema([
                        TextEntry::make('webhook.id')
                            ->label(__('Webhook ID'))
                            ->state($webhook['id'] ?? null),

                        TextEntry::make('webhook.url')
                            ->label(__('Webhook URL'))
                            ->state($webhook['webhook_url'] ?? null)
                            ->copyable(),

                        TextEntry::make('webhook.status')
                            ->label(__('Status'))
                            ->state($webhook['active'] ?? true ? __('Active') : __('Inactive'))
                            ->badge()
                            ->color(($webhook['active'] ?? true) ? 'success' : 'gray'),

                        TextEntry::make('webhook.subscribed_statuses')
                            ->label(__('Subscribed Statuses'))
                            ->state(collect($webhook['subscribedStatuses'] ?? [])->implode(', '))
                            ->badge(),

                        TextEntry::make('webhook.created_at')
                            ->label(__('Created At'))
                            ->state($webhook['created_at'] ?? null)
                            ->dateTime(),
                    ])
                    ->columns(2)
                    ->collapsible();
            } elseif ($this->record->provider === 'shopify') {
                $webhooks = $webhook['webhooks'] ?? [];
                $topics = $webhook['topics'] ?? [];

                $components[] = Section::make(__('Webhook Configuration'))
                    ->description(__('Webhook details for receiving real-time updates from Shopify.'))
                    ->schema([
                        TextEntry::make('webhook.url')
                            ->label(__('Webhook URL'))
                            ->state($webhook['webhook_url'] ?? null)
                            ->copyable(),

                        TextEntry::make('webhook.topics')
                            ->label(__('Subscribed Topics'))
                            ->state(collect($topics)->implode(', '))
                            ->badge(),

                        TextEntry::make('webhook.count')
                            ->label(__('Webhooks Count'))
                            ->state(count($webhooks)),

                        TextEntry::make('webhook.created_at')
                            ->label(__('Created At'))
                            ->state($webhook['created_at'] ?? null)
                            ->dateTime(),
                    ])
                    ->columns(2)
                    ->collapsible();
            }
        }

        return $infolist->schema($components);
    }
}
