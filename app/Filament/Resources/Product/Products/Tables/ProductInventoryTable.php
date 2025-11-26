<?php

namespace App\Filament\Resources\Product\Products\Tables;

use App\Models\Inventory\InventoryMovement;
use App\Models\Product\ProductVariant;
use App\Models\Product\VariantOption;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ProductInventoryTable
{
    public static function configure(Table $table): Table
    {
        // Get all variant options for grouping
        $variantOptions = VariantOption::with('values')->orderBy('position')->get();

        return $table
            ->recordTitleAttribute('sku')
            ->groups(
                $variantOptions->map(function ($option) {
                    return Group::make("option_{$option->id}")
                        ->label(__($option->name))
                        ->collapsible()
                        ->getTitleFromRecordUsing(fn (ProductVariant $record): string => $record->getOptionTitleForGroup($option->id))
                        ->getKeyFromRecordUsing(fn (ProductVariant $record): string => $record->getOptionKeyForGroup($option->id))
                        ->scopeQueryByKeyUsing(function (\Illuminate\Database\Eloquent\Builder $query, string $key) {
                            if ($key === 'none') {
                                return $query;
                            }

                            $query->whereHas('optionValues', function ($q) use ($key) {
                                $q->where('variant_option_values.id', $key);
                            });
                        })
                        ->orderQueryUsing(function (\Illuminate\Database\Eloquent\Builder $query, string $direction) use ($option) {
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
            ->defaultGroup("option_{$variantOptions->first()?->id}")
            ->groupingSettingsInDropdownOnDesktop()
            ->columns([
                Tables\Columns\TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('optionValues.value')
                    ->label(__('Variant'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __($state))
                    ->separator(' / ')
                    ->wrap(),

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

                Tables\Columns\TextColumn::make('price')
                    ->label(__('Price'))
                    ->money('TRY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('weight')
                    ->label(__('Weight'))
                    ->suffix(' kg')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('requires_shipping')
                    ->label(__('Shipping'))
                    ->boolean()
                    ->toggleable(),
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
            ->recordActions([
                Action::make('adjust_stock')
                    ->label(__('Adjust Stock'))
                    ->icon('heroicon-o-calculator')
                    ->color('primary')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->label(__('Movement Type'))
                            ->options([
                                'received' => __('Received (Purchase)'),
                                'sold' => __('Sold (Manual)'),
                                'returned' => __('Returned'),
                                'damaged' => __('Damaged/Lost'),
                                'adjustment' => __('Adjustment'),
                                'correction' => __('Correction'),
                            ])
                            ->required()
                            ->native(false)
                            ->live(),

                        Forms\Components\TextInput::make('quantity')
                            ->label(__('Quantity Change'))
                            ->required()
                            ->numeric()
                            ->helperText(fn ($get): string => match ($get('type')) {
                                'received', 'returned' => __('Enter positive number to add stock'),
                                'sold', 'damaged' => __('Enter positive number to reduce stock'),
                                default => __('Enter positive for increase, negative for decrease'),
                            })
                            ->rules([
                                fn ($get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if (in_array($get('type'), ['sold', 'damaged']) && $value < 0) {
                                        $fail(__('For :type, enter a positive number', ['type' => __($get('type'))]));
                                    }
                                },
                            ]),

                        Forms\Components\Textarea::make('reference')
                            ->label(__('Notes/Reference'))
                            ->rows(3)
                            ->placeholder(__('e.g., Order #123, Supplier invoice #456, etc.')),
                    ])
                    ->action(function (Model $record, array $data): void {
                        $quantityBefore = $record->inventory_quantity;

                        // Calculate quantity change based on type
                        $quantityChange = match ($data['type']) {
                            'received', 'returned' => abs((int) $data['quantity']),
                            'sold', 'damaged' => -abs((int) $data['quantity']),
                            default => (int) $data['quantity'],
                        };

                        $quantityAfter = $quantityBefore + $quantityChange;

                        // Update variant stock
                        $record->update([
                            'inventory_quantity' => $quantityAfter,
                        ]);

                        // Create inventory movement record
                        InventoryMovement::create([
                            'product_variant_id' => $record->id,
                            'type' => $data['type'],
                            'quantity' => $quantityChange,
                            'quantity_before' => $quantityBefore,
                            'quantity_after' => $quantityAfter,
                            'reference' => $data['reference'] ?? null,
                        ]);

                        Notification::make()
                            ->title(__('Stock adjusted successfully'))
                            ->body(__('Stock changed from :before to :after', [
                                'before' => $quantityBefore,
                                'after' => $quantityAfter,
                            ]))
                            ->success()
                            ->send();
                    }),

                Action::make('view_history')
                    ->label(__('History'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Model $record): string => __('Inventory History: :sku', ['sku' => $record->sku]))
                    ->modalContent(fn (Model $record): \Illuminate\View\View => view(
                        'filament.resources.product.products.pages.inventory-history',
                        ['record' => $record]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),

                EditAction::make()
                    ->label(__('Edit'))
                    ->icon('heroicon-o-pencil')
                    ->modalHeading(__('Edit Variant Details'))
                    ->schema([
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
                    ]),
            ])
            ->toolbarActions([
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
                    ->action(function (\Illuminate\Database\Eloquent\Collection $records, array $data): void {
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
            ])
            ->defaultSort('product_variants.inventory_quantity', 'asc');
    }
}
