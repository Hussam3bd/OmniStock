<?php

namespace App\Filament\Resources\Product\VariantOptions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantOptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Option Name'))
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => __($state)),

                TextColumn::make('values_count')
                    ->label(__('Values'))
                    ->counts('values')
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => __(':count values', ['count' => $state])),

                TextColumn::make('position')
                    ->label(__('Order'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('position');
    }
}
