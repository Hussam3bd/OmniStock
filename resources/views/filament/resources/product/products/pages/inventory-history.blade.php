<div class="space-y-4">
    @php
        $movements = $record->inventoryMovements()
            ->with('order')
            ->orderBy('created_at', 'desc')
            ->get();
    @endphp

    @if($movements->isEmpty())
        <div class="flex flex-col items-center justify-center p-6">
            <div class="text-center">
                <x-filament::icon icon="heroicon-o-clock" class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">{{ __('No Inventory History') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('No stock movements have been recorded for this variant') }}</p>
            </div>
        </div>
    @else
        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="overflow-x-auto">
                <table class="w-full table-auto divide-y divide-gray-200 dark:divide-white/5">
                    <thead>
                        <tr class="bg-gray-50 dark:bg-white/5">
                            <th class="px-3 py-3.5 text-start">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('Date') }}
                                </span>
                            </th>
                            <th class="px-3 py-3.5 text-start">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('Type') }}
                                </span>
                            </th>
                            <th class="px-3 py-3.5 text-start">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('Change') }}
                                </span>
                            </th>
                            <th class="px-3 py-3.5 text-start">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('Before') }}
                                </span>
                            </th>
                            <th class="px-3 py-3.5 text-start">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('After') }}
                                </span>
                            </th>
                            <th class="px-3 py-3.5 text-start">
                                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                                    {{ __('Reference') }}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 whitespace-nowrap dark:divide-white/5">
                        @foreach($movements as $movement)
                            <tr>
                                <td class="px-3 py-4">
                                    <div class="flex flex-col">
                                        <span class="text-sm leading-6 text-gray-950 dark:text-white">
                                            {{ $movement->created_at->format('M d, Y') }}
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $movement->created_at->format('H:i:s') }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-3 py-4">
                                    <x-filament::badge
                                        :color="match($movement->type) {
                                            'received', 'returned' => 'success',
                                            'sold' => 'primary',
                                            'damaged' => 'danger',
                                            default => 'gray',
                                        }"
                                    >
                                        {{ __(ucfirst($movement->type)) }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="text-sm font-medium {{ $movement->quantity >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $movement->quantity >= 0 ? '+' : '' }}{{ $movement->quantity }}
                                    </span>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ $movement->quantity_before }}
                                    </span>
                                </td>
                                <td class="px-3 py-4">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">
                                        {{ $movement->quantity_after }}
                                    </span>
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex flex-col max-w-xs">
                                        @if($movement->order_id)
                                            <a href="{{ route('filament.admin.resources.order.orders.edit', ['record' => $movement->order_id]) }}"
                                               class="text-sm text-primary-600 hover:underline dark:text-primary-400">
                                                {{ __('Order #:id', ['id' => $movement->order_id]) }}
                                            </a>
                                        @endif
                                        @if($movement->reference)
                                            <span class="text-sm text-gray-600 dark:text-gray-400">
                                                {{ $movement->reference }}
                                            </span>
                                        @endif
                                        @if(!$movement->order_id && !$movement->reference)
                                            <span class="text-sm text-gray-400 dark:text-gray-500">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="text-sm font-medium text-gray-900 dark:text-white">{{ __('Current Stock Level') }}</h4>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('As of now') }}</p>
                </div>
                <div class="text-right">
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $record->inventory_quantity }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('units') }}</p>
                </div>
            </div>
        </div>
    @endif
</div>
