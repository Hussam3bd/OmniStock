<?php

namespace App\Filament\Resources\Address\Addresses\Tables;

use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AddressesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('full_name')
                    ->label('Name')
                    ->getStateUsing(fn($record) => $record->full_name)
                    ->searchable(['first_name', 'last_name', 'company_name']),

                TextColumn::make('phone')
                    ->searchable(),

                TextColumn::make('province.name')
                    ->label('Province')
                    ->getStateUsing(fn($record) => $record->province?->getTranslation('name', app()->getLocale()))
                    ->sortable(),

                TextColumn::make('district.name')
                    ->label('District')
                    ->getStateUsing(fn($record) => $record->district?->getTranslation('name', app()->getLocale()))
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),

                IconColumn::make('is_shipping')
                    ->label('Shipping')
                    ->boolean(),

                IconColumn::make('is_billing')
                    ->label('Billing')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
            ]);
    }
}
