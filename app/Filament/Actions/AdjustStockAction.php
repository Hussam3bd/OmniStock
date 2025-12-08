<?php

namespace App\Filament\Actions;

use App\Enums\Inventory\InventoryMovementType;
use App\Models\Inventory\InventoryMovement;
use App\Models\Product\ProductVariant;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;

class AdjustStockAction
{
    public static function make(): Action
    {
        return Action::make('adjust_stock')
            ->label(__('Adjust Stock'))
            ->icon('heroicon-o-calculator')
            ->color('primary')
            ->schema([
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
            });
    }
}
