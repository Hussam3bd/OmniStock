<?php

namespace App\Filament\Resources\ExchangeRates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExchangeRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fromCurrency.code')
                    ->label(__('From'))
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                TextColumn::make('toCurrency.code')
                    ->label(__('To'))
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('success'),

                TextColumn::make('rate')
                    ->label(__('Rate'))
                    ->numeric(decimalPlaces: 8)
                    ->sortable()
                    ->description(fn ($record) => "1 {$record->fromCurrency->code} = {$record->rate} {$record->toCurrency->code}"),

                TextColumn::make('effective_date')
                    ->label(__('Effective Date'))
                    ->date()
                    ->sortable()
                    ->description(fn ($record) => $record->effective_date->diffForHumans()),

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
            ->defaultSort('effective_date', 'desc')
            ->filters([
                SelectFilter::make('from_currency_id')
                    ->label(__('From Currency'))
                    ->relationship('fromCurrency', 'code')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('to_currency_id')
                    ->label(__('To Currency'))
                    ->relationship('toCurrency', 'code')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                // No edit action - rates are managed by API
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
