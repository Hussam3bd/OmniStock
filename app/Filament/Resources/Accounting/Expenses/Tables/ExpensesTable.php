<?php

namespace App\Filament\Resources\Accounting\Expenses\Tables;

use App\Enums\Accounting\ExpenseCategory;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->formatStateUsing(function ($state) {
                        $enum = ExpenseCategory::tryFrom($state);

                        return $enum?->getLabel() ?? $state;
                    })
                    ->color(function ($state) {
                        $enum = ExpenseCategory::tryFrom($state);

                        return $enum?->getColor() ?? 'gray';
                    })
                    ->sortable()
                    ->searchable(),

                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->money(fn ($record) => $record->currency ?? 'TRY')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('account.name')
                    ->label(__('Paid From'))
                    ->sortable()
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('purchaseOrder.order_number')
                    ->label(__('PO #'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->options(ExpenseCategory::class)
                    ->multiple(),

                SelectFilter::make('account_id')
                    ->label(__('Account'))
                    ->relationship('account', 'name')
                    ->multiple(),
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
            ->defaultSort('transaction_date', 'desc');
    }
}
