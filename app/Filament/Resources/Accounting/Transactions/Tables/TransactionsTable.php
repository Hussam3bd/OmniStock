<?php

namespace App\Filament\Resources\Accounting\Transactions\Tables;

use App\Enums\Accounting\ExpenseCategory;
use App\Enums\Accounting\IncomeCategory;
use App\Enums\Accounting\TransactionType;
use App\Models\Accounting\Transaction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('transaction_date')
                    ->label(__('Date'))
                    ->date()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('type')
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('category')
                    ->label(__('Category'))
                    ->badge()
                    ->formatStateUsing(function ($record, $state) {
                        if (! $state) {
                            return '-';
                        }

                        $enum = $record->category_enum;

                        return $enum?->getLabel() ?? $state;
                    })
                    ->color(function ($record, $state) {
                        if (! $state) {
                            return 'gray';
                        }

                        $enum = $record->category_enum;

                        return $enum?->getColor() ?? 'gray';
                    })
                    ->sortable()
                    ->searchable(),

                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->formatStateUsing(fn (Transaction $record) => $record->getDualCurrencyDisplay())
                    ->sortable(),

                TextColumn::make('account.name')
                    ->label(__('Account'))
                    ->badge()
                    ->sortable()
                    ->searchable(),

                TextColumn::make('description')
                    ->label(__('Description'))
                    ->limit(50)
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('transactionable.order_number')
                    ->label(__('Related'))
                    ->formatStateUsing(function ($record, $state) {
                        if (! $state) {
                            return '-';
                        }
                        $type = class_basename($record->transactionable_type);

                        return "{$type}: {$state}";
                    })
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('currency')
                    ->badge()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label(__('Created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('Type'))
                    ->options(TransactionType::class),

                SelectFilter::make('category')
                    ->label(__('Category'))
                    ->searchable()
                    ->options(function () {
                        $incomeOptions = collect(IncomeCategory::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()]);
                        $expenseOptions = collect(ExpenseCategory::cases())
                            ->mapWithKeys(fn ($case) => [$case->value => $case->getLabel()]);

                        return $incomeOptions->merge($expenseOptions)->toArray();
                    })
                    ->multiple(),

                SelectFilter::make('account_id')
                    ->label(__('Account'))
                    ->relationship('account', 'name')
                    ->searchable()
                    ->preload()
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
            ]);
    }
}
