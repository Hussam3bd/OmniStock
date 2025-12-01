<?php

namespace App\Filament\Resources\Product\Products\Tables;

use App\Filament\Actions\AdjustStockAction;
use App\Forms\Components\MoneyInput;
use App\Models\Inventory\InventoryMovement;
use App\Models\Product\ProductVariant;
use App\Models\Product\VariantOption;
use App\Services\BarcodeService;
use App\Tables\Columns\MoneyInputColumn;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ProductVariantsTable
{
    public static function configure(Table $table, ManageRelatedRecords $livewire): Table
    {
        // Get all variant options for grouping
        $variantOptions = VariantOption::with('values')->orderBy('position')->get();

        return $table
            ->groups(
                $variantOptions->map(function ($option) {
                    return Group::make("option_{$option->id}")
                        ->label(__($option->name))
                        ->collapsible()
                        ->getTitleFromRecordUsing(fn (ProductVariant $record): string => $record->getOptionTitleForGroup($option->id))
                        ->getKeyFromRecordUsing(fn (ProductVariant $record): string => $record->getOptionKeyForGroup($option->id))
                        ->scopeQueryByKeyUsing(function (Builder $query, string $key) {
                            if ($key === 'none') {
                                return $query;
                            }

                            $query->whereHas('optionValues', function ($q) use ($key) {
                                $q->where('variant_option_value_id', $key);
                            });
                        })
                        ->orderQueryUsing(function (Builder $query, string $direction) use ($option) {
                            // Order by the option value position using subquery to avoid GROUP BY issues
                            $query->leftJoin(
                                'product_variant_option_values as pvov_'.$option->id,
                                'product_variants.id', '=', 'pvov_'.$option->id.'.product_variant_id'
                            )
                                ->leftJoin(
                                    'variant_option_values as vov_'.$option->id,
                                    function ($join) use ($option) {
                                        $join->on('pvov_'.$option->id.'.variant_option_value_id', '=', 'vov_'.$option->id.'.id')
                                            ->where('vov_'.$option->id.'.variant_option_id', '=', $option->id);
                                    }
                                )
                                ->selectRaw('product_variants.*, MAX(vov_'.$option->id.'.position) as _group_position')
                                ->groupBy('product_variants.id')
                                ->orderBy('_group_position', $direction);
                        });
                })->toArray()
            )
            ->defaultGroup("option_{$variantOptions->first()?->id}")
            ->groupingSettingsInDropdownOnDesktop()
            ->columns([
                Tables\Columns\TextColumn::make('optionValues.value')
                    ->label(__('Variant'))
                    ->badge()
                    ->separator(' / ')
                    ->formatStateUsing(function ($state, $record) {
                        // If the state is an array (JSON), get the translation for current locale
                        if (is_array($state)) {
                            $locale = app()->getLocale();

                            return $state[$locale] ?? $state['en'] ?? $state['tr'] ?? '';
                        }

                        // Fallback for string values (shouldn't happen after migration)
                        return $state;
                    })
                    ->searchable(),

                Tables\Columns\TextInputColumn::make('sku')
                    ->label(__('SKU'))
                    ->rules(['required', 'max:255'])
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('barcode')
                    ->label(__('Barcode'))
                    ->rules(['nullable', 'max:255']),

                MoneyInputColumn::make('price')
                    ->label(__('Price'))
                    ->rules(['required', 'numeric', 'min:0'])
                    ->sortable(),

                MoneyInputColumn::make('cost_price')
                    ->label(__('Cost'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('inventory_quantity')
                    ->label(__('Stock'))
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 10 => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (int $state): string => match (true) {
                        $state <= 0 => __('Out of Stock'),
                        $state <= 10 => __(':count (Low Stock)', ['count' => $state]),
                        default => (string) $state,
                    }),

                Tables\Columns\TextColumn::make('weight')
                    ->label(__('Weight'))
                    ->suffix('kg')
                    ->toggleable()
                    ->toggledHiddenByDefault(),

                Tables\Columns\IconColumn::make('requires_shipping')
                    ->label(__('Shipping'))
                    ->boolean()
                    ->toggleable()
                    ->toggledHiddenByDefault(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('stock_status')
                    ->label(__('Stock Status'))
                    ->options([
                        'in_stock' => __('In Stock'),
                        'low_stock' => __('Low Stock (≤10)'),
                        'out_of_stock' => __('Out of Stock'),
                    ])
                    ->query(function ($query, $state) {
                        return match ($state['value'] ?? null) {
                            'out_of_stock' => $query->where('inventory_quantity', '<=', 0),
                            'low_stock' => $query->whereBetween('inventory_quantity', [1, 10]),
                            'in_stock' => $query->where('inventory_quantity', '>', 10),
                            default => $query,
                        };
                    }),
            ])
            ->headerActions([
                Action::make('generate_variants')
                    ->label(__('Generate Variants'))
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->modalHeading(__('Generate Product Variants'))
                    ->modalDescription(__('Select option values to automatically generate all variant combinations'))
                    ->modalWidth('3xl')
                    ->schema(function () {
                        $options = VariantOption::with('values')->orderBy('position')->get();

                        return array_map(function ($option) {
                            return Forms\Components\Select::make("option_{$option->id}")
                                ->label($option->name)
                                ->options($option->values->pluck('value', 'id'))
                                ->multiple()
                                ->searchable()
                                ->helperText(__('Select multiple values to generate combinations'));
                        }, $options->all());
                    })
                    ->action(function (array $data) use ($livewire) {
                        $product = $livewire->getOwnerRecord();

                        // Filter out empty selections
                        $selectedOptions = collect($data)
                            ->filter(fn ($values) => ! empty($values))
                            ->toArray();

                        if (empty($selectedOptions)) {
                            Notification::make()
                                ->title(__('No options selected'))
                                ->warning()
                                ->send();

                            return;
                        }

                        // Generate cartesian product
                        $combinations = static::generateCombinations($selectedOptions);

                        $createdCount = 0;
                        foreach ($combinations as $combination) {
                            // Check if variant with these exact option values already exists
                            $exists = ProductVariant::where('product_id', $product->id)
                                ->whereHas('optionValues', function ($query) use ($combination) {
                                    $query->whereIn('variant_option_values.id', $combination);
                                }, '=', count($combination))
                                ->exists();

                            if (! $exists) {
                                $sku = static::generateSku($product, $combination);

                                $variant = ProductVariant::create([
                                    'product_id' => $product->id,
                                    'sku' => $sku,
                                    'barcode' => $sku,
                                    'price' => 0,
                                    'inventory_quantity' => 0,
                                    'requires_shipping' => true,
                                    'taxable' => true,
                                ]);

                                $variant->optionValues()->attach($combination);
                                $createdCount++;
                            }
                        }

                        Notification::make()
                            ->title(__('Variants generated successfully'))
                            ->body(__(':count new variants created', ['count' => $createdCount]))
                            ->success()
                            ->send();
                    }),

                CreateAction::make()
                    ->label(__('Add Single Variant'))
                    ->icon('heroicon-o-plus')
                    ->modalHeading(__('Create Variant'))
                    ->schema([
                        Forms\Components\TextInput::make('sku')
                            ->label(__('SKU'))
                            ->required()
                            ->unique('product_variants', 'sku')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('barcode')
                            ->label(__('Barcode'))
                            ->maxLength(255),

                        MoneyInput::make('price')
                            ->label(__('Price'))
                            ->required()
                            ->default(0),

                        MoneyInput::make('cost_price')
                            ->label(__('Cost Price')),

                        Forms\Components\TextInput::make('inventory_quantity')
                            ->label(__('Stock'))
                            ->required()
                            ->numeric()
                            ->default(0),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('Edit Variant'))
                    ->schema([
                        Forms\Components\TextInput::make('sku')
                            ->label(__('SKU'))
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('barcode')
                            ->label(__('Barcode'))
                            ->maxLength(255),

                        Forms\Components\TextInput::make('weight')
                            ->label(__('Weight (kg)'))
                            ->numeric()
                            ->suffix('kg'),

                        Forms\Components\Toggle::make('requires_shipping')
                            ->label(__('Requires Shipping'))
                            ->default(true),

                        Forms\Components\Toggle::make('taxable')
                            ->label(__('Taxable'))
                            ->default(true),

                        Forms\Components\Placeholder::make('edit_price_inline')
                            ->label(__('Price & Cost'))
                            ->content(__('Edit price and cost directly in the table for faster updates'))
                            ->columnSpanFull(),
                    ]),

                AdjustStockAction::make(),

                Action::make('view_history')
                    ->label(__('History'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Model $record): string => __('Inventory History: :sku', ['sku' => $record->sku]))
                    ->modalWidth('4xl')
                    ->infolist(function (Model $record): array {
                        $movements = $record->inventoryMovements()
                            ->orderBy('created_at', 'desc')
                            ->limit(50)
                            ->get();

                        if ($movements->isEmpty()) {
                            return [
                                \Filament\Infolists\Components\TextEntry::make('no_history')
                                    ->label('')
                                    ->state(__('No inventory movements recorded yet'))
                                    ->color('gray'),
                            ];
                        }

                        return [
                            \Filament\Infolists\Components\RepeatableEntry::make('movements')
                                ->label(__('Movement History'))
                                ->state($movements->toArray())
                                ->schema([
                                    \Filament\Infolists\Components\TextEntry::make('created_at')
                                        ->label(__('Date'))
                                        ->dateTime()
                                        ->size('sm'),

                                    \Filament\Infolists\Components\TextEntry::make('type')
                                        ->label(__('Type'))
                                        ->badge()
                                        ->formatStateUsing(fn ($state) => __(ucfirst($state)))
                                        ->color(fn ($state): string => match ($state) {
                                            'received' => 'success',
                                            'sold' => 'primary',
                                            'returned' => 'warning',
                                            'damaged' => 'danger',
                                            default => 'gray',
                                        }),

                                    \Filament\Infolists\Components\TextEntry::make('quantity')
                                        ->label(__('Change'))
                                        ->formatStateUsing(fn ($state) => $state > 0 ? "+{$state}" : $state)
                                        ->color(fn ($state): string => $state > 0 ? 'success' : 'danger')
                                        ->weight('bold'),

                                    \Filament\Infolists\Components\TextEntry::make('quantity_before')
                                        ->label(__('Before'))
                                        ->formatStateUsing(fn ($state) => (string) $state),

                                    \Filament\Infolists\Components\TextEntry::make('quantity_after')
                                        ->label(__('After'))
                                        ->formatStateUsing(fn ($state) => (string) $state),

                                    \Filament\Infolists\Components\TextEntry::make('reference')
                                        ->label(__('Reference'))
                                        ->default('-')
                                        ->columnSpanFull(),
                                ])
                                ->columns(5),
                        ];
                    })
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulk_adjust')
                        ->label(__('Bulk Adjust Stock'))
                        ->icon('heroicon-o-calculator')
                        ->color('primary')
                        ->schema([
                            Forms\Components\Select::make('type')
                                ->label(__('Movement Type'))
                                ->options([
                                    'received' => __('Received (Purchase)'),
                                    'adjustment' => __('Adjustment'),
                                    'correction' => __('Correction'),
                                ])
                                ->required()
                                ->native(false),

                            Forms\Components\TextInput::make('quantity')
                                ->label(__('Quantity to Add'))
                                ->required()
                                ->numeric()
                                ->helperText(__('This amount will be added to all selected variants')),

                            Forms\Components\Textarea::make('reference')
                                ->label(__('Notes/Reference'))
                                ->rows(3),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $quantityChange = (int) $data['quantity'];

                            foreach ($records as $record) {
                                $quantityBefore = $record->inventory_quantity;
                                $quantityAfter = $quantityBefore + $quantityChange;

                                $record->update([
                                    'inventory_quantity' => $quantityAfter,
                                ]);

                                InventoryMovement::create([
                                    'product_variant_id' => $record->id,
                                    'type' => $data['type'],
                                    'quantity' => $quantityChange,
                                    'quantity_before' => $quantityBefore,
                                    'quantity_after' => $quantityAfter,
                                    'reference' => $data['reference'] ?? null,
                                ]);
                            }

                            Notification::make()
                                ->title(__('Bulk stock adjustment completed'))
                                ->body(__(':count variants updated', ['count' => $records->count()]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('generate_sku')
                        ->label(__('Generate SKU'))
                        ->icon('heroicon-o-tag')
                        ->requiresConfirmation()
                        ->modalHeading(__('Generate SKU for Selected Variants'))
                        ->modalDescription(__('This will generate and update SKU for all selected variants based on their product model code and option values.'))
                        ->action(function (Collection $records) {
                            $barcodeService = app(BarcodeService::class);
                            $updatedCount = 0;

                            foreach ($records as $variant) {
                                $sku = $barcodeService->generateSku($variant);
                                $variant->update(['sku' => $sku]);
                                $updatedCount++;
                            }

                            Notification::make()
                                ->title(__('SKU Generated Successfully'))
                                ->body(__(':count variants updated', ['count' => $updatedCount]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('generate_barcode')
                        ->label(__('Generate Barcode'))
                        ->icon('heroicon-o-qr-code')
                        ->requiresConfirmation()
                        ->modalHeading(__('Generate Barcode for Selected Variants'))
                        ->modalDescription(__('This will generate barcodes for all selected variants using the configured format and country code.'))
                        ->action(function (Collection $records) {
                            $barcodeService = app(BarcodeService::class);
                            $updatedCount = 0;

                            foreach ($records as $variant) {
                                $barcode = $barcodeService->generateBarcode($variant);
                                $variant->update(['barcode' => $barcode]);
                                $updatedCount++;
                            }

                            Notification::make()
                                ->title(__('Barcode Generated Successfully'))
                                ->body(__(':count variants updated', ['count' => $updatedCount]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('update_price')
                        ->label(__('Update Price'))
                        ->icon('heroicon-o-currency-dollar')
                        ->form([
                            MoneyInput::make('price')
                                ->label(__('New Price'))
                                ->required()
                                ->helperText(__('This price will be applied to all selected variants')),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $updatedCount = 0;

                            foreach ($records as $variant) {
                                $variant->update(['price' => $data['price']]);
                                $updatedCount++;
                            }

                            Notification::make()
                                ->title(__('Price Updated Successfully'))
                                ->body(__(':count variants updated', ['count' => $updatedCount]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('update_cost')
                        ->label(__('Update Cost'))
                        ->icon('heroicon-o-banknotes')
                        ->form([
                            MoneyInput::make('cost_price')
                                ->label(__('New Cost Price'))
                                ->required()
                                ->helperText(__('This cost will be applied to all selected variants')),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $updatedCount = 0;

                            foreach ($records as $variant) {
                                $variant->update(['cost_price' => $data['cost_price']]);
                                $updatedCount++;
                            }

                            Notification::make()
                                ->title(__('Cost Updated Successfully'))
                                ->body(__(':count variants updated', ['count' => $updatedCount]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No variants yet'))
            ->emptyStateDescription(__('Create your first variant or generate multiple variants automatically'))
            ->emptyStateIcon('heroicon-o-cube')
            ->defaultSort('sku', 'asc');
    }

    protected static function generateCombinations(array $options): array
    {
        $result = [[]];

        foreach ($options as $optionKey => $values) {
            $append = [];
            foreach ($result as $product) {
                foreach ($values as $value) {
                    $product[] = $value;
                    $append[] = $product;
                    array_pop($product);
                }
            }
            $result = $append;
        }

        return $result;
    }

    protected static function generateSku(Model $product, array $optionValueIds): string
    {
        // Get the product's model code (e.g., REV-0001)
        $modelCode = strtoupper($product->model_code ?? 'PROD-001');

        // Get the actual option values from the database
        $optionValues = \App\Models\Product\VariantOptionValue::whereIn('id', $optionValueIds)
            ->orderBy('position')
            ->get()
            ->map(function ($optionValue) {
                $value = $optionValue->value;

                // Extract the actual value after the last dot (e.g., "color.black" -> "black")
                if (str_contains($value, '.')) {
                    $value = substr($value, strrpos($value, '.') + 1);
                }

                // If value is 3 chars or less, use full value
                if (strlen($value) <= 3) {
                    return strtoupper($value);
                }

                // For numeric values, preserve the full number
                if (is_numeric($value)) {
                    return strtoupper($value);
                }

                // Take first 3 characters for text values
                return strtoupper(substr($value, 0, 3));
            })
            ->toArray();

        // Construct base SKU: MODEL-CODE-OPT1-OPT2
        $baseSku = $modelCode;
        if (! empty($optionValues)) {
            $baseSku .= '-'.implode('-', $optionValues);
        }

        // Check for uniqueness and add suffix if needed
        $sku = $baseSku;
        $counter = 1;
        while (ProductVariant::where('sku', $sku)->where('product_id', $product->id)->exists()) {
            $sku = $baseSku.'-'.str_pad($counter, 2, '0', STR_PAD_LEFT);
            $counter++;
        }

        return $sku;
    }
}
