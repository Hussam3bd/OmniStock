<?php

namespace App\Filament\Resources\Currencies\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CurrenciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('symbol')
                    ->label(__('Symbol'))
                    ->searchable(),

                TextColumn::make('decimal_places')
                    ->label(__('Decimal Places'))
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),

                IconColumn::make('is_default')
                    ->label(__('Default'))
                    ->boolean()
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),

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
            ->defaultSort('code')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active'))
                    ->placeholder(__('All currencies'))
                    ->trueLabel(__('Active only'))
                    ->falseLabel(__('Inactive only')),

                TernaryFilter::make('is_default')
                    ->label(__('Default'))
                    ->placeholder(__('All currencies'))
                    ->trueLabel(__('Default only'))
                    ->falseLabel(__('Non-default only')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
