@props([
    'roleOptions',
    'selected' => null,
])

@php
    $selected = old('admin_role', $selected);
@endphp

<div class="rounded-2xl border border-indigo-100 bg-indigo-50/60 p-5">
    <div class="mb-4">
        <h3 class="text-base font-black text-gray-900">الدور الوظيفي</h3>
        <p class="mt-1 text-sm text-gray-600">اختر دورًا واحدًا ليحصل الموظف تلقائيًا على كل الصلاحيات اللازمة لعمله.</p>
    </div>

    <select name="admin_role" class="block w-full rounded-xl border-gray-200 bg-white text-sm font-bold text-gray-800 focus:border-indigo-500 focus:ring-indigo-500" data-admin-role-select>
        <option value="">بدون دور جاهز — صلاحيات مخصصة فقط</option>
        @foreach($roleOptions as $role)
            <option value="{{ $role['key'] }}" @selected($selected === $role['key'])>
                {{ $role['name_ar'] }} — {{ count($role['permission_keys']) }} صلاحية
            </option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('admin_role')" class="mt-2" />

    <div class="mt-4 grid grid-cols-1 gap-2 md:grid-cols-2">
        @foreach($roleOptions as $role)
            <div class="rounded-xl border border-white bg-white/80 p-3" data-admin-role-description="{{ $role['key'] }}" @if($selected !== $role['key']) hidden @endif>
                <p class="text-sm font-black text-indigo-900">{{ $role['name_ar'] }}</p>
                <p class="mt-1 text-xs leading-5 text-gray-600">{{ $role['description_ar'] }}</p>
            </div>
        @endforeach
    </div>
</div>

@once
    @push('scripts')
        <script>
            document.addEventListener('change', function (event) {
                if (! event.target.matches('[data-admin-role-select]')) return;

                var form = event.target.closest('form');
                form.querySelectorAll('[data-admin-role-description]').forEach(function (description) {
                    description.hidden = description.dataset.adminRoleDescription !== event.target.value;
                });
            });
        </script>
    @endpush
@endonce
