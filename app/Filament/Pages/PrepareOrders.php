<?php

namespace App\Filament\Pages;

use App\Enums\Integration\IntegrationProvider;
use App\Enums\Order\FulfillmentStatus;
use App\Enums\Order\OrderChannel;
use App\Enums\Order\OrderStatus;
use App\Models\Address\District;
use App\Models\Address\Province;
use App\Models\Integration\Integration;
use App\Models\Order\Order;
use App\Services\Integrations\ShippingProviders\BasitKargo\BasitKargoAdapter;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;

class PrepareOrders extends Page
{
    protected string $view = 'filament.pages.prepare-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('Sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('Prepare Orders');
    }

    public function getTitle(): string
    {
        return __('Prepare Orders');
    }

    /** @var array<int> */
    public array $orderIds = [];

    public int $currentIndex = 0;

    /** @var array<int, bool> */
    public array $checkedItems = [];

    public bool $confirmed = false;

    // Carrier modal
    public bool $showCarrierModal = false;

    public string $selectedCarrier = 'SURAT';

    /** @var array<int, array{code: string, name: string}> */
    public array $availableCarriers = [];

    // Address correction modal
    public bool $showAddressModal = false;

    public ?int $editProvinceId = null;

    public ?int $editDistrictId = null;

    public string $editAddressLine1 = '';

    /** @var array<int, array{id: int, name: string}> */
    public array $availableProvinces = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $availableDistricts = [];

    public function mount(): void
    {
        $this->loadQueue();
    }

    public function loadQueue(): void
    {
        $this->orderIds = Order::query()
            ->whereIn('fulfillment_status', [
                FulfillmentStatus::UNFULFILLED,
                FulfillmentStatus::AWAITING_SHIPMENT,
            ])
            ->whereIn('order_status', [
                OrderStatus::PENDING,
                OrderStatus::CONFIRMED,
                OrderStatus::PROCESSING,
            ])
            ->orderBy('created_at')
            ->pluck('id')
            ->toArray();

        $this->currentIndex = 0;
        $this->checkedItems = [];
        $this->confirmed = false;
    }

    #[Computed]
    public function currentOrder(): ?Order
    {
        $id = $this->orderIds[$this->currentIndex] ?? null;

        if (! $id) {
            return null;
        }

        return Order::query()
            ->with([
                'items.productVariant.media',
                'items.productVariant.optionValues.variantOption',
                'items.productVariant.product.media',
                'customer',
                'shippingAddress.province',
                'shippingAddress.district',
            ])
            ->find($id);
    }

    #[Computed]
    public function totalOrders(): int
    {
        return count($this->orderIds);
    }

    #[Computed]
    public function allItemsChecked(): bool
    {
        if (! $this->currentOrder) {
            return false;
        }

        foreach ($this->currentOrder->items as $item) {
            if (empty($this->checkedItems[$item->id])) {
                return false;
            }
        }

        return true;
    }

    public function toggleItem(int $itemId): void
    {
        $this->checkedItems[$itemId] = ! ($this->checkedItems[$itemId] ?? false);

        unset($this->allItemsChecked);
    }

    public function nextOrder(): void
    {
        if (! ($this->allItemsChecked && $this->confirmed)) {
            return;
        }

        $this->currentIndex++;
        $this->checkedItems = [];
        $this->confirmed = false;

        unset($this->currentOrder, $this->allItemsChecked);
    }

    public function skipOrder(): void
    {
        $this->currentIndex++;
        $this->checkedItems = [];
        $this->confirmed = false;

        unset($this->currentOrder, $this->allItemsChecked);
    }

    public function previousOrder(): void
    {
        if ($this->currentIndex > 0) {
            $this->currentIndex--;
            $this->checkedItems = [];
            $this->confirmed = false;

            unset($this->currentOrder, $this->allItemsChecked);
        }
    }

    // ── Address correction ────────────────────────────────────────────────────

    public function openAddressModal(): void
    {
        $order = $this->currentOrder;

        if (! $order) {
            return;
        }

        $address = $order->shippingAddress;

        $this->editProvinceId = $address?->province_id;
        $this->editDistrictId = $address?->district_id;
        $this->editAddressLine1 = $address?->address_line1 ?? '';

        $this->availableProvinces = Province::orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])
            ->all();

        $this->availableDistricts = $this->editProvinceId
            ? $this->loadDistricts($this->editProvinceId)
            : [];

        $this->showAddressModal = true;
    }

    public function updatedEditProvinceId(): void
    {
        $this->editDistrictId = null;
        $this->availableDistricts = $this->editProvinceId
            ? $this->loadDistricts($this->editProvinceId)
            : [];
    }

    public function saveAddress(): void
    {
        $this->validate([
            'editProvinceId' => 'required|integer',
            'editDistrictId' => 'required|integer',
            'editAddressLine1' => 'required|string|min:5',
        ], [
            'editProvinceId.required' => 'Lütfen il seçiniz.',
            'editDistrictId.required' => 'Lütfen ilçe seçiniz.',
            'editAddressLine1.required' => 'Adres satırı zorunludur.',
            'editAddressLine1.min' => 'Adres en az 5 karakter olmalıdır.',
        ]);

        $order = $this->currentOrder;
        $address = $order?->shippingAddress;

        if (! $address) {
            return;
        }

        $address->update([
            'province_id' => $this->editProvinceId,
            'district_id' => $this->editDistrictId,
            'address_line1' => $this->editAddressLine1,
        ]);

        unset($this->currentOrder);

        $this->showAddressModal = false;

        Notification::make()
            ->title('Adres güncellendi.')
            ->success()
            ->send();

        // Proceed to carrier selection with the corrected address
        $this->openCarrierModal();
    }

    // ── Carrier selection & shipment creation ─────────────────────────────────

    public function openCarrierModal(): void
    {
        $order = $this->currentOrder;

        if (! $order || $order->channel !== OrderChannel::SHOPIFY) {
            return;
        }

        // Validate address completeness before calling BasitKargo
        $address = $order->shippingAddress;

        if (! $address?->province_id || ! $address?->district_id) {
            $this->openAddressModal();

            return;
        }

        $integration = $this->resolveBasitKargoIntegration($order);

        // Look for a pre-existing BasitKargo shipment before creating a new one.
        // Primary match: Shopify foreignCode (GID) — ensures we re-use shipments
        // auto-created by the Shopify BasitKargo app without breaking the tracking link.
        if ($integration && $order->order_date && ! $order->shipping_aggregator_shipment_id) {
            $adapter = new BasitKargoAdapter($integration);

            try {
                $shopifyGid = $order->platformMappings()->value('platform_id');
                $existing = $shopifyGid
                    ? $adapter->findShipmentByForeignCode($shopifyGid, $order->order_date)
                    : null;
            } catch (\Exception $e) {
                // Log but don't block — fall through to creating a fresh shipment
                activity()
                    ->performedOn($order)
                    ->withProperties(['error' => $e->getMessage()])
                    ->log('basitkargo_pre_search_failed');
                $existing = null;
            }

            if ($existing && ! empty($existing['id'])) {
                if (! empty($existing['barcode'])) {
                    // Already labelled → link & open directly
                    $order->update([
                        'shipping_aggregator_integration_id' => $integration->id,
                        'shipping_aggregator_shipment_id' => $existing['id'],
                        'shipping_tracking_number' => $existing['barcode'],
                    ]);

                    unset($this->currentOrder);

                    $this->dispatch('open-label', url: route('admin.orders.basitkargo-label', $order->id));

                    Notification::make()
                        ->title('Mevcut gönderi bulundu ve bağlandı.')
                        ->success()
                        ->send();

                    return;
                }

                // Found without barcode (NEW from Shopify app) →
                // Only remember the integration; do NOT save the shipment ID.
                // createOutboundShipment will create a new labelled shipment
                // with the same foreignCode so the Shopify sync link is preserved,
                // and the Shopify-created shipment is left untouched.
                $order->update([
                    'shipping_aggregator_integration_id' => $integration->id,
                ]);

                unset($this->currentOrder);

                Notification::make()
                    ->title('Mevcut gönderi bulundu.')
                    ->body('Kargo firmasını seçerek etiketi oluşturun.')
                    ->info()
                    ->send();

                // Fall through to carrier modal so user picks a handler
            }
        }

        if ($integration) {
            $this->availableCarriers = (new BasitKargoAdapter($integration))->getHandlers();
        }

        if (empty($this->availableCarriers)) {
            $this->availableCarriers = [
                ['code' => 'SURAT',   'name' => 'Sürat Kargo'],
                ['code' => 'MNG',     'name' => 'MNG Kargo'],
                ['code' => 'YURTICI', 'name' => 'Yurtiçi Kargo'],
                ['code' => 'ARAS',    'name' => 'Aras Kargo'],
            ];
        }

        $this->selectedCarrier = 'SURAT';
        $this->showCarrierModal = true;
    }

    public function createShipmentForOrder(): void
    {
        $order = $this->currentOrder;

        if (! $order || $order->channel !== OrderChannel::SHOPIFY) {
            return;
        }

        $order->load(['shippingAddress.province', 'shippingAddress.district', 'customer']);

        $integration = $this->resolveBasitKargoIntegration($order);

        if (! $integration) {
            Notification::make()
                ->title('BasitKargo entegrasyonu bulunamadı.')
                ->danger()
                ->send();

            return;
        }

        try {
            $adapter = new BasitKargoAdapter($integration);

            $result = $adapter->createOutboundShipment($order, $this->selectedCarrier);

            $order->update([
                'shipping_aggregator_integration_id' => $integration->id,
                'shipping_aggregator_shipment_id' => $result['id'],
                'shipping_tracking_number' => $result['barcode'],
            ]);

            unset($this->currentOrder);

            $this->showCarrierModal = false;

            $this->dispatch('open-label', url: route('admin.orders.basitkargo-label', $order->id));

            Notification::make()
                ->title('Gönderi başarıyla oluşturuldu.')
                ->success()
                ->send();
        } catch (\RuntimeException $e) {
            Notification::make()
                ->title('Gönderi oluşturulamadı.')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function getLabelUrl(): ?string
    {
        if (! $this->currentOrder) {
            return null;
        }

        $order = $this->currentOrder;

        // Only show print button when both the shipment ID AND tracking number exist
        if (
            $order->channel === OrderChannel::SHOPIFY
            && $order->shipping_aggregator_shipment_id
            && $order->shipping_tracking_number
        ) {
            return route('admin.orders.basitkargo-label', $order->id);
        }

        if ($order->channel === OrderChannel::TRENDYOL) {
            return route('admin.orders.trendyol-label', $order->id);
        }

        return null;
    }

    public function needsShipmentCreation(): bool
    {
        $order = $this->currentOrder;

        return $order
            && $order->channel === OrderChannel::SHOPIFY
            && ! ($order->shipping_aggregator_shipment_id && $order->shipping_tracking_number);
    }

    public function isDone(): bool
    {
        return $this->totalOrders > 0 && $this->currentIndex >= $this->totalOrders;
    }

    private function resolveBasitKargoIntegration(Order $order): ?Integration
    {
        if ($order->shipping_aggregator_integration_id) {
            return $order->shippingAggregatorIntegration;
        }

        return Integration::where('provider', IntegrationProvider::BASIT_KARGO)
            ->where('is_active', true)
            ->first();
    }

    /** @return array<int, array{id: int, name: string}> */
    private function loadDistricts(int $provinceId): array
    {
        return District::where('province_id', $provinceId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name])
            ->all();
    }
}
