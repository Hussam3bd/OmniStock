<?php

namespace App\Filament\Resources\Supplier\Suppliers\Schemas;

use Filament\Forms;
use Filament\Schemas;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make(__('Supplier Information'))
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Supplier Name'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('code')
                            ->label(__('Supplier Code'))
                            ->required()
                            ->unique(ignorable: fn ($record) => $record)
                            ->maxLength(255)
                            ->helperText(__('Unique identifier for this supplier')),

                        Forms\Components\TextInput::make('contact_person')
                            ->label(__('Contact Person'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('email')
                            ->label(__('Email'))
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('phone')
                            ->label(__('Phone'))
                            ->tel()
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_active')
                            ->label(__('Active'))
                            ->default(true),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Address'))
                    ->schema([
                        Forms\Components\Textarea::make('address')
                            ->label(__('Address'))
                            ->rows(2)
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('city')
                            ->label(__('City'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('country')
                            ->label(__('Country'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('tax_number')
                            ->label(__('Tax Number'))
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Notes'))
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
