<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Selected Variants Table --}}
        @if(count($this->selectedVariants))
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('Selected Variants') }}
                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                        ({{ count($this->selectedVariants) }} {{ __('variants') }}, {{ $this->totalLabels }} {{ __('labels') }})
                    </span>
                </x-slot>
                <x-slot name="headerEnd">
                    <x-filament::button color="danger" size="sm" wire:click="clearAll" outlined>
                        {{ __('Clear All') }}
                    </x-filament::button>
                </x-slot>

                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
                        <thead>
                            <tr>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">{{ __('Product') }}</th>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">{{ __('SKU') }}</th>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">{{ __('Options') }}</th>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">{{ __('Barcode') }}</th>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">{{ __('Stock') }}</th>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white">{{ __('Copies') }}</th>
                                <th class="fi-ta-header-cell px-3 py-3.5 text-start text-sm font-semibold text-gray-950 dark:text-white"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                            @foreach($this->selectedVariantDetails as $variant)
                                <tr wire:key="variant-{{ $variant['id'] }}">
                                    <td class="fi-ta-cell px-3 py-4 text-sm text-gray-700 dark:text-gray-300">
                                        <div class="flex items-center gap-3">
                                            @if($variant['image'])
                                                <img src="{{ $variant['image'] }}" class="h-10 w-10 rounded object-cover shrink-0" alt="" />
                                            @else
                                                <div class="h-10 w-10 rounded bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0">
                                                    <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5 text-gray-400" />
                                                </div>
                                            @endif
                                            <div class="min-w-0">
                                                <div class="font-medium truncate">{{ $variant['model_code'] }}</div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ \Illuminate\Support\Str::limit($variant['product_title'], 40) }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="fi-ta-cell px-3 py-4 text-sm font-mono text-gray-700 dark:text-gray-300">{{ $variant['sku'] }}</td>
                                    <td class="fi-ta-cell px-3 py-4 text-sm">
                                        <div class="flex flex-wrap gap-1">
                                            @foreach(array_slice($variant['options'], 0, 3) as $option)
                                                <x-filament::badge size="sm">{{ $option }}</x-filament::badge>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="fi-ta-cell px-3 py-4 text-sm font-mono text-gray-700 dark:text-gray-300">{{ $variant['barcode'] ?? '—' }}</td>
                                    <td class="fi-ta-cell px-3 py-4 text-sm text-gray-700 dark:text-gray-300">{{ $variant['stock'] }}</td>
                                    <td class="fi-ta-cell px-3 py-4">
                                        <x-filament::input.wrapper class="w-24">
                                            <x-filament::input
                                                type="number"
                                                min="1"
                                                :value="$variant['quantity']"
                                                wire:change="updateQuantity({{ $variant['id'] }}, $event.target.value)"
                                            />
                                        </x-filament::input.wrapper>
                                    </td>
                                    <td class="fi-ta-cell px-3 py-4">
                                        <x-filament::icon-button
                                            icon="heroicon-o-x-mark"
                                            color="danger"
                                            size="sm"
                                            wire:click="removeVariant({{ $variant['id'] }})"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>

            {{-- Label Settings & Print --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('Label Settings') }}</x-slot>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 items-end">
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('Label Preset') }}</label>
                        <select wire:model.live="labelPreset" class="fi-select-input mt-1 block w-full rounded-lg border-gray-300 shadow-sm transition duration-75 focus:border-primary-500 focus:ring-1 focus:ring-inset focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
                            @foreach($this->labelPresetsOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('Width (mm)') }}</label>
                        <x-filament::input.wrapper class="mt-1">
                            <x-filament::input type="number" step="0.1" wire:model.live="labelWidth" />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('Height (mm)') }}</label>
                        <x-filament::input.wrapper class="mt-1">
                            <x-filament::input type="number" step="0.1" wire:model.live="labelHeight" />
                        </x-filament::input.wrapper>
                    </div>
                    <div>
                        <x-filament::button
                            icon="heroicon-o-printer"
                            size="lg"
                            class="w-full"
                            x-on:click="
                                const url = new URL('{{ route('admin.barcode-labels.print') }}');
                                url.searchParams.set('variants', JSON.stringify($wire.selectedVariants));
                                url.searchParams.set('width', $wire.labelWidth);
                                url.searchParams.set('height', $wire.labelHeight);
                                window.open(url.toString(), '_blank');
                            "
                        >
                            {{ __('Print Labels') }} ({{ $this->totalLabels }})
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @else
            <div class="text-center py-12">
                <x-filament::icon icon="heroicon-o-printer" class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">{{ __('No variants selected') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Use the buttons above to add products or individual variants.') }}</p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
