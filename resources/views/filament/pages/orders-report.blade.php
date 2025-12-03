<x-filament-panels::page>
    <x-filament-widgets::widgets
        :widgets="$this->getWidgets()"
        :columns="[
            'sm' => 2,
            'lg' => 4,
        ]"
    />
</x-filament-panels::page>
