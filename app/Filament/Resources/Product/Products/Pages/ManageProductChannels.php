<?php

namespace App\Filament\Resources\Product\Products\Pages;

use App\Filament\Resources\Product\Products\ProductResource;
use App\Models\Product\ProductChannelAvailability;
use App\Models\Product\ProductVariant;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ManageProductChannels extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ProductResource::class;

    protected string $view = 'filament.pages.manage-product-channels';

    public static function getNavigationLabel(): string
    {
        return __('Channels');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ProductVariant::query()
                    ->where('product_id', $this->getRecord()->id)
                    ->with(['optionValues.variantOption', 'channelAvailability'])
            )
            ->columns([
                TextColumn::make('optionValues.value')
                    ->label(__('Variant'))
                    ->badge()
                    ->separator(' / ')
                    ->formatStateUsing(function ($state) {
                        if (is_array($state)) {
                            $locale = app()->getLocale();

                            return $state[$locale] ?? $state['en'] ?? $state['tr'] ?? '';
                        }

                        return $state;
                    }),

                TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable(),

                ToggleColumn::make('shopify_enabled')
                    ->label('Shopify')
                    ->getStateUsing(fn (ProductVariant $record) => $record->channelAvailability
                        ->where('channel', 'shopify')
                        ->first()?->is_enabled ?? false
                    )
                    ->updateStateUsing(function (ProductVariant $record, bool $state) {
                        ProductChannelAvailability::updateOrCreate(
                            [
                                'product_variant_id' => $record->id,
                                'channel' => 'shopify',
                            ],
                            [
                                'is_enabled' => $state,
                            ]
                        );
                    })
                    ->alignCenter(),

                ToggleColumn::make('trendyol_enabled')
                    ->label('Trendyol')
                    ->getStateUsing(fn (ProductVariant $record) => $record->channelAvailability
                        ->where('channel', 'trendyol')
                        ->first()?->is_enabled ?? false
                    )
                    ->updateStateUsing(function (ProductVariant $record, bool $state) {
                        ProductChannelAvailability::updateOrCreate(
                            [
                                'product_variant_id' => $record->id,
                                'channel' => 'trendyol',
                            ],
                            [
                                'is_enabled' => $state,
                            ]
                        );
                    })
                    ->alignCenter(),

                IconColumn::make('synced_to_shopify')
                    ->label(__('Synced'))
                    ->getStateUsing(function (ProductVariant $record) {
                        return $record->platformMappings()
                            ->where('platform', 'shopify')
                            ->exists();
                    })
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),

                IconColumn::make('synced_to_trendyol')
                    ->label(__('Synced'))
                    ->getStateUsing(function (ProductVariant $record) {
                        return $record->platformMappings()
                            ->where('platform', 'trendyol')
                            ->exists();
                    })
                    ->boolean()
                    ->alignCenter()
                    ->toggleable(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable_shopify')
                        ->label(__('Enable on Shopify'))
                        ->icon('heroicon-o-shopping-bag')
                        ->color('success')
                        ->action(function (Collection $records) {
                            foreach ($records as $variant) {
                                ProductChannelAvailability::updateOrCreate(
                                    [
                                        'product_variant_id' => $variant->id,
                                        'channel' => 'shopify',
                                    ],
                                    [
                                        'is_enabled' => true,
                                    ]
                                );
                            }

                            Notification::make()
                                ->title(__('Enabled on Shopify'))
                                ->body(__(':count variants enabled', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('disable_shopify')
                        ->label(__('Disable on Shopify'))
                        ->icon('heroicon-o-shopping-bag')
                        ->color('danger')
                        ->action(function (Collection $records) {
                            foreach ($records as $variant) {
                                ProductChannelAvailability::updateOrCreate(
                                    [
                                        'product_variant_id' => $variant->id,
                                        'channel' => 'shopify',
                                    ],
                                    [
                                        'is_enabled' => false,
                                    ]
                                );
                            }

                            Notification::make()
                                ->title(__('Disabled on Shopify'))
                                ->body(__(':count variants disabled', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('enable_trendyol')
                        ->label(__('Enable on Trendyol'))
                        ->icon('heroicon-o-building-storefront')
                        ->color('warning')
                        ->action(function (Collection $records) {
                            foreach ($records as $variant) {
                                ProductChannelAvailability::updateOrCreate(
                                    [
                                        'product_variant_id' => $variant->id,
                                        'channel' => 'trendyol',
                                    ],
                                    [
                                        'is_enabled' => true,
                                    ]
                                );
                            }

                            Notification::make()
                                ->title(__('Enabled on Trendyol'))
                                ->body(__(':count variants enabled', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('disable_trendyol')
                        ->label(__('Disable on Trendyol'))
                        ->icon('heroicon-o-building-storefront')
                        ->color('danger')
                        ->action(function (Collection $records) {
                            foreach ($records as $variant) {
                                ProductChannelAvailability::updateOrCreate(
                                    [
                                        'product_variant_id' => $variant->id,
                                        'channel' => 'trendyol',
                                    ],
                                    [
                                        'is_enabled' => false,
                                    ]
                                );
                            }

                            Notification::make()
                                ->title(__('Disabled on Trendyol'))
                                ->body(__(':count variants disabled', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->emptyStateHeading(__('No variants yet'))
            ->emptyStateDescription(__('Create variants first before managing channel availability'))
            ->defaultSort('sku', 'asc');
    }

    protected function getRecord(): mixed
    {
        return $this->getResource()::resolveRecordRouteBinding(
            request()->route('record')
        );
    }
}
