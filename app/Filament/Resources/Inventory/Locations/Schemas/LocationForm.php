<?php

namespace App\Filament\Resources\Inventory\Locations\Schemas;

use Filament\Forms;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make(__('Basic Information'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('code')
                            ->label(__('Code'))
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->alphaDash()
                            ->helperText(__('Unique identifier for this location (e.g., MAIN, WH01)')),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true),

                        Forms\Components\Toggle::make('is_default')
                            ->label(__('Default Location'))
                            ->helperText(__('This location will be used by default for new inventory')),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Address Information'))
                    ->schema([
                        Forms\Components\Textarea::make('address')
                            ->label(__('Street Address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('city')
                            ->label(__('City')),

                        Forms\Components\TextInput::make('state')
                            ->label(__('State/Province')),

                        Forms\Components\TextInput::make('postal_code')
                            ->label(__('Postal Code')),

                        Forms\Components\TextInput::make('country')
                            ->label(__('Country')),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Schemas\Components\Section::make(__('Contact Information'))
                    ->schema([
                        PhoneInput::make('phone')
                            ->label(__('Phone'))
                            ->defaultCountry('TR')
                            ->countryOrder(['TR', 'US', 'GB'])
                            ->initialCountry('TR')
                            ->validateFor(),

                        Forms\Components\TextInput::make('email')
                            ->label(__('Email'))
                            ->email(),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Schemas\Components\Section::make(__('Notes'))
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label(__('Internal Notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }
}
