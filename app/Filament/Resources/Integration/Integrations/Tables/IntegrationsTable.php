<?php

namespace App\Filament\Resources\Integration\Integrations\Tables;

use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Trendyol\TrendyolAdapter;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IntegrationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sales_channel' => __('Sales Channel'),
                        'shipping_provider' => __('Shipping Provider'),
                        'payment_gateway' => __('Payment Gateway'),
                        'invoice_provider' => __('Invoice Provider'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sales_channel' => 'success',
                        'shipping_provider' => 'info',
                        'payment_gateway' => 'warning',
                        'invoice_provider' => 'primary',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('provider')
                    ->label(__('Provider'))
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),

                IconColumn::make('webhook_configured')
                    ->label(__('Webhook'))
                    ->boolean()
                    ->getStateUsing(function ($record) {
                        if ($record->provider !== 'trendyol') {
                            return null;
                        }

                        return isset($record->config['webhook']['id']);
                    })
                    ->tooltip(function ($record) {
                        if ($record->provider !== 'trendyol') {
                            return null;
                        }

                        if (isset($record->config['webhook']['id'])) {
                            return __('Webhook ID: :id', ['id' => $record->config['webhook']['id']]);
                        }

                        return __('No webhook configured');
                    }),

                TextColumn::make('created_at')
                    ->label(__('Created At'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label(__('Updated At'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('register_webhook')
                    ->label(fn (Integration $record) => isset($record->config['webhook']['id'])
                        ? __('Update Webhook')
                        : __('Register Webhook'))
                    ->icon(Heroicon::OutlinedSignal)
                    ->color(fn (Integration $record) => isset($record->config['webhook']['id'])
                        ? 'info'
                        : 'warning')
                    ->visible(fn (Integration $record) => $record->provider === 'trendyol' && $record->is_active)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Integration $record) => isset($record->config['webhook']['id'])
                        ? __('Update Trendyol Webhook')
                        : __('Register Trendyol Webhook'))
                    ->modalDescription(__('This will create/update a webhook at Trendyol to receive real-time order updates.'))
                    ->modalSubmitActionLabel(__('Proceed'))
                    ->action(function (Integration $record) {
                        try {
                            $adapter = new TrendyolAdapter($record);

                            if ($adapter->registerWebhooks()) {
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
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
