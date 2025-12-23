<?php

namespace App\Filament\Resources\Supplier\Suppliers\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier_info')
                    ->label(__('Supplier'))
                    ->searchable(['name', 'code'])
                    ->html()
                    ->getStateUsing(function ($record) {
                        $name = '<div class="font-medium">'.$record->name.'</div>';
                        $code = '<div class="font-mono text-sm text-gray-600 dark:text-gray-400">'.$record->code.'</div>';

                        return $name.$code;
                    })
                    ->wrap()
                    ->grow(),

                Tables\Columns\TextColumn::make('contact_person')
                    ->label(__('Contact Person'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('phone')
                    ->label(__('Phone'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('city')
                    ->label(__('City'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('country')
                    ->label(__('Country'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('purchase_orders_count')
                    ->label(__('Orders'))
                    ->counts('purchaseOrders')
                    ->badge()
                    ->color('primary')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_items_purchased')
                    ->label(__('Items Purchased'))
                    ->state(function ($record) {
                        return DB::table('purchase_order_items')
                            ->join('purchase_orders', 'purchase_order_items.purchase_order_id', '=', 'purchase_orders.id')
                            ->where('purchase_orders.supplier_id', $record->id)
                            ->sum('purchase_order_items.quantity_received');
                    })
                    ->numeric()
                    ->color('info')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('total_value_paid')
                    ->label(__('Total Paid'))
                    ->state(function ($record) {
                        $totals = DB::table('purchase_orders')
                            ->where('supplier_id', $record->id)
                            ->whereNotIn('status', ['draft', 'cancelled'])
                            ->select('currency_code', DB::raw('SUM(total) as total'))
                            ->groupBy('currency_code')
                            ->get();

                        return $totals->map(function ($item) {
                            return money($item->total, $item->currency_code)->format();
                        })->join(' + ');
                    })
                    ->color('success')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label(__('Active'))
                    ->placeholder(__('All suppliers'))
                    ->trueLabel(__('Active only'))
                    ->falseLabel(__('Inactive only')),
            ])
            ->recordActions([
                ViewAction::make(),
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
