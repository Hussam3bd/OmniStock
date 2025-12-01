<?php

namespace App\Filament\Resources\Integration\Integrations\Pages;

use App\Filament\Resources\Integration\Integrations\IntegrationResource;
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

        // Add webhook action for Trendyol integrations
        if ($this->record->provider === 'trendyol' && $this->record->is_active) {
            $actions[] = Action::make('register_webhook')
                ->label(isset($this->record->config['webhook']['id'])
                    ? __('Update Webhook')
                    : __('Register Webhook'))
                ->icon(Heroicon::OutlinedSignal)
                ->color(isset($this->record->config['webhook']['id']) ? 'info' : 'warning')
                ->requiresConfirmation()
                ->modalHeading(isset($this->record->config['webhook']['id'])
                    ? __('Update Trendyol Webhook')
                    : __('Register Trendyol Webhook'))
                ->modalDescription(__('This will create/update a webhook at Trendyol to receive real-time order updates.'))
                ->action(function () {
                    try {
                        $adapter = new TrendyolAdapter($this->record);

                        if ($adapter->registerWebhooks()) {
                            $this->refreshFormData(['config']);

                            Notification::make()
                                ->title(__('Webhook registered successfully'))
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title(__('Failed to register webhook'))
                                ->body(__('Please check your integration settings and try again.'))
                                ->danger()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title(__('Error registering webhook'))
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

        // Show webhook info for Trendyol integrations
        if ($this->record->provider === 'trendyol' && isset($this->record->config['webhook'])) {
            $webhook = $this->record->config['webhook'];

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
        }

        return $infolist->schema($components);
    }
}
