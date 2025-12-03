<?php

namespace App\Filament\Resources\Customer\Customers\Schemas;

use App\Enums\Order\OrderChannel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('channel')
                    ->options(OrderChannel::class)
                    ->required()
                    ->default(OrderChannel::PORTAL)
                    ->disabled()
                    ->dehydrated(),
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                PhoneInput::make('phone')
                    ->defaultCountry('TR')
                    ->countryOrder(['TR', 'US', 'GB'])
                    ->initialCountry('TR')
                    ->validateFor(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
