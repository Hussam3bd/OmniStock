<?php

namespace App\Filament\Widgets\Accounting;

use App\Models\Accounting\Account;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class AccountBalancesWidget extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Account::query()->with('currency'))
            ->heading(__('Account Balances'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Account'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('balance')
                    ->label(__('Balance'))
                    ->formatStateUsing(fn (Account $record) => $record->balance->format())
                    ->sortable()
                    ->color(fn (Account $record) => $record->balance->isPositive() ? 'success' : 'danger')
                    ->weight('bold'),

                TextColumn::make('currency.name')
                    ->label(__('Currency'))
                    ->badge(),

                TextColumn::make('transactions_count')
                    ->counts('transactions')
                    ->label(__('Transactions'))
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->paginated(false);
    }
}
