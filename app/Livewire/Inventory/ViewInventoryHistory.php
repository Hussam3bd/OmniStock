<?php

namespace App\Livewire\Inventory;

use App\Models\Inventory\InventoryMovement;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class ViewInventoryHistory extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public int $variantId;

    public ?int $locationId = null;

    public function mount(int $variantId, ?int $locationId = null): void
    {
        $this->variantId = $variantId;
        $this->locationId = $locationId;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getTableQuery())
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('Date'))
                    ->dateTime()
                    ->description(fn (InventoryMovement $record) => $record->created_at->diffForHumans())
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label(__('Type'))
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('location.name')
                    ->label(__('Location'))
                    ->placeholder('-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('Change'))
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? "+{$state}" : (string) $state)
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity_before')
                    ->label(__('Before'))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('quantity_after')
                    ->label(__('After'))
                    ->alignEnd()
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reference')
                    ->label(__('Reference'))
                    ->placeholder('-')
                    ->searchable()
                    ->wrap()
                    ->description(fn (InventoryMovement $record) => $record->notes),

                Tables\Columns\TextColumn::make('order.order_number')
                    ->label(__('Order'))
                    ->url(fn (InventoryMovement $record) => $record->order_id
                        ? route('filament.admin.resources.order.orders.view', $record->order_id)
                        : null)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([10, 25, 50])
            ->poll('30s')
            ->emptyStateHeading(__('No inventory movements'))
            ->emptyStateDescription(__('This variant has no inventory movement history yet.'))
            ->emptyStateIcon('heroicon-o-clock');
    }

    protected function getTableQuery(): Builder
    {
        $query = InventoryMovement::query()
            ->where('product_variant_id', $this->variantId)
            ->with(['location', 'order']);

        if ($this->locationId) {
            $query->where('location_id', $this->locationId);
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.inventory.view-inventory-history');
    }
}
