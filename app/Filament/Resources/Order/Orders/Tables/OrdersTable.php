<?php

namespace App\Filament\Resources\Order\Orders\Tables;

use App\Filament\Resources\Customer\Customers\CustomerResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                    ->label('Shipping Total')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('total_commission')
                    ->label('Commission')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
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
                //
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn ($record) => $record->isExternal()),
                EditAction::make()
                    ->visible(fn ($record) => ! $record->isExternal()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
