<?php

namespace App\Filament\Resources\Order\Orders\RelationManagers;

use App\Enums\Order\ReturnStatus;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReturnsRelationManager extends RelationManager
{
    protected static string $relationship = 'returns';

    protected static ?string $title = 'Returns';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('return_number')
            ->columns([
                TextColumn::make('return_number')
                    ->label(__('Return #'))
                    ->searchable()
                    ->copyable()
                    ->url(fn ($record) => route('filament.admin.resources.order.order-returns.view', ['record' => $record]))
                    ->description(fn ($record) => __('Click to view full details')),

                TextColumn::make('channel')
                    ->badge(),

                TextColumn::make('status')
                    ->badge(),

                TextColumn::make('reason_name')
                    ->label(__('Reason'))
                    ->limit(30)
                    ->searchable()
                    ->tooltip(fn ($record) => $record->reason_name),

                TextColumn::make('items_count')
                    ->label(__('Items'))
                    ->counts('items')
                    ->badge()
                    ->color('gray')
                    ->description(fn ($record) => $record->items->pluck('orderItem.productVariant.sku')->take(3)->join(', ')),

                TextColumn::make('total_refund_amount')
                    ->label(__('Refund'))
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->description(fn ($record) => __('Loss: :amount', ['amount' => $record->getTotalLoss()->formatByIntl()])),

                TextColumn::make('requested_at')
                    ->label(__('Requested'))
                    ->dateTime()
                    ->sortable()
                    ->since()
                    ->description(fn ($record) => $record->requested_at->format('M d, Y')),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ReturnStatus::class)
                    ->multiple(),
            ])
            ->defaultSort('requested_at', 'desc');
    }
}
