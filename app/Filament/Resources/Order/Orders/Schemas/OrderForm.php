<?php

namespace App\Filament\Resources\Order\Orders\Schemas;

use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Enums\Order\PaymentStatus;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('customer_id')
                    ->relationship('customer', 'id')
                    ->required()
                    ->disabled(fn ($record) => $record?->isExternal()),
                Select::make('channel')
                    ->options(OrderChannel::class)
                    ->required()
                    ->default(OrderChannel::PORTAL->value)
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('order_number')
                    ->required()
                    ->disabled(fn ($record) => $record?->isExternal()),
                Select::make('order_status')
                    ->options(OrderStatus::class)
                    ->required()
                    ->default(OrderStatus::PENDING->value)
                    ->disabled(fn ($record) => $record?->isExternal()),
                Select::make('payment_status')
                    ->options(PaymentStatus::class)
                    ->required()
                    ->default(PaymentStatus::PENDING->value)
                    ->disabled(fn ($record) => $record?->isExternal()),
                Select::make('fulfillment_status')
                    ->options(FulfillmentStatus::class)
                    ->required()
                    ->default(FulfillmentStatus::UNFULFILLED->value)
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('subtotal')
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('tax_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('shipping_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('discount_amount')
                    ->required()
                    ->numeric()
                    ->default(0.0)
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('total_amount')
                    ->required()
                    ->numeric()
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('currency')
                    ->required()
                    ->default('TRY')
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('invoice_number')
                    ->disabled(fn ($record) => $record?->isExternal()),
                DatePicker::make('invoice_date')
                    ->disabled(fn ($record) => $record?->isExternal()),
                TextInput::make('invoice_url')
                    ->url()
                    ->disabled(fn ($record) => $record?->isExternal()),
                Textarea::make('notes')
                    ->columnSpanFull()
                    ->disabled(fn ($record) => $record?->isExternal()),
                DateTimePicker::make('order_date')
                    ->required()
                    ->disabled(fn ($record) => $record?->isExternal()),
            ]);
    }
}
