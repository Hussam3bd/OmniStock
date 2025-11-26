<?php

namespace App\Filament\Resources\Product\Products\Pages;

use App\Filament\Resources\Product\Products\ProductResource;
use App\Models\Product\Product;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Grid;
use Illuminate\Database\Eloquent\Model;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->createAnother(false)
                ->modalHeading(__('Create Product'))
                ->modalDescription(__('Create a new product with initial variant'))
                ->form([
                    Grid::make(2)->schema([
                        TextInput::make('title')
                            ->label(__('Product Name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('e.g., Running Shoes Air Max'))
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
                            ])
                            ->default('draft')
                            ->required()
                            ->native(false),
                    ]),

                    Grid::make(2)->schema([
                        TextInput::make('sku')
                            ->label(__('Initial SKU'))
                            ->required()
                            ->unique('product_variants', 'sku')
                            ->maxLength(255)
                            ->placeholder(__('e.g., AIR-MAX-001')),

                        TextInput::make('price')
                            ->label(__('Base Price'))
                            ->required()
                            ->numeric()
                            ->prefix('TRY')
                            ->default(0)
                            ->minValue(0),
                    ]),
                ])
                ->using(function (array $data): Model {
                    $sku = $data['sku'];
                    $price = $data['price'];
                    $status = $data['status'] ?? 'draft';
                    unset($data['sku'], $data['price'], $data['status']);

                    $product = Product::create([
                        'title' => $data['title'],
                        'product_type' => $data['product_type'],
                        'status' => $status,
                    ]);

                    $product->variants()->create([
                        'sku' => $sku,
                        'barcode' => $sku,
                        'price' => $price,
                        'inventory_quantity' => 0,
                        'requires_shipping' => true,
                        'taxable' => true,
                    ]);

                    return $product;
                })
                ->successRedirectUrl(fn (Model $record): string => ProductResource::getUrl('edit', [
                    'record' => $record,
                ]))
                ->successNotificationTitle(__('Product created successfully')),
        ];
    }
}
