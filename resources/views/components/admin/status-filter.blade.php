@props(['name', 'label', 'options', 'selected' => []])

<div class="relative" data-status-filter="{{ $name }}"
     x-data="{ open: false, selected: @js($selected), labels: @js($options) }"
     @click.outside="open = false" @keydown.escape.prevent.stop="open = false; $refs.trigger.focus()">
    <label id="{{ $name }}-label" class="mb-1.5 block text-xs font-black text-gray-600">{{ $label }}</label>
    <button type="button" x-ref="trigger" @click="open = !open" :aria-expanded="open"
            aria-controls="{{ $name }}-options" aria-labelledby="{{ $name }}-label {{ $name }}-summary"
            class="flex w-full items-center justify-between gap-1 rounded-xl border border-gray-200 bg-white px-3 py-2 text-right text-sm">
        <span id="{{ $name }}-summary" class="truncate" x-text="selected.length === 0 ? labels[''] : (selected.length === 1 ? labels[selected[0]] : selected.length + ' حالات محددة')">{{ count($selected) ? implode('، ', array_intersect_key($options, array_flip($selected))) : $options[''] }}</span>
        <span aria-hidden="true">⌄</span>
    </button>
    <div id="{{ $name }}-options" x-show="open" x-cloak
         class="absolute right-0 z-50 mt-2 w-72 max-w-[85vw] rounded-xl border border-gray-200 bg-white p-2 shadow-xl">
        <button type="button" @click="selected = []" class="mb-1 w-full rounded-lg px-3 py-2 text-right text-xs font-bold text-indigo-700 hover:bg-indigo-50">كل الحالات / مسح الاختيار</button>
        <div class="max-h-64 overflow-y-auto" role="group" aria-labelledby="{{ $name }}-label">
            @foreach($options as $value => $optionLabel)
                @if($value !== '')
                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-indigo-50">
                        <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" x-model="selected" @checked(in_array($value, $selected, true)) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        <span>{{ $optionLabel }}</span>
                    </label>
                @endif
            @endforeach
        </div>
        <p class="mt-1 border-t border-gray-100 px-3 pt-2 text-xs text-gray-500">اختر حالة أو أكثر ثم اضغط تطبيق.</p>
    </div>
</div>
