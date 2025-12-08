<?php

namespace App\Filament\Actions;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use App\Models\Inventory\LocationInventory;
use App\Models\Product\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class AdjustStockAction
{
    public static function make(): Action
    {
        return Action::make('adjust_stock')
            ->label(__('Adjust Stock'))
            ->icon('heroicon-o-calculator')
            ->color('primary')
            ->schema([
                Forms\Components\Select::make('location_id')
                    ->label(__('Location'))
                    ->options(Location::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->native(false)
                    ->default(fn () => Location::where('is_default', true)->first()?->id)
                    ->helperText(__('Select the location where inventory will be adjusted')),

                Forms\Components\Select::make('type')
                    ->label(__('Movement Type'))
                    ->options(InventoryMovementType::class)
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
            ->action(function (ProductVariant $record, array $data): void {
                DB::transaction(function () use ($record, $data) {
                    $location = Location::findOrFail($data['location_id']);

                    // Calculate quantity change based on type
                    $quantityChange = match ($data['type']) {
                        'received', 'returned' => abs((int) $data['quantity']),
                        'sold', 'damaged' => -abs((int) $data['quantity']),
                        default => (int) $data['quantity'],
                    };

                    // Get or create location inventory record
                    $locationInventory = LocationInventory::firstOrCreate(
                        [
                            'location_id' => $location->id,
                            'product_variant_id' => $record->id,
                        ],
                        ['quantity' => 0]
                    );

                    // Lock the row for update to prevent race conditions
                    $locationInventory = LocationInventory::where('id', $locationInventory->id)
                        ->lockForUpdate()
                        ->first();

                    $quantityBefore = $locationInventory->quantity;
                    $quantityAfter = $quantityBefore + $quantityChange;

                    // Update location inventory
                    $locationInventory->update(['quantity' => $quantityAfter]);

                    // Create inventory movement record
                    InventoryMovement::create([
                        'product_variant_id' => $record->id,
                        'location_id' => $location->id,
                        'type' => $data['type'],
                        'quantity' => $quantityChange,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference' => $data['reference'] ?? null,
                    ]);

                    // Sync the variant's inventory_quantity with total across all locations
                    $record->syncInventoryQuantity();

                    // Log activity
                    activity()
                        ->performedOn($locationInventory)
                        ->withProperties([
                            'variant_id' => $record->id,
                            'variant_sku' => $record->sku,
                            'location_id' => $location->id,
                            'location_name' => $location->name,
                            'type' => $data['type'],
                            'quantity_change' => $quantityChange,
                            'quantity_before' => $quantityBefore,
                            'quantity_after' => $quantityAfter,
                        ])
                        ->log("inventory_manual_adjustment_{$data['type']}");

                    Notification::make()
                        ->title(__('Stock adjusted successfully'))
                        ->body(__('Stock at :location changed from :before to :after (Total: :total)', [
                            'location' => $location->name,
                            'before' => $quantityBefore,
                            'after' => $quantityAfter,
                            'total' => $record->fresh()->inventory_quantity,
                        ]))
                        ->success()
                        ->send();
                });
            });
    }
}
