<?php

namespace App\Filament\Resources\Product\Products\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_group_id')
                    ->relationship('productGroup', 'name'),
                TextInput::make('title')
                    ->required(),
                Textarea::make('description')
                    ->columnSpanFull(),
                TextInput::make('vendor'),
                TextInput::make('product_type'),
                TextInput::make('status')
                    ->required()
                    ->default('active'),
            ]);
    }
}
