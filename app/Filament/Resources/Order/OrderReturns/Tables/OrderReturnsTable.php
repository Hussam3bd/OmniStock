<?php

namespace App\Filament\Resources\Order\OrderReturns\Tables;

use App\Enums\Order\ReturnStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrderReturnsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('return_number')
                    ->label('Return #')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('order.order_number')
                    ->label('Order #')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->order ? route('filament.admin.resources.order.orders.view', ['record' => $record->order]) : null),

                TextColumn::make('order.customer.full_name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('platform')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('reason_name')
                    ->label('Reason')
                    ->limit(30)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('total_refund_amount')
                    ->label('Refund')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('return_shipping_cost')
                    ->label('Return Shipping')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('requested_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable()
                    ->since(),

                TextColumn::make('approvedBy.name')
                    ->label('Approved By')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ReturnStatus::class)
                    ->multiple(),

                SelectFilter::make('platform')
                    ->options([
                        'trendyol' => 'Trendyol',
                        'shopify' => 'Shopify',
                        'internal' => 'Internal',
                    ])
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
