<?php

namespace App\Filament\Resources\Product\Products\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Product Information'))
                    ->description(__('Core product details'))
                    ->schema([
                        TextInput::make('title')
                            ->label(__('Product Name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('e.g., Running Shoes Air Max'))
                            ->columnSpanFull(),

                        TextInput::make('model_code')
                            ->label(__('Model Code'))
                            ->required()
                            ->unique('products', 'model_code', ignoreRecord: true)
                            ->maxLength(255)
                            ->placeholder(__('e.g., REV-0001'))
                            ->helperText(__('Unique identifier for this product (e.g., REV-0001)'))
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper($state))
                            ->columnSpanFull(),

                        TextInput::make('gtin')
                            ->label(__('GTIN'))
                            ->maxLength(255)
                            ->placeholder(__('e.g., 0614141123456'))
                            ->helperText(__('Global Trade Item Number (UPC, EAN, ISBN, etc.)'))
                            ->columnSpanFull(),

                        Select::make('product_type')
                            ->label(__('Product Type'))
                            ->options([
                                'footwear' => __('Footwear'),
                                'clothing' => __('Clothing'),
                                'accessories' => __('Accessories'),
                            ])
                            ->required()
                            ->native(false),

                        Select::make('status')
                            ->label(__('Status'))
                            ->options([
                                'draft' => __('Draft'),
                                'active' => __('Active'),
                                'archived' => __('Archived'),
                            ])
                            ->required()
                            ->default('draft')
                            ->native(false)
                            ->helperText(__('Draft products are not visible to customers')),

                        RichEditor::make('description')
                            ->label(__('Description'))
                            ->placeholder(__('Describe your product...'))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('Product Images'))
                    ->description(__('Upload and manage product images'))
                    ->schema([
                        SpatieMediaLibraryFileUpload::make('images')
                            ->label(__('Images'))
                            ->collection('images')
                            ->multiple()
                            ->reorderable()
                            ->maxFiles(20)
                            ->maxSize(5120) // 5MB
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios([
                                '1:1',
                                '4:3',
                                '16:9',
                            ])
                            ->downloadable()
                            ->openable()
                            ->helperText(__('Upload product images. You can upload up to 20 images (max 5MB each). Drag to reorder. Later, you can assign these images to specific variants.'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
