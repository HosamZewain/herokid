@php
    $completedSceneCount = collect(range(1, 13))->filter(function ($sceneNumber) use ($sceneTemplates) {
        $template = $sceneTemplates->get($sceneNumber);

        return filled(old("scenes.$sceneNumber.text_template", $template?->text_template));
    })->count();
@endphp

<details class="mt-6 overflow-hidden rounded-2xl border border-indigo-200 bg-indigo-50/40" data-story-scenes-editor>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 font-bold text-gray-900">
        <span>نصوص المشاهد</span>
        <span class="rounded-full bg-white px-3 py-1 text-xs text-indigo-700 shadow-sm" data-scene-readiness>
            {{ $completedSceneCount }} من 13 مشهد مكتمل
        </span>
    </summary>

    <div class="border-t border-indigo-100 p-4 sm:p-5">
        <div class="mb-5 rounded-xl border border-indigo-100 bg-white p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-bold text-gray-900">استيراد من القصة الكاملة</p>
                    <p class="mt-1 text-xs leading-6 text-gray-500">
                        يقبل 13 قسمًا مرقّمًا بصيغة «مشهد 1» أو «Scene 1». الاستيراد يملأ الحقول فقط ولن يحفظ القصة.
                    </p>
                </div>
                <button
                    type="button"
                    class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                    data-scene-import
                    data-import-url="{{ route('admin.stories.scene-import-preview') }}"
                >
                    استيراد من القصة الكاملة
                </button>
            </div>
            <p class="mt-3 hidden text-sm font-semibold" role="status" aria-live="polite" data-scene-import-status></p>
        </div>

        <div class="mb-4 flex flex-wrap gap-2 text-xs text-gray-600">
            <span class="font-bold">المتغيرات المدعومة:</span>
            <code class="rounded bg-white px-2 py-1" dir="ltr">@{{child_name}}</code>
            <code class="rounded bg-white px-2 py-1" dir="ltr">@{{child_age}}</code>
            <code class="rounded bg-white px-2 py-1" dir="ltr">@{{story_title}}</code>
        </div>

        <div class="space-y-3">
            @foreach(range(1, 13) as $sceneNumber)
                @php $template = $sceneTemplates->get($sceneNumber); @endphp
                <details class="overflow-hidden rounded-xl border border-gray-200 bg-white" data-scene-editor-item>
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-bold text-gray-800">
                        <span>المشهد {{ $sceneNumber }}</span>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs {{ filled(old("scenes.$sceneNumber.text_template", $template?->text_template)) ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}"
                            data-scene-item-status
                        >
                            {{ filled(old("scenes.$sceneNumber.text_template", $template?->text_template)) ? 'مكتمل' : 'غير مكتمل' }}
                        </span>
                    </summary>
                    <div class="space-y-4 border-t border-gray-100 p-4">
                        <input type="hidden" name="scenes[{{ $sceneNumber }}][scene_number]" value="{{ $sceneNumber }}">

                        <div>
                            <x-input-label for="scene-title-{{ $sceneNumber }}" :value="'عنوان المشهد '.$sceneNumber.' (اختياري)'" />
                            <input
                                id="scene-title-{{ $sceneNumber }}"
                                type="text"
                                name="scenes[{{ $sceneNumber }}][title]"
                                value="{{ old("scenes.$sceneNumber.title", $template?->title) }}"
                                maxlength="255"
                                class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                data-scene-title
                            >
                            <x-input-error :messages="$errors->get("scenes.$sceneNumber.title")" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="scene-text-{{ $sceneNumber }}" :value="'نص المشهد '.$sceneNumber" />
                            <textarea
                                id="scene-text-{{ $sceneNumber }}"
                                name="scenes[{{ $sceneNumber }}][text_template]"
                                rows="7"
                                maxlength="10000"
                                class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-right leading-7 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                dir="rtl"
                                data-scene-template
                            >{{ old("scenes.$sceneNumber.text_template", $template?->text_template) }}</textarea>
                            <x-input-error :messages="$errors->get("scenes.$sceneNumber.text_template")" class="mt-2" />
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</details>
