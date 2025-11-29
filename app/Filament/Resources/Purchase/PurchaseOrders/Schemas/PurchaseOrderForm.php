<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Schemas;

use App\Enums\PurchaseOrderStatus;
use App\Forms\Components\MoneyInput;
use App\Models\Product\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists;
use Filament\Schemas;
use Filament\Schemas\Schema;

class PurchaseOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Schemas\Components\Section::make(__('Order Information'))
                    ->schema([
                        Forms\Components\TextInput::make('order_number')
                            ->label(__('Order Number'))
                            ->required()
                            ->unique(ignorable: fn ($record) => $record)
                            ->default(fn () => 'PO-'.strtoupper(uniqid()))
                            ->maxLength(255),

                        Forms\Components\Select::make('supplier_id')
                            ->label(__('Supplier'))
                            ->relationship('supplier', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')
                                    ->label(__('Supplier Name'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('code')
                                    ->label(__('Supplier Code'))
                                    ->required()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('email')
                                    ->label(__('Email'))
                                    ->email()
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('phone')
                                    ->label(__('Phone'))
                                    ->tel()
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Select::make('status')
                            ->label(__('Status'))
                            ->options(PurchaseOrderStatus::class)
                            ->default(PurchaseOrderStatus::Draft)
                            ->required()
                            ->native(false),

                        Forms\Components\DatePicker::make('order_date')
                            ->label(__('Order Date'))
                            ->required()
                            ->default(now())
                            ->native(false),

                        Forms\Components\DatePicker::make('expected_delivery_date')
                            ->label(__('Expected Delivery Date'))
                            ->native(false),

                        Forms\Components\DatePicker::make('received_date')
                            ->label(__('Received Date'))
                            ->native(false)
                            ->visible(fn ($get) => in_array($get('status'), [
                                PurchaseOrderStatus::Received->value,
                                PurchaseOrderStatus::PartiallyReceived->value,
                            ])),
                    ])
                    ->columns(2)
                    ->columnSpan(2),

                Schemas\Components\Section::make(__('Order Summary'))
                    ->schema([
                        Infolists\Components\TextEntry::make('subtotal_display')
                            ->label(__('Subtotal'))
                            ->state(function ($get) {
                                $subtotal = collect($get('items') ?? [])->sum(function ($item) {
                                    $quantity = (float) ($item['quantity_ordered'] ?? 0);
                                    $unitCost = (float) ($item['unit_cost'] ?? 0);

                                    return $quantity * $unitCost;
                                });

                                return number_format($subtotal, 2).' '.__('TRY');
                            }),

                        Infolists\Components\TextEntry::make('tax_display')
                            ->label(__('Tax'))
                            ->state(function ($get) {
                                $tax = collect($get('items') ?? [])->sum(function ($item) {
                                    $quantity = (float) ($item['quantity_ordered'] ?? 0);
                                    $unitCost = (float) ($item['unit_cost'] ?? 0);
                                    $taxRate = (float) ($item['tax_rate'] ?? 0);

                                    $subtotal = $quantity * $unitCost;

                                    return $subtotal * ($taxRate / 100);
                                });

                                return number_format($tax, 2).' '.__('TRY');
                            }),

                        MoneyInput::make('shipping_cost')
                            ->label(__('Shipping Cost'))
                            ->reactive(),

                        Infolists\Components\TextEntry::make('total_display')
                            ->label(__('Total'))
                            ->state(function ($get) {
                                $subtotal = collect($get('items') ?? [])->sum(function ($item) {
                                    $quantity = (float) ($item['quantity_ordered'] ?? 0);
                                    $unitCost = (float) ($item['unit_cost'] ?? 0);

                                    return $quantity * $unitCost;
                                });

                                $tax = collect($get('items') ?? [])->sum(function ($item) {
                                    $quantity = (float) ($item['quantity_ordered'] ?? 0);
                                    $unitCost = (float) ($item['unit_cost'] ?? 0);
                                    $taxRate = (float) ($item['tax_rate'] ?? 0);

                                    $itemSubtotal = $quantity * $unitCost;

                                    return $itemSubtotal * ($taxRate / 100);
                                });

                                $shipping = (float) ($get('shipping_cost') ?? 0);
                                $total = $subtotal + $tax + $shipping;

                                return number_format($total, 2).' '.__('TRY');
                            }),
                    ])
                    ->columnSpan(1),

                Schemas\Components\Section::make(__('Order Items'))
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship('items')
                            ->schema([
                                Forms\Components\Select::make('product_variant_id')
                                    ->label(__('Product Variant'))
                                    ->options(ProductVariant::query()
                                        ->with('product')
                                        ->get()
                                        ->mapWithKeys(fn ($variant) => [
                                            $variant->id => $variant->product->name.' - '.$variant->sku,
                                        ]))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state) {
                                            $variant = ProductVariant::find($state);
                                            if ($variant && $variant->cost_price) {
                                                $set('unit_cost', $variant->cost_price->divide(100)->getAmount());
                                            }
                                        }
                                    }),

                                Forms\Components\TextInput::make('quantity_ordered')
                                    ->label(__('Quantity Ordered'))
                                    ->required()
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->reactive(),

                                Forms\Components\TextInput::make('quantity_received')
                                    ->label(__('Quantity Received'))
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->visible(fn ($get) => $get('../../status') !== PurchaseOrderStatus::Draft->value),

                                MoneyInput::make('unit_cost')
                                    ->label(__('Unit Cost'))
                                    ->required()
                                    ->reactive(),

                                Forms\Components\TextInput::make('tax_rate')
                                    ->label(__('Tax Rate (%)'))
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('%')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->reactive()
                                    ->dehydrateStateUsing(fn ($state) => $state === '' || $state === null ? 0 : $state),

                                Infolists\Components\TextEntry::make('item_total')
                                    ->label(__('Total'))
                                    ->state(function ($get) {
                                        $quantity = (float) ($get('quantity_ordered') ?? 0);
                                        $unitCost = (float) ($get('unit_cost') ?? 0);
                                        $taxRate = (float) ($get('tax_rate') ?? 0);

                                        $subtotal = $quantity * $unitCost;
                                        $tax = $subtotal * ($taxRate / 100);
                                        $total = $subtotal + $tax;

                                        return number_format($total, 2).' '.__('TRY');
                                    }),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => ProductVariant::find($state['product_variant_id'] ?? null)?->sku ?? __('New Item'))
                            ->addActionLabel(__('Add Item'))
                            ->deleteAction(
                                fn (Action $action) => $action->requiresConfirmation()
                            ),
                    ])
                    ->columnSpanFull(),

                Schemas\Components\Section::make(__('Notes'))
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label(__('Notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->collapsible(),
            ]);
    }
}
