<x-filament-panels::page>
    {{-- Stats Overview --}}
    <div class="grid gap-4 grid-cols-1 md:grid-cols-4 mb-6">
        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('Total Products') }}
                </div>
                <div class="text-3xl font-bold">
                    {{ $totalProducts }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Need reordering') }}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-danger-500">
                    {{ __('Critical') }}
                </div>
                <div class="text-3xl font-bold text-danger-600 dark:text-danger-400">
                    {{ $criticalProducts }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('≤ 3 days of stock') }}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-warning-500">
                    {{ __('Urgent') }}
                </div>
                <div class="text-3xl font-bold text-warning-600 dark:text-warning-400">
                    {{ $urgentProducts }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('≤ 7 days of stock') }}
                </div>
            </div>
        </x-filament::card>

        <x-filament::card>
            <div class="space-y-2">
                <div class="text-sm font-medium text-success-500">
                    {{ __('Increasing Demand') }}
                </div>
                <div class="text-3xl font-bold text-success-600 dark:text-success-400">
                    {{ $increasingDemand }}
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Growing >20%') }}
                </div>
            </div>
        </x-filament::card>
    </div>

    {{-- Table --}}
    {{ $this->table }}
</x-filament-panels::page>
