<?php

namespace App\Filament\Resources\Order\Orders\Tables;

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
                TextColumn::make('total_amount')
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
