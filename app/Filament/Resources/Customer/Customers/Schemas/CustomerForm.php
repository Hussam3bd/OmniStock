<?php

namespace App\Filament\Resources\Customer\Customers\Schemas;

use App\Enums\Order\OrderChannel;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('channel')
                    ->options(OrderChannel::class)
                    ->required()
                    ->default(OrderChannel::PORTAL),
                TextInput::make('first_name')
                    ->required(),
                TextInput::make('last_name'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email(),
                TextInput::make('phone')
                    ->tel(),
                Textarea::make('address_line1')
                    ->columnSpanFull(),
                Textarea::make('address_line2')
                    ->columnSpanFull(),
                TextInput::make('city'),
                TextInput::make('state'),
                TextInput::make('postal_code'),
                TextInput::make('country')
                    ->required()
                    ->default('TR'),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}
