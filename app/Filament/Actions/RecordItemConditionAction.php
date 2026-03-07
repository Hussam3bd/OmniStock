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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RecordItemConditionAction
{
    public static function make(): Action
    {
        return Action::make('record_condition')
            ->label(__('Record Condition'))
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->schema([
                Forms\Components\Select::make('location_id')
                    ->label(__('Location'))
                    ->options(Location::where('is_active', true)->pluck('name', 'id'))
                    ->required()
                    ->native(false)
                    ->default(fn () => Location::where('is_default', true)->first()?->id),

                Forms\Components\Select::make('type')
                    ->label(__('Condition'))
                    ->options([
                        InventoryMovementType::Damaged->value => InventoryMovementType::Damaged->getLabel(),
                        InventoryMovementType::Lost->value => InventoryMovementType::Lost->getLabel(),
                        InventoryMovementType::NeedsFix->value => InventoryMovementType::NeedsFix->getLabel(),
                    ])
                    ->required()
                    ->native(false)
                    ->helperText(fn ($get) => match ($get('type')) {
                        InventoryMovementType::Damaged->value => __('Item is damaged and cannot be repaired. Will be written off.'),
                        InventoryMovementType::Lost->value => __('Item is missing/lost. Will be written off.'),
                        InventoryMovementType::NeedsFix->value => __('Item needs repair. Will be held until fixed.'),
                        default => null,
                    }),

                Forms\Components\TextInput::make('quantity')
                    ->label(__('Quantity'))
                    ->required()
                    ->integer()
                    ->minValue(1)
                    ->default(1),

                Forms\Components\Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(2)
                    ->placeholder(__('e.g., Defective sole, missing buckle, water damage...')),
            ])
            ->action(function (ProductVariant $record, array $data): void {
                DB::transaction(function () use ($record, $data) {
                    $location = Location::findOrFail($data['location_id']);
                    $type = InventoryMovementType::from($data['type']);
                    $quantity = (int) $data['quantity'];

                    $locationInventory = LocationInventory::firstOrCreate(
                        ['location_id' => $location->id, 'product_variant_id' => $record->id],
                        ['quantity' => 0]
                    );

                    $locationInventory = LocationInventory::where('id', $locationInventory->id)
                        ->lockForUpdate()
                        ->first();

                    $quantityBefore = $locationInventory->quantity;
                    $quantityAfter = $quantityBefore - $quantity; // always a deduction

                    $locationInventory->update(['quantity' => $quantityAfter]);

                    InventoryMovement::create([
                        'product_variant_id' => $record->id,
                        'location_id' => $location->id,
                        'type' => $type->value,
                        'quantity' => -$quantity,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $quantityAfter,
                        'reference' => $data['notes'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ]);

                    $record->syncInventoryQuantity();

                    activity()
                        ->performedOn($locationInventory)
                        ->withProperties([
                            'variant_id' => $record->id,
                            'variant_sku' => $record->sku,
                            'location_id' => $location->id,
                            'type' => $type->value,
                            'quantity_change' => -$quantity,
                            'quantity_before' => $quantityBefore,
                            'quantity_after' => $quantityAfter,
                            'recorded_by' => Auth::id(),
                        ])
                        ->log("inventory_{$type->value}");

                    Notification::make()
                        ->title(__(':type recorded', ['type' => $type->getLabel()]))
                        ->body(__(':qty unit(s) of :sku recorded as :type at :location.', [
                            'qty' => $quantity,
                            'sku' => $record->sku,
                            'type' => $type->getLabel(),
                            'location' => $location->name,
                        ]))
                        ->warning()
                        ->send();
                });
            });
    }
}
