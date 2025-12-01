<?php

namespace App\Filament\Resources\Integration\Integrations\Pages;

use App\Filament\Resources\Integration\Integrations\IntegrationResource;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

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
