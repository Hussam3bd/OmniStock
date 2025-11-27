<?php

namespace App\Filament\Resources\Product\Products\Tables;

use App\Filament\Actions\AdjustStockAction;
use App\Models\Product\ProductVariant;
use App\Models\Product\VariantOption;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                                $q->where('variant_option_values.id', $key);
                            });
                        })
                        ->orderQueryUsing(function (Builder $query, string $direction) use ($option) {
                            // Order by the option value - include position in SELECT for DISTINCT compatibility
                            $query->select('product_variants.*', 'vov_'.$option->id.'.position as _group_order')
                                ->leftJoin(
                                    'product_variant_option_values as pvov_'.$option->id,
                                    fn ($join) => $join->on('product_variants.id', '=', 'pvov_'.$option->id.'.product_variant_id')
                                )
                                ->leftJoin(
                                    'variant_option_values as vov_'.$option->id,
                                    fn ($join) => $join->on('pvov_'.$option->id.'.variant_option_value_id', '=', 'vov_'.$option->id.'.id')
                                        ->where('vov_'.$option->id.'.variant_option_id', '=', $option->id)
                                )
                                ->orderBy('_group_order', $direction)
                                ->distinct();
                        });
                })->toArray()
            )
//            ->defaultGroup("option_{$variantOptions->first()?->id}")
            ->groupingSettingsInDropdownOnDesktop()
            ->columns([
                Tables\Columns\TextColumn::make('optionValues.value')
                    ->label(__('Variant'))
                    ->badge()
                    ->separator(' / ')
                    ->formatStateUsing(fn ($state) => __($state))
                    ->searchable(),

                Tables\Columns\TextInputColumn::make('sku')
                    ->label(__('SKU'))
                    ->rules(['required', 'max:255'])
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('barcode')
                    ->label(__('Barcode'))
                    ->rules(['nullable', 'max:255']),

                Tables\Columns\TextInputColumn::make('price')
                    ->label(__('Price'))
                    ->rules(['required', 'numeric', 'min:0'])
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('cost_price')
                    ->label(__('Cost'))
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->toggleable(),

                Tables\Columns\TextColumn::make('inventory_quantity')
                    ->label(__('Stock'))
                    ->sortable()
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 10 => 'warning',
                        default => 'success',
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

                        Forms\Components\TextInput::make('price')
                            ->label(__('Price'))
                            ->required()
                            ->numeric()
                            ->prefix('TRY')
                            ->default(0),

                        Forms\Components\TextInput::make('inventory_quantity')
                            ->label(__('Stock'))
                            ->required()
                            ->numeric()
                            ->default(0),
                    ]),
            ])
            ->recordActions([
                AdjustStockAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No variants yet'))
            ->emptyStateDescription(__('Create your first variant or generate multiple variants automatically'))
            ->emptyStateIcon('heroicon-o-cube')
            ->defaultSort('product_variants.id', 'desc');
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
