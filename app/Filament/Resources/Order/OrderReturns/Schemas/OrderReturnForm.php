<?php

namespace App\Filament\Resources\Order\OrderReturns\Schemas;

use App\Enums\Order\ReturnStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class OrderReturnForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('order_id')
                    ->relationship('order', 'id')
                    ->required(),
                TextInput::make('return_number')
                    ->required(),
                TextInput::make('platform')
                    ->required(),
                TextInput::make('external_return_id'),
                Select::make('status')
                    ->options(ReturnStatus::class)
                    ->default('requested')
                    ->required(),
                DateTimePicker::make('requested_at'),
                DateTimePicker::make('approved_at'),
                DateTimePicker::make('label_generated_at'),
                DateTimePicker::make('shipped_at'),
                DateTimePicker::make('received_at'),
                DateTimePicker::make('inspected_at'),
                DateTimePicker::make('completed_at'),
                DateTimePicker::make('rejected_at'),
                TextInput::make('reason_code'),
                TextInput::make('reason_name'),
                Textarea::make('customer_note')
                    ->columnSpanFull(),
                Textarea::make('internal_note')
                    ->columnSpanFull(),
                TextInput::make('return_shipping_carrier'),
                TextInput::make('return_tracking_number'),
                Textarea::make('return_tracking_url')
                    ->columnSpanFull(),
                Textarea::make('return_label_url')
                    ->columnSpanFull(),
                TextInput::make('return_shipping_cost')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('$'),
                TextInput::make('original_shipping_cost')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->prefix('$'),
                TextInput::make('total_refund_amount')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('restocking_fee')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('approved_by')
                    ->numeric(),
                TextInput::make('rejected_by')
                    ->numeric(),
                TextInput::make('inspected_by')
                    ->numeric(),
                TextInput::make('platform_data'),
                TextInput::make('currency')
                    ->required()
                    ->default('TRY'),
            ]);
    }
}
