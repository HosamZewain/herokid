@props([
    'permissionGroups',
    'selected' => [],
    'disabled' => false,
    'title' => 'صلاحيات إضافية مخصصة',
    'hint' => 'اختياري: افتح هذا القسم فقط إذا احتاج الموظف صلاحيات تزيد عن دوره الوظيفي.',
    'open' => false,
])

@php
    $selected = collect(old('permissions', $selected))->all();
@endphp

<input type="hidden" name="permissions_present" value="1">

<details class="group space-y-4 rounded-2xl border border-gray-100 bg-white p-4" @if($open) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-4">
        <div>
            <h3 class="text-base font-black text-gray-900">{{ $title }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
        </div>
        <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-black text-gray-600 group-open:hidden">عرض</span>
        <span class="hidden rounded-full bg-gray-100 px-3 py-1 text-xs font-black text-gray-600 group-open:inline">إخفاء</span>
    </summary>

<div class="mt-5 space-y-4">
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-xs text-gray-500">يمكنك تعيين الصلاحيات التي تملكها فقط. الصلاحيات الحساسة مميزة باللون الأحمر.</p>
        </div>
    </div>
    <x-input-error :messages="$errors->get('permissions')" class="mt-2" />

    @foreach($permissionGroups as $group)
        <section class="rounded-2xl border border-gray-100 bg-slate-50 p-4" data-permission-group>
            <div class="mb-3 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div>
                    <h4 class="font-black text-gray-900">{{ $group['name_ar'] }}</h4>
                    <p class="text-xs text-gray-400">{{ $group['name_en'] }}</p>
                </div>
                @unless($disabled)
                    <div class="flex gap-2">
                        <button type="button" class="rounded-lg border border-indigo-200 bg-white px-3 py-1.5 text-xs font-bold text-indigo-700" data-select-group>اختيار الكل</button>
                        <button type="button" class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-bold text-gray-600" data-clear-group>إلغاء الكل</button>
                    </div>
                @endunless
            </div>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach($group['permissions'] as $permission)
                    @php
                        $isSensitive = (bool) ($permission['sensitive'] ?? false);
                    @endphp
                    <label class="flex gap-3 rounded-xl border bg-white p-3 text-right {{ $isSensitive ? 'border-red-100' : 'border-gray-100' }}">
                        <input
                            type="checkbox"
                            name="permissions[]"
                            value="{{ $permission['key'] }}"
                            class="mt-1 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            @checked(in_array($permission['key'], $selected, true))
                            @disabled($disabled)
                            data-permission-checkbox
                        >
                        <span>
                            <span class="block text-sm font-black text-gray-900">
                                {{ $permission['name_ar'] }}
                                @if($isSensitive)
                                    <span class="mr-1 rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-black text-red-600">حساسة</span>
                                @endif
                            </span>
                            <span class="mt-1 block text-xs leading-5 text-gray-500">{{ $permission['description_ar'] }}</span>
                            <span class="mt-1 block text-[11px] font-mono text-gray-400" dir="ltr">{{ $permission['key'] }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
</details>

@once
    @push('scripts')
        <script>
            document.addEventListener('click', function (event) {
                if (event.target.matches('[data-select-group], [data-clear-group]')) {
                    var group = event.target.closest('[data-permission-group]');
                    var checked = event.target.matches('[data-select-group]');

                    group.querySelectorAll('[data-permission-checkbox]:not(:disabled)').forEach(function (checkbox) {
                        checkbox.checked = checked;
                    });
                }
            });
        </script>
    @endpush
@endonce
