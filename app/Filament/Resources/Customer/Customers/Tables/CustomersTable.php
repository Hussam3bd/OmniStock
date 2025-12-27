<?php

namespace App\Filament\Resources\Customer\Customers\Tables;

use App\Actions\Customer\MergeCustomersAction;
use App\Enums\Order\OrderChannel;
use App\Models\Customer\Customer;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('channel')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('first_name')
                    ->searchable(),
                TextColumn::make('last_name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email address')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('city')
                    ->searchable(),
                TextColumn::make('state')
                    ->searchable(),
                TextColumn::make('postal_code')
                    ->searchable(),
                TextColumn::make('country')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->options(OrderChannel::class)
                    ->multiple(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('merge')
                        ->label('Merge Customers')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->form(function (BulkAction $action) {
                            $records = $action->getRecords();

                            return [
                                Select::make('primary_customer_id')
                                    ->label('Keep which customer?')
                                    ->helperText('All other selected customers will be merged into this one.')
                                    ->options($records->mapWithKeys(fn (Customer $customer) => [
                                        $customer->id => sprintf(
                                            '#%d - %s %s (%s) - %d orders',
                                            $customer->id,
                                            $customer->first_name,
                                            $customer->last_name,
                                            $customer->email ?? 'No email',
                                            $customer->orders()->count()
                                        ),
                                    ]))
                                    ->required()
                                    ->searchable(),
                            ];
                        })
                        ->action(function (Collection $records, array $data) {
                            $mergeAction = new MergeCustomersAction;

                            $primaryCustomer = $records->firstWhere('id', $data['primary_customer_id']);
                            $duplicates = $records->reject(fn ($customer) => $customer->id === $data['primary_customer_id'])->all();

                            if (! $primaryCustomer) {
                                Notification::make()
                                    ->danger()
                                    ->title('Error')
                                    ->body('Primary customer not found.')
                                    ->send();

                                return;
                            }

                            if (empty($duplicates)) {
                                Notification::make()
                                    ->warning()
                                    ->title('No customers to merge')
                                    ->body('Please select at least 2 customers to merge.')
                                    ->send();

                                return;
                            }

                            try {
                                $result = $mergeAction->execute($primaryCustomer, $duplicates);

                                Notification::make()
                                    ->success()
                                    ->title('Customers merged successfully')
                                    ->body(sprintf(
                                        'Merged %d customer(s) into #%d - %s %s',
                                        count($duplicates),
                                        $result->id,
                                        $result->first_name,
                                        $result->last_name
                                    ))
                                    ->send();
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->danger()
                                    ->title('Error merging customers')
                                    ->body($e->getMessage())
                                    ->send();
                            }
                        }),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
