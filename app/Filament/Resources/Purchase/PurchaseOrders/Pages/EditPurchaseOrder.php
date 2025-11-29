<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Purchase\PurchaseOrders\PurchaseOrderResource;
use App\Models\Inventory\InventoryMovement;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receive_items')
                ->label(__('Receive Items'))
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->visible(fn () => in_array($this->record->status, [
                    PurchaseOrderStatus::Ordered,
                    PurchaseOrderStatus::PartiallyReceived,
                ]))
                ->form([
                    Forms\Components\Repeater::make('items')
                        ->label(__('Items to Receive'))
                        ->schema([
                            Forms\Components\Placeholder::make('product_info')
                                ->label(__('Product'))
                                ->content(fn ($get, $state) => $this->record->items->find($state['id'] ?? null)?->productVariant->sku.' - '.$this->record->items->find($state['id'] ?? null)?->productVariant->product->name),

                            Forms\Components\Placeholder::make('quantity_ordered_display')
                                ->label(__('Quantity Ordered'))
                                ->content(fn ($state) => $this->record->items->find($state['id'] ?? null)?->quantity_ordered ?? 0),

                            Forms\Components\Placeholder::make('quantity_received_display')
                                ->label(__('Already Received'))
                                ->content(fn ($state) => $this->record->items->find($state['id'] ?? null)?->quantity_received ?? 0),

                            Forms\Components\TextInput::make('quantity_to_receive')
                                ->label(__('Quantity to Receive'))
                                ->numeric()
                                ->minValue(0)
                                ->default(function ($state) {
                                    $item = $this->record->items->find($state['id'] ?? null);

                                    return $item ? $item->quantity_ordered - $item->quantity_received : 0;
                                })
                                ->required(),

                            Forms\Components\Hidden::make('id'),
                        ])
                        ->default(function () {
                            return $this->record->items->map(fn ($item) => [
                                'id' => $item->id,
                                'quantity_to_receive' => $item->quantity_ordered - $item->quantity_received,
                            ])->toArray();
                        })
                        ->columns(2)
                        ->reorderable(false)
                        ->addable(false)
                        ->deletable(false),

                    Forms\Components\DatePicker::make('received_date')
                        ->label(__('Received Date'))
                        ->default(now())
                        ->required()
                        ->native(false),

                    Forms\Components\Textarea::make('notes')
                        ->label(__('Receiving Notes'))
                        ->rows(2),
                ])
                ->action(function (array $data) {
                    DB::transaction(function () use ($data) {
                        foreach ($data['items'] as $itemData) {
                            $quantityToReceive = $itemData['quantity_to_receive'] ?? 0;

                            if ($quantityToReceive <= 0) {
                                continue;
                            }

                            $purchaseOrderItem = $this->record->items()->find($itemData['id']);

                            if (! $purchaseOrderItem) {
                                continue;
                            }

                            // Update quantity received
                            $purchaseOrderItem->quantity_received += $quantityToReceive;
                            $purchaseOrderItem->save();

                            // Get current quantity
                            $variant = $purchaseOrderItem->productVariant;
                            $quantityBefore = $variant->quantity;
                            $quantityAfter = $quantityBefore + $quantityToReceive;

                            // Update variant quantity
                            $variant->quantity = $quantityAfter;
                            $variant->save();

                            // Create inventory movement
                            InventoryMovement::create([
                                'product_variant_id' => $purchaseOrderItem->product_variant_id,
                                'purchase_order_item_id' => $purchaseOrderItem->id,
                                'type' => 'purchase',
                                'quantity' => $quantityToReceive,
                                'quantity_before' => $quantityBefore,
                                'quantity_after' => $quantityAfter,
                                'reference' => __('Purchase Order: ').$this->record->order_number.($data['notes'] ? ' - '.$data['notes'] : ''),
                            ]);
                        }

                        // Update purchase order status
                        $allFullyReceived = $this->record->items->every(fn ($item) => $item->quantity_received >= $item->quantity_ordered);
                        $anyReceived = $this->record->items->some(fn ($item) => $item->quantity_received > 0);

                        if ($allFullyReceived) {
                            $this->record->status = PurchaseOrderStatus::Received;
                            $this->record->received_date = $data['received_date'];
                        } elseif ($anyReceived) {
                            $this->record->status = PurchaseOrderStatus::PartiallyReceived;
                        }

                        $this->record->save();
                    });

                    Notification::make()
                        ->success()
                        ->title(__('Items Received'))
                        ->body(__('The items have been received and inventory has been updated.'))
                        ->send();

                    $this->refreshFormData([
                        'status',
                        'received_date',
                        'items',
                    ]);
                }),

            DeleteAction::make(),
        ];
    }
}
