<?php

namespace App\Filament\Resources\Accounting\TransactionCategoryMappings\TransactionCategoryMappings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransactionCategoryMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('priority')
            ->columns([
                TextColumn::make('priority')
                    ->label(__('Priority'))
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('pattern')
                    ->label(__('Pattern'))
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge(),

                TextColumn::make('category')
                    ->label(__('Category'))
                    ->formatStateUsing(function ($record) {
                        if (! $record->category) {
                            return '-';
                        }

                        $enum = $record->getCategoryEnumAttribute();

                        return $enum ? $enum->getLabel() : $record->category;
                    }),

                TextColumn::make('account.name')
                    ->label(__('Account'))
                    ->placeholder(__('All Accounts'))
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('Active'))
                    ->boolean()
                    ->sortable(),
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
            ]);
    }
}
