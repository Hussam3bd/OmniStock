<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($this->getProviders() as $type => $providers)
            <div>
                <h2 class="text-lg font-semibold mb-4">
                    {{ match($type) {
                        'sales_channel' => __('Sales Channels'),
                        'shipping_provider' => __('Shipping Providers'),
                        'payment_gateway' => __('Payment Gateways'),
                        'invoice_provider' => __('Invoice Providers'),
                        default => ucfirst(str_replace('_', ' ', $type))
                    } }}
                </h2>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    @foreach ($providers as $providerKey => $provider)
                        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6 hover:shadow-lg transition">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2 rounded-lg bg-{{ $provider['color'] }}-100 dark:bg-{{ $provider['color'] }}-900">
                                        <x-filament::icon
                                            :icon="$provider['icon']"
                                            class="h-6 w-6 text-{{ $provider['color'] }}-600 dark:text-{{ $provider['color'] }}-400"
                                        />
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-gray-900 dark:text-white">
                                            {{ $provider['name'] }}
                                        </h3>
                                    </div>
                                </div>
                            </div>

                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                {{ $provider['description'] }}
                            </p>

                            <div class="flex items-center gap-2">
                                <x-filament::button
                                    :href="route('filament.admin.resources.integration.integrations.create', ['type' => $type, 'provider' => $providerKey])"
                                    tag="a"
                                    size="sm"
                                    color="{{ $provider['color'] }}"
                                >
                                    {{ __('Install') }}
                                </x-filament::button>

                                @if ($provider['documentation_url'])
                                    <x-filament::button
                                        :href="$provider['documentation_url']"
                                        tag="a"
                                        size="sm"
                                        color="gray"
                                        outlined
                                        target="_blank"
                                        icon="heroicon-o-arrow-top-right-on-square"
                                    >
                                        {{ __('Docs') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
