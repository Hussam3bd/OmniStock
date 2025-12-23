<x-filament-panels::page>
    {{-- Stats Overview --}}
    <div class="grid gap-4 grid-cols-1 md:grid-cols-4 mb-6">
        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('Active Variants') }}
                </div>
                <div class="text-3xl font-bold">
                    {{ number_format($totalVariants) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('With sales history') }}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-success-500">
                    {{ __('Total Items Sold') }}
                </div>
                <div class="text-3xl font-bold text-success-600 dark:text-success-400">
                    {{ number_format($totalItemsSold) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Completed orders only') }}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-danger-500">
                    {{ __('Total Returns') }}
                </div>
                <div class="text-3xl font-bold text-danger-600 dark:text-danger-400">
                    {{ number_format($totalItemsReturned) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Returned items') }}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-info-500">
                    {{ __('Current Stock') }}
                </div>
                <div class="text-3xl font-bold text-info-600 dark:text-info-400">
                    {{ number_format($totalCurrentStock) }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Available inventory') }}
                </div>
            </div>
        </x-filament::card>
    </div>

    {{-- Table --}}
    {{ $this->table }}
</x-filament-panels::page>
