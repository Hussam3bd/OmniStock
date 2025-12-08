<?php

namespace App\Filament\Resources\Address\Addresses\Tables;

use App\Filament\Actions\Address\SyncAddressToShopifyAction;
use App\Services\Address\TurkishAddressParser;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
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
                    ->getStateUsing(fn ($record) => $record->full_name)
                    ->searchable(['first_name', 'last_name', 'company_name']),

                TextColumn::make('phone')
                    ->searchable(),

                TextColumn::make('province.name')
                    ->label('Province')
                    ->getStateUsing(fn ($record) => $record->province?->getTranslation('name', app()->getLocale()))
                    ->sortable(),

                TextColumn::make('district.name')
                    ->label('District')
                    ->getStateUsing(fn ($record) => $record->district?->getTranslation('name', app()->getLocale()))
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
                Action::make('auto_correct')
                    ->label('Auto-Correct')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->action(function ($record) {
                        $parser = new TurkishAddressParser;

                        // Build address text from current address fields
                        $addressText = implode(' ', array_filter([
                            $record->address_line1,
                            $record->address_line2,
                            $record->building_name,
                            $record->postal_code,
                        ]));

                        if (empty($addressText)) {
                            Notification::make()
                                ->warning()
                                ->title('No address data to parse')
                                ->body('Address line 1 or line 2 must contain text to auto-correct.')
                                ->send();

                            return;
                        }

                        // Parse the address
                        $parsed = $parser->parse($addressText);

                        // Track what changed
                        $changes = [];

                        if ($parsed['province'] && $parsed['province']->id !== $record->province_id) {
                            $changes[] = 'Province';
                            $record->province_id = $parsed['province']->id;
                        }

                        if ($parsed['district'] && $parsed['district']->id !== $record->district_id) {
                            $changes[] = 'District';
                            $record->district_id = $parsed['district']->id;
                        }

                        if ($parsed['neighborhood'] && $parsed['neighborhood']->id !== $record->neighborhood_id) {
                            $changes[] = 'Neighborhood';
                            $record->neighborhood_id = $parsed['neighborhood']->id;
                        }

                        if ($parsed['postal_code'] && $parsed['postal_code'] !== $record->postal_code) {
                            $changes[] = 'Postal Code';
                            $record->postal_code = $parsed['postal_code'];
                        }

                        if ($parsed['building_number'] && $parsed['building_number'] !== $record->building_number) {
                            $changes[] = 'Building Number';
                            $record->building_number = $parsed['building_number'];
                        }

                        if ($parsed['floor'] && $parsed['floor'] !== $record->floor) {
                            $changes[] = 'Floor';
                            $record->floor = $parsed['floor'];
                        }

                        if ($parsed['apartment'] && $parsed['apartment'] !== $record->apartment) {
                            $changes[] = 'Apartment';
                            $record->apartment = $parsed['apartment'];
                        }

                        if (empty($changes)) {
                            Notification::make()
                                ->info()
                                ->title('No changes needed')
                                ->body('Address is already correct.')
                                ->send();

                            return;
                        }

                        // Save changes
                        $record->save();

                        Notification::make()
                            ->success()
                            ->title('Address auto-corrected')
                            ->body('Updated: '.implode(', ', $changes))
                            ->send();
                    }),
                SyncAddressToShopifyAction::make(),
                EditAction::make()
                    ->modalWidth(Width::FourExtraLarge),
            ]);
    }
}
