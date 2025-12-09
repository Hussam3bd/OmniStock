<?php

namespace App\Filament\Pages;

use App\Models\Inventory\Location;
use App\Models\Product\ProductVariant;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class InventoryReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.inventory-report';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 1;

    public ?int $selectedLocation = null;

    public static function getNavigationGroup(): ?string
    {
        return __('Reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('Inventory Report');
    }

    public function getTitle(): string
    {
        return __('Inventory Report');
    }

    public function mount(): void
    {
        // Default to the default location if exists
        $defaultLocation = Location::where('is_default', true)->first();
        $this->selectedLocation = $defaultLocation?->id;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->defaultGroup('product.title')
            ->groups([
                Tables\Grouping\Group::make('product.title')
                    ->label(__('Product'))
                    ->collapsible(),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('product.title')
                    ->label(__('Product'))
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(function (ProductVariant $record): HtmlString {
                        return new HtmlString(
                            view('components.variant-badges', [
                                'productTitle' => $record->product->title,
                                'optionValues' => $record->optionValues,
                            ])->render()
                        );
                    })
                    ->html(),

                Tables\Columns\TextColumn::make('sku')
                    ->label(__('SKU'))
                    ->searchable()
                    ->sortable()
                    ->weight(FontWeight::Medium),

                Tables\Columns\TextColumn::make('location_name')
                    ->label(__('Location'))
                    ->getStateUsing(fn (ProductVariant $record) => $this->selectedLocation
                        ? Location::find($this->selectedLocation)?->name
                        : __('All Locations'))
                    ->visible(fn () => $this->selectedLocation !== null),

                Tables\Columns\TextColumn::make('stock_quantity')
                    ->label(__('Stock'))
                    ->sortable()
                    ->badge()
                    ->getStateUsing(fn (ProductVariant $record): int => $this->getStockQuantity($record))
                    ->color(fn (int $state): string => match (true) {
                        $state <= 0 => 'danger',
                        $state <= 10 => 'warning',
                        default => 'success',
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->label(__('Price'))
                    ->money('TRY')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cost_price')
                    ->label(__('Cost'))
                    ->money('TRY')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('inventory_value')
                    ->label(__('Stock Value'))
                    ->money('TRY', divideBy: 100)
                    ->getStateUsing(fn (ProductVariant $record): int => $record->price->multiply($this->getStockQuantity($record))->getAmount())
                    ->sortable(query: function ($query, string $direction): void {
                        // Sorting handled by calculated field
                    }),

                Tables\Columns\TextColumn::make('cost_value')
                    ->label(__('Cost Value'))
                    ->money('TRY', divideBy: 100)
                    ->getStateUsing(fn (ProductVariant $record): int => $record->cost_price ? $record->cost_price->multiply($this->getStockQuantity($record))->getAmount() : 0)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('potential_profit')
                    ->label(__('Potential Profit'))
                    ->money('TRY', divideBy: 100)
                    ->getStateUsing(function (ProductVariant $record): int {
                        $profit = $record->cost_price
                            ? $record->price->subtract($record->cost_price)
                            : $record->price;

                        return $profit->multiply($this->getStockQuantity($record))->getAmount();
                    })
                    ->toggleable()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger'),
            ])
            ->recordActions([
                Action::make('view_history')
                    ->label(__('History'))
                    ->icon('heroicon-o-clock')
                    ->color('info')
                    ->modalHeading(fn (?ProductVariant $record) => $record
                        ? __('Inventory History: :product', [
                            'product' => $record->product->title.' - '.$record->sku,
                        ])
                        : __('Inventory History'))
                    ->modalDescription(fn (?ProductVariant $record) => $record?->optionValues->pluck('value')->join(' / ') ?? '')
                    ->modalContent(fn (?ProductVariant $record) => $record
                        ? new HtmlString(
                            Blade::render(
                                "@livewire('inventory.view-inventory-history', ['variantId' => {$record->id}, 'locationId' => ".($this->selectedLocation ?? 'null').'])'
                            )
                        )
                        : new HtmlString(''))
                    ->modalWidth('6xl')
                    ->slideOver()
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('location')
                    ->label(__('Location'))
                    ->options(fn () => [
                        0 => __('All Locations'),
                        ...Location::query()->pluck('name', 'id')->toArray(),
                    ])
                    ->default(fn () => Location::where('is_default', true)->first()?->id ?? 0)
                    ->query(function (Builder $query, array $data) {
                        if (isset($data['value']) && $data['value'] > 0) {
                            $this->selectedLocation = $data['value'];
                        } else {
                            $this->selectedLocation = null;
                        }

                        return $query;
                    }),

                Tables\Filters\SelectFilter::make('stock_status')
                    ->label(__('Stock Status'))
                    ->options([
                        'in_stock' => __('In Stock'),
                        'low_stock' => __('Low Stock (≤10)'),
                        'out_of_stock' => __('Out of Stock'),
                    ])
                    ->query(function ($query, $state) {
                        if (! isset($state['value'])) {
                            return $query;
                        }

                        return match ($state['value']) {
                            'out_of_stock' => $this->filterByStockLevel($query, 0, 0),
                            'low_stock' => $this->filterByStockLevel($query, 1, 10),
                            'in_stock' => $this->filterByStockLevel($query, 11, null),
                            default => $query,
                        };
                    }),

                Tables\Filters\Filter::make('has_cost')
                    ->label(__('Has Cost Price'))
                    ->query(fn ($query) => $query->whereNotNull('cost_price')),

                Tables\Filters\Filter::make('profitable')
                    ->label(__('Profitable Only'))
                    ->query(fn ($query) => $query->whereRaw('price > COALESCE(cost_price, 0)')),
            ])
            ->defaultSort('product.title')
            ->paginated([10, 25, 50, 100]);
    }

    protected function getTableQuery(): Builder
    {
        $query = ProductVariant::query()->with(['product', 'optionValues']);

        if ($this->selectedLocation) {
            // Join with location_inventory for specific location
            $query->join('location_inventory', function ($join) {
                $join->on('product_variants.id', '=', 'location_inventory.product_variant_id')
                    ->where('location_inventory.location_id', '=', $this->selectedLocation);
            })->select('product_variants.*', 'location_inventory.quantity as location_quantity');
        } else {
            // Get all locations - we'll aggregate in the accessor
            $query->with('locations');
        }

        return $query;
    }

    protected function getStockQuantity(ProductVariant $record): int
    {
        if ($this->selectedLocation) {
            return $record->location_quantity ?? 0;
        }

        // Sum across all locations
        return $record->locations->sum('pivot.quantity');
    }

    protected function filterByStockLevel(Builder $query, ?int $min, ?int $max): Builder
    {
        if ($this->selectedLocation) {
            // Filter on specific location
            if ($max === null) {
                return $query->where('location_inventory.quantity', '>=', $min);
            }

            return $query->whereBetween('location_inventory.quantity', [$min, $max]);
        }

        // Filter on total across all locations
        $query->having('total_quantity', '>=', $min);

        if ($max !== null) {
            $query->having('total_quantity', '<=', $max);
        }

        return $query;
    }
}
