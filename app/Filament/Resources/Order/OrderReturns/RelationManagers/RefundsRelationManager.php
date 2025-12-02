<?php

namespace App\Filament\Resources\Order\OrderReturns\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RefundsRelationManager extends RelationManager
{
    protected static string $relationship = 'refunds';

    protected static ?string $title = 'Refunds';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)
                    ->schema([
                        Hidden::make('currency')
                            ->default(fn ($livewire) => $livewire->getOwnerRecord()->currency),

                        TextInput::make('amount')
                            ->label(__('Refund Amount'))
                            ->required()
                            ->numeric()
                            ->prefix(fn ($livewire) => $livewire->getOwnerRecord()->currency)
                            ->helperText(__('Enter the amount in the smallest currency unit (e.g., cents)')),

                        Select::make('method')
                            ->label(__('Refund Method'))
                            ->options([
                                'bank_transfer' => 'Bank Transfer',
                                'credit_card' => 'Credit Card',
                                'debit_card' => 'Debit Card',
                                'cash' => 'Cash',
                                'store_credit' => 'Store Credit',
                                'original_payment_method' => 'Original Payment Method',
                            ])
                            ->required()
                            ->default('original_payment_method'),

                        TextInput::make('payment_gateway')
                            ->label(__('Payment Gateway'))
                            ->helperText(__('e.g., Stripe, PayPal, Iyzico'))
                            ->maxLength(255),

                        Hidden::make('status')
                            ->default('pending'),

                        Textarea::make('note')
                            ->label(__('Note'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('refund_number')
            ->columns([
                TextColumn::make('refund_number')
                    ->label(__('Refund #'))
                    ->searchable()
                    ->copyable()
                    ->sortable(),

                TextColumn::make('amount')
                    ->label(__('Amount'))
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'pending' => 'warning',
                        'processing' => 'info',
                        'completed' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn ($state) => match ($state) {
                        'pending' => 'heroicon-o-clock',
                        'processing' => 'heroicon-o-arrow-path',
                        'completed' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        default => null,
                    })
                    ->sortable(),

                TextColumn::make('method')
                    ->label(__('Method'))
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'bank_transfer' => 'Bank Transfer',
                        'credit_card' => 'Credit Card',
                        'debit_card' => 'Debit Card',
                        'cash' => 'Cash',
                        'store_credit' => 'Store Credit',
                        'original_payment_method' => 'Original Payment Method',
                        default => ucfirst(str_replace('_', ' ', $state)),
                    })
                    ->badge()
                    ->color('gray'),

                TextColumn::make('payment_gateway')
                    ->label(__('Gateway'))
                    ->placeholder(__('N/A'))
                    ->toggleable(),

                TextColumn::make('processedBy.name')
                    ->label(__('Processed By'))
                    ->placeholder(__('Not processed'))
                    ->icon('heroicon-o-user')
                    ->toggleable(),

                TextColumn::make('initiated_at')
                    ->label(__('Initiated'))
                    ->dateTime()
                    ->since()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('completed_at')
                    ->label(__('Completed'))
                    ->dateTime()
                    ->since()
                    ->placeholder(__('Not completed'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ])
                    ->multiple(),

                SelectFilter::make('method')
                    ->options([
                        'bank_transfer' => 'Bank Transfer',
                        'credit_card' => 'Credit Card',
                        'debit_card' => 'Debit Card',
                        'cash' => 'Cash',
                        'store_credit' => 'Store Credit',
                        'original_payment_method' => 'Original Payment Method',
                    ])
                    ->multiple(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data) {
                        $data['initiated_at'] = now();

                        return $data;
                    }),
            ])
            ->recordActions([
                Action::make('markAsProcessing')
                    ->label(__('Mark as Processing'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => $record->isPending())
                    ->action(function ($record) {
                        $record->markAsProcessing(auth()->user());
                    }),

                Action::make('markAsCompleted')
                    ->label(__('Mark as Completed'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn ($record) => in_array($record->status, ['pending', 'processing']))
                    ->action(function ($record) {
                        $record->markAsCompleted();
                    }),

                Action::make('markAsFailed')
                    ->label(__('Mark as Failed'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->form([
                        Textarea::make('failure_reason')
                            ->label(__('Failure Reason'))
                            ->required()
                            ->rows(3),
                    ])
                    ->visible(fn ($record) => in_array($record->status, ['pending', 'processing']))
                    ->action(function ($record, array $data) {
                        $record->markAsFailed($data['failure_reason']);
                    }),

                EditAction::make()
                    ->visible(fn ($record) => $record->isPending()),

                DeleteAction::make()
                    ->visible(fn ($record) => $record->isPending()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('initiated_at', 'desc');
    }
}
