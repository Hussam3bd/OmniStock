<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            📂 {{ __('Breakdown by Category') }}
        </x-slot>

        <x-slot name="description">
            @if(isset($this->pageFilters['startDate']) && isset($this->pageFilters['endDate']))
                {{ \Carbon\Carbon::parse($this->pageFilters['startDate'])->format('M d, Y') }} - {{ \Carbon\Carbon::parse($this->pageFilters['endDate'])->format('M d, Y') }}
            @else
                {{ __('This month - See where your money is coming from and going to') }}
            @endif
        </x-slot>

        @php
            $categories = $this->getCategoryBreakdown();
        @endphp

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="text-sm font-semibold text-green-700 dark:text-green-400 mb-3">{{ __('Income Categories') }}</h4>
                <div class="space-y-2">
                    @foreach(collect($categories)->where('type', 'Income')->sortByDesc('amount_raw') as $category)
                        <div class="flex justify-between items-center p-3 bg-green-50 dark:bg-green-900/10 rounded-lg border border-green-100 dark:border-green-900/30">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $category['category'] }}</span>
                            <span class="text-sm font-semibold text-green-700 dark:text-green-400">{{ $category['amount'] }}</span>
                        </div>
                    @endforeach
                    @if(collect($categories)->where('type', 'Income')->isEmpty())
                        <div class="text-sm text-gray-500 dark:text-gray-500 italic p-3">{{ __('No income in this period') }}</div>
                    @endif
                </div>
            </div>

            <div>
                <h4 class="text-sm font-semibold text-red-700 dark:text-red-400 mb-3">{{ __('Expense Categories') }}</h4>
                <div class="space-y-2">
                    @foreach(collect($categories)->where('type', 'Expense')->sortByDesc('amount_raw') as $category)
                        <div class="flex justify-between items-center p-3 bg-red-50 dark:bg-red-900/10 rounded-lg border border-red-100 dark:border-red-900/30">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ $category['category'] }}</span>
                            <span class="text-sm font-semibold text-red-700 dark:text-red-400">{{ $category['amount'] }}</span>
                        </div>
                    @endforeach
                    @if(collect($categories)->where('type', 'Expense')->isEmpty())
                        <div class="text-sm text-gray-500 dark:text-gray-500 italic p-3">{{ __('No expenses in this period') }}</div>
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
