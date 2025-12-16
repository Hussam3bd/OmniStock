<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Schemas;

use App\Enums\PurchaseOrderStatus;
use App\Forms\Components\MoneyInput;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Cknow\Money\Money;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;

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
                                PhoneInput::make('phone')
                                    ->label(__('Phone'))
                                    ->defaultCountry('TR')
                                    ->countryOrder(['TR', 'US', 'GB'])
                                    ->initialCountry('TR')
                                    ->validateFor(),
                            ]),

                        Forms\Components\Select::make('account_id')
                            ->label(__('Payment Account'))
                            ->relationship('account', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText(__('Account to pay from (defaults to first bank/cash account if not specified)'))
                            ->getOptionLabelFromRecordUsing(fn ($record
                            ) => "{$record->name} ({$record->type->getLabel()})"),

                        Forms\Components\Select::make('location_id')
                            ->label(__('Destination Location'))
                            ->relationship('location', 'name')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(fn () => \App\Models\Inventory\Location::where('is_default', true)->first()?->id)
                            ->helperText(__('Where this order will be received')),

                        Forms\Components\Select::make('currency_id')
                            ->label(__('Currency'))
                            ->relationship('currency', 'code')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->default(function (callable $set) {
                                $defaultCurrency = Currency::where('is_default', true)->first();
                                if ($defaultCurrency) {
                                    // Set currency_code for default currency
                                    $set('currency_code', $defaultCurrency->code);

                                    return $defaultCurrency->id;
                                }

                                return null;
                            })
                            ->reactive()
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->code} ({$record->symbol})")
                            ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                if ($state) {
                                    $currency = Currency::find($state);
                                    $defaultCurrency = Currency::where('is_default', true)->first();

                                    if ($currency && $defaultCurrency) {
                                        $rate = ExchangeRate::getRateWithFallback(
                                            $currency->id,
                                            $defaultCurrency->id
                                        );
                                        $set('exchange_rate', $rate ?? 1.0);
                                        // Set currency_code so Money casts use the correct currency
                                        $set('currency_code', $currency->code);
                                    }
                                }
                            }),

                        Forms\Components\Hidden::make('currency_code'),

                        Forms\Components\Hidden::make('status')
                            ->default(PurchaseOrderStatus::Draft),

                        Forms\Components\TextInput::make('exchange_rate')
                            ->label(__('Exchange Rate'))
                            ->numeric()
                            ->disabled()
                            ->dehydrated()
                            ->helperText(fn ($get) => $get('currency_id')
                                ? __('Rate to default currency at order time')
                                : null),

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

                                $currency = Currency::find($get('currency_id'));
                                $currencyCode = $currency?->code ?? 'TRY';

                                return number_format($subtotal, 2).' '.$currencyCode;
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

                                $currency = Currency::find($get('currency_id'));
                                $currencyCode = $currency?->code ?? 'TRY';

                                return number_format($tax, 2).' '.$currencyCode;
                            }),

                        MoneyInput::make('shipping_cost')
                            ->label(__('Shipping Cost'))
                            ->default(0)
                            ->currencyField('currency_id')
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

                                $currency = Currency::find($get('currency_id'));
                                $currencyCode = $currency?->code ?? 'TRY';
                                $defaultCurrency = Currency::where('is_default', true)->first();

                                $display = number_format($total, 2).' '.$currencyCode;

                                // Show conversion if not default currency
                                if ($currency && $defaultCurrency && $currency->id !== $defaultCurrency->id) {
                                    $exchangeRate = (float) ($get('exchange_rate') ?? 1.0);
                                    $convertedTotal = $total * $exchangeRate;
                                    $display .= ' ≈ '.number_format($convertedTotal, 2).' '.$defaultCurrency->code;
                                }

                                return $display;
                            }),
                    ])
                    ->columnSpan(1),

                Schemas\Components\Section::make(__('Order Items'))
                    ->headerActions([
                        Action::make('addAllVariants')
                            ->label(__('Add All Variants'))
                            ->icon('heroicon-o-plus-circle')
                            ->color('success')
                            ->form([
                                Forms\Components\Select::make('product_id')
                                    ->label(__('Select Product'))
                                    ->options(
                                        Product::query()
                                            ->orderBy('title')
                                            ->get()
                                            ->mapWithKeys(fn ($product) => [
                                                $product->id => $product->title.($product->model_code ? ' ('.$product->model_code.')' : ''),
                                            ])
                                    )
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('All variants of this product will be added to the order')),

                                Forms\Components\TextInput::make('number_of_sets')
                                    ->label(__('Number of Sets'))
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->required()
                                    ->helperText(__('Quantity will be calculated based on size distribution per set')),

                                Forms\Components\TextInput::make('bulk_unit_cost')
                                    ->label(__('Unit Cost (for all variants)'))
                                    ->numeric()
                                    ->step(0.01)
                                    ->suffix(fn ($livewire) => Currency::find($livewire->data['currency_id'] ?? null)?->code ?? 'TRY')
                                    ->helperText(__('Leave empty to use each variant\'s cost price')),
                            ])
                            ->action(function (array $data, $livewire) {
                                $productId = $data['product_id'];
                                $numberOfSets = (int) ($data['number_of_sets'] ?? 1);
                                $bulkUnitCost = ! empty($data['bulk_unit_cost']) ? (float) $data['bulk_unit_cost'] : null;

                                // Get the currency for this purchase order
                                $currencyId = $livewire->data['currency_id'] ?? null;
                                $currency = $currencyId ? Currency::find($currencyId) : Currency::where('is_default', true)->first();
                                $currencyCode = $currency?->code ?? 'TRY';

                                $variants = ProductVariant::where('product_id', $productId)
                                    ->with(['product', 'optionValues.variantOption'])
                                    ->get();

                                // Get current form state to preserve all data
                                $formState = $livewire->form->getRawState();
                                $currentItems = $formState['items'] ?? [];
                                $existingVariantIds = collect($currentItems)->pluck('product_variant_id')->filter()->values()->toArray();

                                // Define size-based quantity mapping for one set
                                $sizeQuantityMap = function ($size) {
                                    $size = (string) $size;
                                    // Sizes 37, 38, 39 get 2 pieces per set
                                    if (in_array($size, ['37', '38', '39'])) {
                                        return 2;
                                    }

                                    // All other sizes (36, 40, etc.) get 1 piece per set
                                    return 1;
                                };

                                $addedCount = 0;
                                foreach ($variants as $variant) {
                                    // Skip if variant already exists in items
                                    if (in_array($variant->id, $existingVariantIds)) {
                                        continue;
                                    }

                                    // Find the size option value for this variant
                                    $sizeValue = $variant->optionValues
                                        ->first(fn ($optionValue) => $optionValue->variantOption?->type === 'size');

                                    // Get base quantity for this size
                                    $baseQuantity = $sizeValue
                                        ? $sizeQuantityMap($sizeValue->value)
                                        : 1;

                                    // Calculate total quantity (base quantity × number of sets)
                                    $quantity = $baseQuantity * $numberOfSets;

                                    // Handle unit cost - create Money object
                                    if ($bulkUnitCost !== null) {
                                        // Create Money object from decimal value (Money::parse handles conversion)
                                        $unitCost = Money::parse($bulkUnitCost, $currencyCode);
                                    } else {
                                        // Use variant's cost price (already a Money object or null)
                                        $unitCost = $variant->cost_price ?? Money::parse(0, $currencyCode);
                                    }

                                    $currentItems[] = [
                                        'product_variant_id' => $variant->id,
                                        'quantity_ordered' => $quantity,
                                        'unit_cost' => $unitCost,
                                        'tax_rate' => 0,
                                    ];
                                    $addedCount++;
                                }

                                // Update the items in the component data directly
                                $livewire->data['items'] = $currentItems;

                                // Also update the form state
                                $formState['items'] = $currentItems;
                                $livewire->form->fill($formState);

                                // Force Livewire to refresh
                                $livewire->dispatch('$refresh');

                                Notification::make()
                                    ->success()
                                    ->title(__('Variants Added'))
                                    ->body(__(':count variants added to order (:sets sets)', ['count' => $addedCount, 'sets' => $numberOfSets]))
                                    ->send();
                            })
                            ->modalHeading(__('Add All Variants of Product'))
                            ->modalSubmitActionLabel(__('Add Variants'))
                            ->modalWidth('md'),
                    ])
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship('items')
                            ->schema([
                                Forms\Components\Select::make('product_variant_id')
                                    ->label(__('Product Variant'))
                                    ->options(ProductVariant::query()
                                        ->with('product')
                                        ->get()
                                        ->groupBy(fn ($variant
                                        ) => $variant->product->title.($variant->product->model_code ? ' ('.$variant->product->model_code.')' : ''))
                                        ->map(fn ($variants) => $variants->mapWithKeys(fn ($variant) => [
                                            $variant->id => $variant->sku.($variant->title ? ' - '.$variant->title : ''),
                                        ])))
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->disableOptionWhen(function ($value, $state, $get) {
                                        $selectedVariants = collect($get('../../items'))
                                            ->pluck('product_variant_id')
                                            ->filter()
                                            ->values();

                                        return $selectedVariants->contains($value) && $value !== $state;
                                    })
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

                                MoneyInput::make('unit_cost')
                                    ->label(__('Unit Cost'))
                                    ->required()
                                    ->currencyField('../../currency_id')
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

                                        $currency = Currency::find($get('../../currency_id'));
                                        $currencyCode = $currency?->code ?? 'TRY';

                                        return number_format($total, 2).' '.$currencyCode;
                                    }),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state
                            ): ?string => ProductVariant::find($state['product_variant_id'] ?? null)?->sku ?? __('New Item'))
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
