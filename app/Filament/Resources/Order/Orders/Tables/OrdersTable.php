<?php

namespace App\Filament\Resources\Order\Orders\Tables;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use App\Enums\Shipping\ShippingCarrier;
use App\Filament\Resources\Customer\Customers\CustomerResource;
use App\Models\Integration\Integration;
use App\Services\Integrations\SalesChannels\Shopify\Mappers\OrderMapper as ShopifyOrderMapper;
use App\Services\Integrations\SalesChannels\Shopify\ShopifyAdapter;
use App\Services\Integrations\SalesChannels\Trendyol\Mappers\OrderMapper as TrendyolOrderMapper;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('order_date', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order #')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('customer.full_name')
                    ->label('Customer')
                    ->searchable(query: function ($query, $search) {
                        return $query->whereHas('customer', function ($query) use ($search) {
                            $query->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                    })
                    ->url(fn ($record) => $record->customer
                        ? CustomerResource::getUrl('edit', ['record' => $record->customer])
                        : null)
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge()
                    ->searchable(),
                TextColumn::make('order_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payment_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('payment_gateway')
                    ->label('Payment Method')
                    ->formatStateUsing(function ($record) {
                        $parts = [];
                        if ($record->payment_method) {
                            $parts[] = match ($record->payment_method) {
                                'cod' => 'COD',
                                'bank_transfer' => 'Bank Transfer',
                                'online' => 'Online',
                                default => ucfirst($record->payment_method),
                            };
                        }
                        if ($record->payment_gateway) {
                            $parts[] = '('.ucfirst($record->payment_gateway).')';
                        }

                        return ! empty($parts) ? implode(' ', $parts) : '-';
                    })
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('fulfillment_status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('subtotal')
                    ->label('Subtotal')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('discount_amount')
                    ->label('Discount')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('tax_amount')
                    ->label('Tax')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_carrier')
                    ->label('Shipping Carrier')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_cost_excluding_vat')
                    ->label('Shipping (excl. VAT)')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_vat_amount')
                    ->label('Shipping VAT')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('shipping_amount')
                    ->label('Shipping Fee (Customer)')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable()
                    ->tooltip('Amount charged to customer (₺0 for Trendyol)'),
                TextColumn::make('total_commission')
                    ->label('Commission')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                TextColumn::make('gross_profit')
                    ->label('Gross Profit')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->color(fn ($record) => match (true) {
                        ! $record->gross_profit => 'gray',
                        $record->gross_profit->getAmount() > 0 => 'success',
                        $record->gross_profit->getAmount() < 0 => 'danger',
                        default => 'gray',
                    })
                    ->tooltip('Revenue - Product Cost - Shipping Cost - Commission'),
                TextColumn::make('order_date')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label(__('Channel'))
                    ->options(OrderChannel::class)
                    ->multiple(),

                SelectFilter::make('order_status')
                    ->label(__('Order Status'))
                    ->options(OrderStatus::class)
                    ->multiple(),

                SelectFilter::make('payment_status')
                    ->label(__('Payment Status'))
                    ->options(PaymentStatus::class)
                    ->multiple(),

                SelectFilter::make('fulfillment_status')
                    ->label(__('Fulfillment Status'))
                    ->options(FulfillmentStatus::class)
                    ->multiple(),

                SelectFilter::make('payment_method')
                    ->label(__('Payment Method'))
                    ->options([
                        'cod' => __('Cash on Delivery'),
                        'bank_transfer' => __('Bank Transfer'),
                        'online' => __('Online Payment'),
                    ])
                    ->multiple(),

                SelectFilter::make('shipping_carrier')
                    ->label(__('Shipping Carrier'))
                    ->options(ShippingCarrier::class)
                    ->multiple(),

                TernaryFilter::make('has_returns')
                    ->label(__('Has Returns'))
                    ->placeholder(__('All orders'))
                    ->trueLabel(__('With returns'))
                    ->falseLabel(__('Without returns'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('return_status')->where('return_status', '!=', 'none'),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('return_status')->orWhere('return_status', 'none')),
                    ),

                Filter::make('profitable')
                    ->label(__('Profitable Orders'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('items', function ($q) {
                        $q->whereNotNull('unit_cost');
                    })->whereRaw('total_amount > (COALESCE(total_product_cost, 0) + COALESCE(shipping_cost_excluding_vat, 0) + COALESCE(shipping_vat_amount, 0) + COALESCE(total_commission, 0))')),

                Filter::make('loss_making')
                    ->label(__('Loss-Making Orders'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereHas('items', function ($q) {
                        $q->whereNotNull('unit_cost');
                    })->whereRaw('total_amount < (COALESCE(total_product_cost, 0) + COALESCE(shipping_cost_excluding_vat, 0) + COALESCE(shipping_vat_amount, 0) + COALESCE(total_commission, 0))')),

                Filter::make('order_date')
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('From'))
                            ->placeholder(__('Select start date')),
                        DatePicker::make('until')
                            ->label(__('Until'))
                            ->placeholder(__('Select end date')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('order_date', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('order_date', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];

                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('Order date from '.Carbon::parse($data['from'])->toFormattedDateString())
                                ->removeField('from');
                        }

                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('Order date until '.Carbon::parse($data['until'])->toFormattedDateString())
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn ($record) => $record->isExternal()),
                EditAction::make()
                    ->visible(fn ($record) => ! $record->isExternal()),
                Action::make('resync')
                    ->label('Resync')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Resync Order from Channel')
                    ->modalDescription(fn ($record) => "This will fetch the latest data for order {$record->order_number} from {$record->channel->getLabel()} and update it.")
                    ->visible(fn ($record) => $record->isExternal())
                    ->action(function ($record) {
                        try {
                            // Get platform mapping to find the platform order ID
                            $mapping = $record->platformMappings()->first();

                            if (! $mapping) {
                                Notification::make()
                                    ->danger()
                                    ->title('Resync Failed')
                                    ->body('No platform mapping found for this order.')
                                    ->send();

                                return;
                            }

                            // Get the integration for this channel
                            $integration = Integration::where('provider', $record->channel->value)
                                ->where('is_active', true)
                                ->first();

                            if (! $integration) {
                                Notification::make()
                                    ->danger()
                                    ->title('Resync Failed')
                                    ->body("No active integration found for {$record->channel->getLabel()}.")
                                    ->send();

                                return;
                            }

                            // Fetch and sync order based on channel
                            match ($record->channel) {
                                OrderChannel::SHOPIFY => static::resyncShopifyOrder($record, $mapping, $integration),
                                OrderChannel::TRENDYOL => static::resyncTrendyolOrder($record, $mapping, $integration),
                                default => throw new \Exception('Channel not supported for resync'),
                            };

                            Notification::make()
                                ->success()
                                ->title('Order Resynced')
                                ->body("Order {$record->order_number} has been successfully resynced from {$record->channel->getLabel()}.")
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->danger()
                                ->title('Resync Failed')
                                ->body($e->getMessage())
                                ->send();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function resyncShopifyOrder($record, $mapping, Integration $integration): void
    {
        $adapter = new ShopifyAdapter($integration);
        $mapper = app(ShopifyOrderMapper::class);

        // Fetch order with transactions from Shopify
        $orderData = $adapter->fetchOrderWithTransactions($mapping->platform_id);

        if (! $orderData) {
            throw new \Exception('Could not fetch order from Shopify.');
        }

        // Map/sync the order
        $mapper->mapOrder($orderData);
    }

    protected static function resyncTrendyolOrder($record, $mapping, Integration $integration): void
    {
        $mapper = app(TrendyolOrderMapper::class);

        // For Trendyol, use the stored platform_data from the mapping
        // The Trendyol API doesn't have a direct endpoint to fetch a single package by ID
        // So we'll use the cached data which is sufficient for resyncing costs and calculations
        $packageData = $mapping->platform_data;

        if (! $packageData) {
            throw new \Exception('No platform data available for this Trendyol order.');
        }

        // Map/sync the order - this will recalculate costs, profits, etc.
        $mapper->mapOrder($packageData);
    }
}
