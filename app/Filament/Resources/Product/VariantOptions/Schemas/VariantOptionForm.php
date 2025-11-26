<?php

namespace App\Filament\Resources\Product\VariantOptions\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VariantOptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Option Details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('Option Name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('e.g., Color, Size, Material'))
                            ->helperText(__('This will be used across all products that use this option')),

                        TextInput::make('position')
                            ->label(__('Display Order'))
                            ->numeric()
                            ->default(0)
                            ->helperText(__('Lower numbers appear first')),
                    ])->columns(2),

                Section::make(__('Option Values'))
                    ->schema([
                        Repeater::make('values')
                            ->relationship('values')
                            ->schema([
                                TextInput::make('value')
                                    ->label(__('Value'))
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder(__('e.g., Red, Blue, Small, Large')),

                                TextInput::make('position')
                                    ->label(__('Order'))
                                    ->numeric()
                                    ->default(0),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel(__('Add Value'))
                            ->reorderable('position')
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['value'] ?? null)
                            ->helperText(__('Add the values for this option that products can use')),
                    ]),
            ]);
    }
}
