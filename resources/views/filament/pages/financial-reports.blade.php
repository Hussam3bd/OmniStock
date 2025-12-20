<x-filament-panels::page>
    <x-filament-widgets::widgets
        :widgets="$this->getWidgets()"
        :columns="[
            'sm' => 1,
            'lg' => 3,
        ]"
    />

    {{-- Understanding Guide --}}
    <x-filament::section collapsed class="mt-6">
        <x-slot name="heading">
            ℹ️ {{ __('Understanding Your Financial Reports') }}
        </x-slot>

        <div class="prose dark:prose-invert max-w-none">
            <h4>{{ __("What's the difference between Business Performance and Cash Flow?") }}</h4>

            <p><strong>{{ __('Business Performance') }}</strong> {{ __('shows your actual business profit/loss:') }}</p>
            <ul>
                <li>✅ {{ __('Includes: Sales, refunds, business expenses, fees') }}</li>
                <li>❌ {{ __('Excludes: Money you moved between your own accounts (like paying credit card from bank)') }}</li>
                <li>{{ __('Use this to understand: "How much money did my business actually make?"') }}</li>
            </ul>

            <p><strong>{{ __('Total Cash Position') }}</strong> {{ __('shows all money across your accounts:') }}</p>
            <ul>
                <li>{{ __('This is how much cash you have available right now') }}</li>
                <li>{{ __('Use this to understand: "Can I afford this purchase?"') }}</li>
            </ul>

            <h4>{{ __('Example:') }}</h4>
            <p>{{ __('You earned TRY 10,000 from sales and paid TRY 5,000 in expenses. Then you paid your credit card TRY 3,000 from your bank account.') }}</p>

            <ul>
                <li><strong>{{ __('Business Profit:') }}</strong> {{ __('TRY 5,000 (10,000 - 5,000)') }} ✅ {{ __('This is your real profit') }}</li>
                <li><strong>{{ __('Internal Transfers:') }}</strong> {{ __('TRY 3,000 (the credit card payment - not counted in profit)') }}</li>
            </ul>

            <h4>{{ __('What should I focus on?') }}</h4>
            <ul>
                <li><strong>{{ __('Business Profit:') }}</strong> {{ __('Use this for tax reporting and understanding profitability') }}</li>
                <li><strong>{{ __('Account Balances:') }}</strong> {{ __('Use this to know how much cash you have available') }}</li>
                <li><strong>{{ __('Category Breakdown:') }}</strong> {{ __('Use this to find areas where you can cut costs or grow revenue') }}</li>
            </ul>
        </div>
    </x-filament::section>
</x-filament-panels::page>
