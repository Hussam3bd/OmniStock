<x-filament-panels::page>
    @if ($this->totalOrders === 0)
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-24 gap-4 text-center">
            <x-heroicon-o-check-circle class="w-20 h-20 text-success-500" />
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">All caught up!</h2>
            <p class="text-gray-500 dark:text-gray-400">No pending orders to prepare right now.</p>
            <x-filament::button wire:click="loadQueue" color="gray" icon="heroicon-o-arrow-path">
                Refresh
            </x-filament::button>
        </div>

    @elseif ($this->isDone())
        {{-- Completion state --}}
        <div class="flex flex-col items-center justify-center py-24 gap-4 text-center">
            <x-heroicon-o-sparkles class="w-20 h-20 text-success-500" />
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                All {{ $this->totalOrders }} orders prepared!
            </h2>
            <p class="text-gray-500 dark:text-gray-400">Great work. Ready for the next batch?</p>
            <x-filament::button wire:click="loadQueue" color="success" icon="heroicon-o-arrow-path">
                Refresh Queue
            </x-filament::button>
        </div>

    @else
        {{-- Progress bar --}}
        <div class="mb-6">
            <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                    Order {{ $this->currentIndex + 1 }} of {{ $this->totalOrders }}
                </span>
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $this->totalOrders - $this->currentIndex - 1 }} remaining
                </span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                <div
                    class="bg-primary-600 h-2.5 rounded-full transition-all duration-500"
                    style="width: {{ (($this->currentIndex + 1) / $this->totalOrders) * 100 }}%"
                ></div>
            </div>
        </div>

        @if ($order = $this->currentOrder)
            {{-- Order header --}}
            <x-filament::section class="mb-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        @if ($order->customer)
                            <p class="text-2xl font-black text-gray-900 dark:text-white mb-2">
                                {{ $order->customer->first_name }} {{ $order->customer->last_name }}
                            </p>
                        @endif
                        <div class="flex flex-wrap items-center gap-3">
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                                #{{ $order->order_number }}
                            </h2>
                            <x-filament::badge :color="$order->channel->getColor()">
                                {{ $order->channel->getLabel() }}
                            </x-filament::badge>
                            <x-filament::badge :color="$order->fulfillment_status->getColor()">
                                {{ $order->fulfillment_status->getLabel() }}
                            </x-filament::badge>
                        </div>
                        @if ($order->order_date)
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                {{ $order->order_date->format('d M Y, H:i') }}
                            </p>
                        @endif
                    </div>

                    <a
                        href="{{ \App\Filament\Resources\Order\Orders\OrderResource::getUrl('view', ['record' => $order]) }}"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0 p-2 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:text-gray-300 dark:hover:bg-gray-800 transition-colors"
                        title="Open order"
                    >
                        <x-heroicon-o-arrow-top-right-on-square class="w-5 h-5" />
                    </a>
                </div>
            </x-filament::section>

            {{-- Item cards --}}
            <div class="flex flex-col gap-3 mb-6">
                @foreach ($order->items as $item)
                    @php
                        $variant  = $item->productVariant;
                        $product  = $variant?->product;
                        $images   = $variant?->getMedia('images')->map(fn ($m) => $m->getUrl())->values()->all();
                        if (empty($images)) {
                            $images = $product?->getMedia('images')->map(fn ($m) => $m->getUrl())->values()->all() ?? [];
                        }
                        if (empty($images)) {
                            $images = ['/images/no-image.png'];
                        }
                        $isChecked = ! empty($this->checkedItems[$item->id]);
                    @endphp

                    <div
                        wire:click="toggleItem({{ $item->id }})"
                        class="flex items-center gap-4 p-4 sm:p-5 rounded-xl border-2 cursor-pointer transition-all select-none
                            {{ $isChecked
                                ? 'border-success-500 bg-success-50 dark:bg-success-950/20'
                                : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 hover:border-primary-300 dark:hover:border-primary-600' }}"
                    >
                        {{-- Image / slider --}}
                        <div
                            class="shrink-0 relative"
                            x-data="{ current: 0 }"
                            @click.stop
                        >
                            <div class="w-36 h-36 sm:w-44 sm:h-44 rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700 relative">
                                @foreach ($images as $i => $imgUrl)
                                    <img
                                        src="{{ $imgUrl }}"
                                        alt="{{ $product?->name }}"
                                        x-show="current === {{ $i }}"
                                        x-transition:enter="transition-opacity duration-200"
                                        x-transition:enter-start="opacity-0"
                                        x-transition:enter-end="opacity-100"
                                        class="absolute inset-0 w-full h-full object-cover"
                                    />
                                @endforeach
                            </div>

                            @if (count($images) > 1)
                                <button
                                    @click="current = (current - 1 + {{ count($images) }}) % {{ count($images) }}"
                                    class="absolute left-1 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full bg-black/40 hover:bg-black/60 text-white flex items-center justify-center transition-colors"
                                >
                                    <x-heroicon-s-chevron-left class="w-3.5 h-3.5" />
                                </button>
                                <button
                                    @click="current = (current + 1) % {{ count($images) }}"
                                    class="absolute right-1 top-1/2 -translate-y-1/2 w-6 h-6 rounded-full bg-black/40 hover:bg-black/60 text-white flex items-center justify-center transition-colors"
                                >
                                    <x-heroicon-s-chevron-right class="w-3.5 h-3.5" />
                                </button>
                                <div class="absolute bottom-1.5 left-1/2 -translate-x-1/2 flex gap-1">
                                    @foreach ($images as $i => $_)
                                        <button
                                            @click="current = {{ $i }}"
                                            :class="current === {{ $i }} ? 'bg-white' : 'bg-white/50'"
                                            class="w-1.5 h-1.5 rounded-full transition-colors"
                                        ></button>
                                    @endforeach
                                </div>
                            @endif
                        </div>

                        <div class="flex-1 min-w-0">
                            <p class="text-base sm:text-lg font-semibold text-gray-900 dark:text-white leading-tight">
                                {{ $product?->name ?? 'Unknown Product' }}
                            </p>

                            @if ($variant?->optionValues->isNotEmpty())
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    @foreach ($variant->optionValues as $optionValue)
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-gray-100 dark:bg-gray-800 text-sm font-medium text-gray-800 dark:text-gray-200">
                                            <span class="text-gray-400 dark:text-gray-500 text-xs uppercase tracking-wide">
                                                {{ $optionValue->variantOption?->name }}
                                            </span>
                                            {{ $optionValue->value }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif

                            <p class="mt-1.5 text-xs text-gray-400 dark:text-gray-500 font-mono">
                                SKU: {{ $variant?->sku ?? '—' }}
                                @if ($variant?->barcode)
                                    · {{ $variant->barcode }}
                                @endif
                            </p>
                        </div>

                        <div class="shrink-0 text-center px-2">
                            <div class="text-4xl sm:text-5xl font-black leading-none
                                {{ $isChecked ? 'text-success-600 dark:text-success-400' : 'text-gray-900 dark:text-white' }}">
                                ×{{ $item->quantity }}
                            </div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">units</div>
                        </div>

                        <div class="shrink-0">
                            <div class="w-9 h-9 rounded-full border-2 flex items-center justify-center transition-all
                                {{ $isChecked
                                    ? 'border-success-500 bg-success-500'
                                    : 'border-gray-300 dark:border-gray-600' }}">
                                @if ($isChecked)
                                    <x-heroicon-s-check class="w-5 h-5 text-white" />
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Shipping label actions --}}
            <div class="mb-6">
                @if ($this->getLabelUrl())
                    <x-filament::button
                        tag="a"
                        :href="$this->getLabelUrl()"
                        target="_blank"
                        rel="noopener"
                        icon="heroicon-o-printer"
                        size="lg"
                    >
                        Print Shipping Label
                    </x-filament::button>
                @elseif ($this->needsShipmentCreation())
                    <x-filament::button
                        wire:click="openCarrierModal"
                        wire:loading.attr="disabled"
                        wire:target="openCarrierModal"
                        icon="heroicon-o-truck"
                        size="lg"
                    >
                        <span wire:loading.remove wire:target="openCarrierModal">Create Shipping Label</span>
                        <span wire:loading wire:target="openCarrierModal">Checking…</span>
                    </x-filament::button>
                @elseif ($order->shipping_tracking_url)
                    <x-filament::button
                        tag="a"
                        :href="$order->shipping_tracking_url"
                        target="_blank"
                        rel="noopener"
                        color="gray"
                        icon="heroicon-o-arrow-top-right-on-square"
                        size="lg"
                    >
                        Open Tracking Page
                    </x-filament::button>
                @else
                    <x-filament::button color="gray" icon="heroicon-o-printer" size="lg" disabled>
                        Label Not Available
                    </x-filament::button>
                @endif
            </div>

            {{-- ── Address correction modal ──────────────────────────────── --}}
            @if ($showAddressModal)
                <div
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                    x-data
                    x-on:keydown.escape.window="$wire.showAddressModal = false"
                >
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
                        <div class="flex items-start gap-3 mb-5">
                            <div class="p-2 rounded-full bg-warning-100 dark:bg-warning-900/30 shrink-0">
                                <x-heroicon-o-exclamation-triangle class="w-5 h-5 text-warning-600 dark:text-warning-400" />
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-gray-900 dark:text-white">
                                    Eksik Teslimat Adresi
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                                    Kargo etiketi oluşturabilmek için il ve ilçe bilgisi gereklidir.
                                </p>
                            </div>
                        </div>

                        <div class="flex flex-col gap-4 mb-6">
                            {{-- Province --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    İl <span class="text-danger-500">*</span>
                                </label>
                                <select
                                    wire:model.live="editProvinceId"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                >
                                    <option value="">— İl seçiniz —</option>
                                    @foreach ($availableProvinces as $province)
                                        <option value="{{ $province['id'] }}" @selected($editProvinceId == $province['id'])>
                                            {{ $province['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- District --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    İlçe <span class="text-danger-500">*</span>
                                </label>
                                <select
                                    wire:model.live="editDistrictId"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                                    @disabled(! $editProvinceId)
                                >
                                    <option value="">— İlçe seçiniz —</option>
                                    @foreach ($availableDistricts as $district)
                                        <option value="{{ $district['id'] }}" @selected($editDistrictId == $district['id'])>
                                            {{ $district['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            {{-- Address line --}}
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    Adres Satırı <span class="text-danger-500">*</span>
                                </label>
                                <textarea
                                    wire:model.live="editAddressLine1"
                                    rows="2"
                                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm text-gray-900 dark:text-white px-3 py-2 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 resize-none"
                                    placeholder="Cadde, Sokak, Bina No…"
                                ></textarea>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-filament::button
                                color="gray"
                                wire:click="$set('showAddressModal', false)"
                            >
                                İptal
                            </x-filament::button>
                            <x-filament::button
                                wire:click="saveAddress"
                                wire:loading.attr="disabled"
                                wire:target="saveAddress"
                                icon="heroicon-o-check"
                            >
                                <span wire:loading.remove wire:target="saveAddress">Kaydet & Devam Et</span>
                                <span wire:loading wire:target="saveAddress">Kaydediliyor…</span>
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- ── Carrier selection modal ──────────────────────────────── --}}
            @if ($showCarrierModal)
                <div
                    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
                    x-data
                    x-on:keydown.escape.window="$wire.showCarrierModal = false"
                >
                    <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Kargo Firması Seçin</h3>

                        <div class="flex flex-col gap-2 mb-6">
                            @foreach ($availableCarriers as $carrier)
                                <label class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all
                                    {{ $selectedCarrier === $carrier['code']
                                        ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/20'
                                        : 'border-gray-200 dark:border-gray-700 hover:border-primary-300' }}">
                                    <input
                                        type="radio"
                                        wire:model.live="selectedCarrier"
                                        value="{{ $carrier['code'] }}"
                                        class="text-primary-600 focus:ring-primary-500"
                                    />
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $carrier['name'] }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="flex justify-end gap-3">
                            <x-filament::button
                                color="gray"
                                wire:click="$set('showCarrierModal', false)"
                            >
                                İptal
                            </x-filament::button>
                            <x-filament::button
                                wire:click="createShipmentForOrder"
                                wire:loading.attr="disabled"
                                wire:target="createShipmentForOrder"
                                icon="heroicon-o-truck"
                            >
                                <span wire:loading.remove wire:target="createShipmentForOrder">Gönderi Oluştur</span>
                                <span wire:loading wire:target="createShipmentForOrder">Oluşturuluyor…</span>
                            </x-filament::button>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Confirmation & navigation --}}
            <x-filament::section>
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                    <label
                        class="flex items-center gap-3 cursor-pointer select-none
                            {{ $this->allItemsChecked ? '' : 'opacity-40 pointer-events-none' }}"
                    >
                        <input
                            type="checkbox"
                            wire:model.live="confirmed"
                            class="w-5 h-5 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                        />
                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                            I confirm all items are packed correctly
                        </span>
                    </label>

                    <div class="flex items-center gap-3">
                        @if ($this->currentIndex > 0)
                            <x-filament::button
                                wire:click="previousOrder"
                                color="gray"
                                icon="heroicon-o-arrow-left"
                            >
                                Previous
                            </x-filament::button>
                        @endif

                        @if ($this->currentIndex < $this->totalOrders - 1)
                            <x-filament::button
                                wire:click="skipOrder"
                                color="gray"
                                icon="heroicon-o-forward"
                                icon-position="after"
                            >
                                Skip
                            </x-filament::button>
                        @endif

                        @if ($this->currentIndex < $this->totalOrders - 1)
                            <x-filament::button
                                wire:click="nextOrder"
                                :disabled="! ($this->allItemsChecked && $this->confirmed)"
                                icon="heroicon-o-arrow-right"
                                icon-position="after"
                            >
                                Next Order
                            </x-filament::button>
                        @else
                            <x-filament::button
                                wire:click="nextOrder"
                                :disabled="! ($this->allItemsChecked && $this->confirmed)"
                                color="success"
                                icon="heroicon-o-check-circle"
                            >
                                Complete
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>

<script>
    document.addEventListener('livewire:init', function () {
        Livewire.on('open-label', function ({ url }) {
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    });
</script>
