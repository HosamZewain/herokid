@php
    $passed = $checks->whereIn('result', ['pass', 'not_applicable'])->count();
    $failed = $checks->where('result', 'fail')->count();
    $pending = $checks->count() - $passed - $failed;
@endphp

<details class="rounded-xl border border-gray-100 bg-white p-4 text-right" data-studio-qa-group>
    <summary class="cursor-pointer list-none">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <p class="font-black text-gray-950">{{ $category }}</p>
            <div class="flex flex-wrap justify-end gap-2">
                @include('admin.production-studio.partials.status-badge', ['label' => "ناجح {$passed}", 'tone' => 'emerald'])
                @include('admin.production-studio.partials.status-badge', ['label' => "فشل {$failed}", 'tone' => $failed ? 'red' : 'gray'])
                @include('admin.production-studio.partials.status-badge', ['label' => "معلق {$pending}", 'tone' => $pending ? 'amber' : 'gray'])
            </div>
        </div>
    </summary>
    <div class="mt-4 space-y-3">
        @foreach($checks as $check)
            <form method="POST" action="{{ route('admin.production-studio.qa.update', [$project, $check]) }}" class="rounded-lg bg-gray-50 p-3">
                @csrf
                @method('PATCH')
                <p class="font-bold text-gray-900">{{ $check->label }}</p>
                <div class="mt-2 grid grid-cols-1 gap-2 md:grid-cols-2">
                    <select name="result" @cannot('production_studio.qa_review') disabled @endcannot class="rounded-xl border-gray-300 text-sm">
                        <option value="not_reviewed" @selected($check->result === 'not_reviewed')>لم يراجع</option>
                        <option value="pass" @selected($check->result === 'pass')>ناجح</option>
                        <option value="fail" @selected($check->result === 'fail')>فشل</option>
                        <option value="not_applicable" @selected($check->result === 'not_applicable')>لا ينطبق</option>
                    </select>
                    <input name="note" value="{{ $check->note }}" @cannot('production_studio.qa_review') readonly @endcannot class="rounded-xl border-gray-300 text-sm" placeholder="ملاحظة">
                    <label class="flex items-center gap-2 text-xs font-bold text-gray-600">
                        <input type="checkbox" name="override_allowed" value="1" @checked($check->override_allowed) @cannot('production_studio.qa_review') disabled @endcannot>
                        تجاوز بصلاحية
                    </label>
                    <input name="override_reason" value="{{ $check->override_reason }}" @cannot('production_studio.qa_review') readonly @endcannot class="rounded-xl border-gray-300 text-sm" placeholder="سبب التجاوز">
                </div>
                @can('production_studio.qa_review')
                    <button class="mt-2 rounded-lg bg-white px-3 py-1.5 text-xs font-black text-indigo-700 ring-1 ring-indigo-200">حفظ البند</button>
                @endcan
            </form>
        @endforeach
    </div>
</details>
