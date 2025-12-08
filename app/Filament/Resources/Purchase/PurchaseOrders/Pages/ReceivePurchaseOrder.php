<?php

namespace App\Filament\Resources\Purchase\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\Purchase\PurchaseOrders\PurchaseOrderResource;
use App\Models\Inventory\InventoryMovement;
use App\Models\Inventory\Location;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class ReceivePurchaseOrder extends Page implements HasSchemas
{
    use InteractsWithRecord;
    use InteractsWithSchemas;

    protected static string $resource = PurchaseOrderResource::class;

    protected string $view = 'filament.resources.purchase.purchase-orders.pages.receive-purchase-order';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Check if order can be received
        if (! in_array($this->record->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::PartiallyReceived])) {
            Notification::make()
                ->warning()
                ->title(__('Cannot Receive Items'))
                ->body(__('This order must be in Ordered or Partially Received status to receive items.'))
                ->send();

            $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $this->record]));

            return;
        }

        $data = [
            'location_id' => $this->record->location_id ?? Location::where('is_default', true)->first()?->id,
            'received_date' => now()->format('Y-m-d'),
            'items' => $this->record->items->map(fn ($item) => [
                'id' => $item->id,
                'product_name' => $item->productVariant->product->name.' - '.$item->productVariant->sku,
                'quantity_ordered' => $item->quantity_ordered,
                'quantity_already_received' => $item->quantity_received,
                'quantity_to_receive' => max(0, $item->quantity_ordered - $item->quantity_received),
                'notes' => '',
            ])->toArray(),
        ];

        $this->schema->fill($data);
    }

    public function schema(Schema $schema): Schema
    {
        return $schema
            ->components([
                Schemas\Components\Section::make(__('Receiving Information'))
                    ->schema([
                        Forms\Components\Select::make('location_id')
                            ->label(__('Receive to Location'))
                            ->options(Location::where('is_active', true)->pluck('name', 'id'))
                            ->required()
                            ->disabled()
                            ->dehydrated()
                            ->helperText(__('Set on purchase order - edit order to change'))
                            ->columnSpan(1),

                        Forms\Components\DatePicker::make('received_date')
                            ->label(__('Received Date'))
                            ->required()
                            ->native(false)
                            ->columnSpan(1),
                    ])
                    ->columns(2),

                Schemas\Components\Section::make(__('Items to Receive'))
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('product_name')
                                    ->label(__('Product'))
                                    ->disabled()
                                    ->dehydrated(false),

                                Forms\Components\TextInput::make('quantity_ordered')
                                    ->label(__('Ordered'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric(),

                                Forms\Components\TextInput::make('quantity_already_received')
                                    ->label(__('Already Received'))
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->numeric(),

                                Forms\Components\TextInput::make('quantity_to_receive')
                                    ->label(__('Receive Now'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),

                                Forms\Components\Textarea::make('notes')
                                    ->label(__('Notes'))
                                    ->placeholder(__('e.g., damaged, rejected'))
                                    ->rows(1)
                                    ->columnSpanFull(),

                                Forms\Components\Hidden::make('id'),
                            ])
                            ->columns(4)
                            ->reorderable(false)
                            ->addable(false)
                            ->deletable(false)
                            ->defaultItems(0),
                    ]),
            ])
            ->statePath('data');
    }

    public function receive(): void
    {
        $data = $this->schema->getState();

        DB::transaction(function () use ($data) {
            $locationId = $data['location_id'];
            $receivedDate = $data['received_date'];

            foreach ($data['items'] as $itemData) {
                $quantityToReceive = (int) ($itemData['quantity_to_receive'] ?? 0);

                if ($quantityToReceive <= 0) {
                    continue;
                }

                $item = $this->record->items()->findOrFail($itemData['id']);
                $productVariant = $item->productVariant;

                // Get current location inventory
                $locationInventory = DB::table('location_inventory')
                    ->where('location_id', $locationId)
                    ->where('product_variant_id', $productVariant->id)
                    ->first();

                $quantityBefore = $locationInventory?->quantity ?? 0;

                // Update quantity received on purchase order item
                $item->quantity_received += $quantityToReceive;
                $item->save();

                // Create inventory movement
                InventoryMovement::create([
                    'product_variant_id' => $productVariant->id,
                    'location_id' => $locationId,
                    'purchase_order_item_id' => $item->id,
                    'type' => \App\Enums\Inventory\InventoryMovementType::PurchaseReceived,
                    'quantity' => $quantityToReceive,
                    'quantity_before' => $quantityBefore,
                    'quantity_after' => $quantityBefore + $quantityToReceive,
                    'notes' => $itemData['notes'] ?? null,
                ]);

                // Update location inventory
                if ($locationInventory) {
                    DB::table('location_inventory')
                        ->where('id', $locationInventory->id)
                        ->increment('quantity', $quantityToReceive);
                } else {
                    DB::table('location_inventory')->insert([
                        'location_id' => $locationId,
                        'product_variant_id' => $productVariant->id,
                        'quantity' => $quantityToReceive,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // Update total product variant inventory (sum of all locations)
                $totalQuantity = DB::table('location_inventory')
                    ->where('product_variant_id', $productVariant->id)
                    ->sum('quantity');

                $productVariant->update(['inventory_quantity' => $totalQuantity]);
            }

            // Update purchase order status
            $allItemsReceived = $this->record->items->every(fn ($item) => $item->quantity_received >= $item->quantity_ordered);
            $anyItemsReceived = $this->record->items->some(fn ($item) => $item->quantity_received > 0);

            if ($allItemsReceived) {
                $this->record->status = PurchaseOrderStatus::Received;
                $this->record->received_date = $receivedDate;
            } elseif ($anyItemsReceived) {
                $this->record->status = PurchaseOrderStatus::PartiallyReceived;
            }

            $this->record->save();
        });

        Notification::make()
            ->success()
            ->title(__('Items received successfully'))
            ->body(__('Inventory has been updated and the purchase order status has been updated.'))
            ->send();

        $this->redirect(PurchaseOrderResource::getUrl('view', ['record' => $this->record]));
    }

    public function getTitle(): string
    {
        return __('Receive Items - Order #:number', ['number' => $this->record->order_number]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('receive')
                ->label(__('Receive Items'))
                ->submit('receive')
                ->color('success')
                ->icon('heroicon-o-inbox-arrow-down'),

            Action::make('cancel')
                ->label(__('Cancel'))
                ->color('gray')
                ->url(PurchaseOrderResource::getUrl('view', ['record' => $this->record])),
        ];
    }
}
