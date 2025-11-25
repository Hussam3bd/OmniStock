<?php

namespace App\Filament\Resources\Product\ProductVariants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->relationship('product', 'title')
                    ->required(),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required(),
                TextInput::make('barcode')
                    ->required(),
                TextInput::make('title'),
                TextInput::make('option1'),
                TextInput::make('option2'),
                TextInput::make('option3'),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('cost_price')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('inventory_quantity')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('weight')
                    ->numeric(),
                TextInput::make('weight_unit')
                    ->required()
                    ->default('kg'),
                Toggle::make('requires_shipping')
                    ->required(),
                Toggle::make('taxable')
                    ->required(),
            ]);
    }
}
