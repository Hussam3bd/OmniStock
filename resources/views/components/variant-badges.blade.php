@props(['productTitle', 'optionValues'])

<div class="flex flex-col gap-1.5">
    <span class="font-medium text-gray-950 dark:text-white">{{ $productTitle }}</span>
    <div class="flex flex-wrap gap-1">
        @foreach($optionValues as $optionValue)
            <span class="inline-flex items-center gap-1 rounded-md bg-gray-50 dark:bg-gray-500/20 px-2 py-1 text-xs font-medium text-gray-600 dark:text-gray-400 ring-1 ring-inset ring-gray-500/10 dark:ring-gray-500/30">
                {{ $optionValue->value }}
            </span>
        @endforeach
    </div>
</div>
