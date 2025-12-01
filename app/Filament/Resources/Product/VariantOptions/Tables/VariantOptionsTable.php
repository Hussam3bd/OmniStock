<?php

namespace App\Filament\Resources\Product\VariantOptions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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

                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->color(fn (?string $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (?string $state): string => $state ? __(ucfirst($state)) : __('Custom'))
                    ->sortable(),

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
                DeleteAction::make()
                    ->hidden(fn (Model $record): bool => $record->isSystemType()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->deselectRecordsAfterCompletion()
                        ->action(function ($records) {
                            $systemRecords = $records->filter(fn ($record) => $record->isSystemType());

                            if ($systemRecords->isNotEmpty()) {
                                \Filament\Notifications\Notification::make()
                                    ->title(__('Cannot delete system variant options'))
                                    ->body(__('Color and Size variant options cannot be deleted.'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $records->each->delete();

                            \Filament\Notifications\Notification::make()
                                ->title(__('Deleted successfully'))
                                ->success()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('position');
    }
}
