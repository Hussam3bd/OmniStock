<x-filament-widgets::widget>
    @php
        $grouped = $this->getGroupedItems();
        $messageText = $this->getMessageText();
    @endphp

    <x-filament::section>
        <x-slot name="heading">
            {{ __('Committed Items Needing Restock') }}
        </x-slot>

        <x-slot name="description">
            {{ __('Items sold in pending orders that exceed current available stock.') }}
        </x-slot>

        <x-slot name="headerEnd">
            @if($grouped->isNotEmpty())
                <div x-data="{ copied: false }">
                    <button
                        type="button"
                        x-show="!copied"
                        x-on:click="
                            navigator.clipboard.writeText(@js($messageText));
                            copied = true;
                            setTimeout(() => copied = false, 2000);
                        "
                        class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-gray-700 shadow-sm ring-1 ring-gray-300 hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-600 dark:hover:bg-gray-700"
                    >
                        <x-filament::icon icon="heroicon-o-clipboard-document" class="h-4 w-4" />
                        {{ __('Copy Message') }}
                    </button>
                    <button
                        type="button"
                        x-show="copied"
                        x-cloak
                        class="inline-flex items-center gap-1.5 rounded-lg bg-success-50 px-3 py-1.5 text-sm font-medium text-success-700 shadow-sm ring-1 ring-success-300 dark:bg-success-950 dark:text-success-400 dark:ring-success-800"
                    >
                        <x-filament::icon icon="heroicon-o-check" class="h-4 w-4" />
                        {{ __('Copied!') }}
                    </button>
                </div>
            @endif
        </x-slot>

        @if($grouped->isEmpty())
            <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                {{ __('All committed items are covered by available stock.') }}
            </div>
        @else
            <div class="space-y-6">
                @foreach($grouped as $productTitle => $colorGroups)
                    <div>
                        <p class="mb-2 text-sm font-semibold text-gray-950 dark:text-white">{{ $productTitle }}</p>

                        <div class="space-y-3 pl-2">
                            @foreach($colorGroups as $color => $sizes)
                                <div>
                                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $color }}</p>
                                    <div class="mt-1 space-y-0.5 pl-3">
                                        @foreach($sizes as $size => $needed)
                                            <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                <span class="text-gray-400">-</span>
                                                <span>{{ $size }}</span>
                                                <span class="text-gray-400">:</span>
                                                <x-filament::badge color="danger">{{ $needed }}</x-filament::badge>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
