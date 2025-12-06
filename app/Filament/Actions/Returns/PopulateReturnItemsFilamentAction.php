<?php

namespace App\Filament\Actions\Returns;

use App\Models\Order\OrderReturn;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class PopulateReturnItemsFilamentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'populate_return_items';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Add Return Items'))
            ->icon('heroicon-o-squares-plus')
            ->color('warning')
            ->visible(fn (OrderReturn $record) => $record->items()->count() === 0 && $record->order->items()->count() > 0)
            ->modalHeading(__('Add Items to Return'))
            ->modalDescription(function (OrderReturn $record) {
                $shopifyLineItems = $record->platform_data['returnLineItems']['edges'] ?? [];
                $itemCount = count($shopifyLineItems);
                $totalQty = array_sum(array_map(fn ($edge) => $edge['node']['quantity'] ?? 0, $shopifyLineItems));

                return __('The customer wants to return :count item(s) (total quantity: :qty). Please select which order items correspond to this return.', [
                    'count' => $itemCount,
                    'qty' => $totalQty,
                ]);
            })
            ->modalWidth('3xl')
            ->form(function (OrderReturn $record) {
                $orderItems = $record->order->items()->with('productVariant.product')->get();

                return [
                    Repeater::make('items')
                        ->label(__('Return Items'))
                        ->schema([
                            Select::make('order_item_id')
                                ->label(__('Order Item'))
                                ->options(function () use ($orderItems) {
                                    return $orderItems->mapWithKeys(function ($item) {
                                        $productName = $item->productVariant?->product?->name ?? $item->product_name ?? 'Unknown Product';
                                        $variantName = $item->productVariant?->name ?? '';
                                        $sku = $item->sku ?: 'No SKU';

                                        return [
                                            $item->id => "{$productName} {$variantName} - {$sku} (Max: {$item->quantity})",
                                        ];
                                    });
                                })
                                ->required()
                                ->searchable()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),

                            TextInput::make('quantity')
                                ->label(__('Quantity'))
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->default(1)
                                ->live()
                                ->afterStateUpdated(function ($state, $get, $set) use ($orderItems) {
                                    $orderItemId = $get('order_item_id');
                                    if ($orderItemId) {
                                        $orderItem = $orderItems->find($orderItemId);
                                        if ($orderItem && $state > $orderItem->quantity) {
                                            $set('quantity', $orderItem->quantity);
                                            Notification::make()
                                                ->warning()
                                                ->title(__('Quantity exceeds order'))
                                                ->body(__('Maximum quantity for this item is :max', ['max' => $orderItem->quantity]))
                                                ->send();
                                        }
                                    }
                                }),
                        ])
                        ->columns(2)
                        ->defaultItems(function (OrderReturn $record) {
                            $shopifyLineItems = $record->platform_data['returnLineItems']['edges'] ?? [];

                            return max(count($shopifyLineItems), 1);
                        })
                        ->addActionLabel(__('Add Another Item'))
                        ->reorderable(false)
                        ->collapsible(),
                ];
            })
            ->action(function (OrderReturn $record, array $data) {
                try {
                    $created = 0;

                    foreach ($data['items'] as $itemData) {
                        $orderItem = $record->order->items()->find($itemData['order_item_id']);

                        if (! $orderItem) {
                            continue;
                        }

                        // Get reason from Shopify data for first item (best effort)
                        $shopifyLineItems = $record->platform_data['returnLineItems']['edges'] ?? [];
                        $reason = null;
                        $reasonCode = null;
                        $customerNote = null;

                        if (isset($shopifyLineItems[0]['node'])) {
                            $shopifyItem = $shopifyLineItems[0]['node'];
                            $reason = $shopifyItem['returnReason'] ?? $shopifyItem['returnReasonNote'] ?? null;
                            $reasonCode = $shopifyItem['returnReason'] ?? null;
                            $customerNote = $shopifyItem['customerNote'] ?? null;
                        }

                        $record->items()->create([
                            'order_item_id' => $orderItem->id,
                            'quantity' => $itemData['quantity'],
                            'reason_name' => $reason ?: 'Customer return request',
                            'reason_code' => $reasonCode,
                            'customer_note' => $customerNote,
                        ]);

                        $created++;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('Items Added'))
                        ->body(__(':count return item(s) added successfully. You can now approve the return.', ['count' => $created]))
                        ->send();

                    // Redirect to refresh the page
                    $this->redirect(request()->url());
                } catch (\Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title(__('Operation Failed'))
                        ->body(__('Failed to add return items: :error', ['error' => $e->getMessage()]))
                        ->send();
                }
            });
    }
}
