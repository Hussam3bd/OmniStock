<x-filament-panels::page>
    <form wire:submit="receive">
        {{ $this->schema }}

        <div class="mt-6">
            @foreach ($this->getFormActions() as $action)
                {{ $action }}
            @endforeach
        </div>
    </form>
</x-filament-panels::page>
