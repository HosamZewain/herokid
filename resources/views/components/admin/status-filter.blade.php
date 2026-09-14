@props(['name', 'label', 'options', 'selected' => []])

<div class="relative" data-status-filter="{{ $name }}" data-empty-label="{{ $options[''] }}">
    <label id="{{ $name }}-label" class="mb-1.5 block text-xs font-black text-gray-600">{{ $label }}</label>
    <details data-status-details>
    <summary
            aria-controls="{{ $name }}-options" aria-labelledby="{{ $name }}-label {{ $name }}-summary"
            class="flex w-full cursor-pointer list-none items-center justify-between gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2 text-right text-sm marker:hidden">
        <span id="{{ $name }}-summary" data-status-summary class="truncate">{{ count($selected) > 1 ? count($selected).' حالات محددة' : (count($selected) ? ($options[$selected[0]] ?? '') : $options['']) }}</span>
        <span aria-hidden="true">⌄</span>
    </summary>
    <div id="{{ $name }}-options"
         class="absolute right-0 z-50 mt-2 w-72 max-w-[85vw] rounded-xl border border-gray-200 bg-white p-2 shadow-xl">
        <button type="button" data-status-clear class="mb-1 w-full rounded-lg px-3 py-2 text-right text-xs font-bold text-indigo-700 hover:bg-indigo-50">كل الحالات / مسح الاختيار</button>
        <div class="max-h-64 overflow-y-auto" role="group" aria-labelledby="{{ $name }}-label">
            @foreach($options as $value => $optionLabel)
                @if($value !== '')
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-indigo-50">
                        <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" data-status-label="{{ $optionLabel }}" @checked(in_array($value, $selected, true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>{{ $optionLabel }}</span>
                    </label>
                @endif
            @endforeach
        </div>
        <p class="mt-1 border-t border-gray-100 px-3 pt-2 text-xs text-gray-500">اختر حالة أو أكثر ثم اضغط تطبيق.</p>
    </div>
    </details>
</div>
