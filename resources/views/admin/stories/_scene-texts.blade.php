@php
    $storyGender = old('gender', isset($story) ? ($story->gender ?? 'both') : 'both');
    $originalCompletedCount = collect(range(1, 13))->filter(function ($sceneNumber) use ($sceneTemplates) {
        $template = $sceneTemplates->get($sceneNumber);

        return filled(old("scenes.$sceneNumber.text_template", $template?->text_template));
    })->count();
    $alternateCompletedCount = collect(range(1, 13))->filter(function ($sceneNumber) use ($sceneTemplates) {
        $template = $sceneTemplates->get($sceneNumber);

        return filled(old("scenes.$sceneNumber.alternate_text_template", $template?->alternate_text_template));
    })->count();
@endphp

<details class="mt-6 overflow-hidden rounded-2xl border border-indigo-200 bg-indigo-50/40" data-story-scenes-editor data-story-gender="{{ $storyGender }}">
    <summary class="flex cursor-pointer list-none flex-col gap-3 px-5 py-4 font-bold text-gray-900 sm:flex-row sm:items-center sm:justify-between">
        <span>نصوص المشاهد</span>
        <span class="flex flex-wrap gap-2">
            <span class="rounded-full bg-white px-3 py-1 text-xs text-indigo-700 shadow-sm" data-scene-readiness-original>
                الأساسي: {{ $originalCompletedCount }} من 13
            </span>
            <span class="rounded-full bg-white px-3 py-1 text-xs text-fuchsia-700 shadow-sm {{ $storyGender === 'both' ? 'hidden' : '' }}" data-scene-readiness-alternate>
                البديل: {{ $alternateCompletedCount }} من 13
            </span>
        </span>
    </summary>

    <div class="border-t border-indigo-100 p-4 sm:p-5">
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-xl border border-indigo-100 bg-white p-4">
                <p class="text-sm font-bold text-gray-900">استيراد النص الأساسي من القصة الكاملة</p>
                <p class="mt-1 text-xs leading-6 text-gray-500">
                    يقرأ 13 قسمًا بصيغة «مشهد 1» أو «Scene 1»، ويملأ العناوين والنص الأساسي فقط دون حفظ.
                </p>
                <button
                    type="button"
                    class="mt-3 w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-indigo-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                    data-scene-import
                    data-import-url="{{ route('admin.stories.scene-import-preview') }}"
                >
                    استيراد النص الأساسي
                </button>
                <p class="mt-3 hidden text-sm font-semibold" role="status" aria-live="polite" data-scene-import-status></p>
            </div>

            <div class="rounded-xl border border-fuchsia-100 bg-white p-4 {{ $storyGender === 'both' ? 'hidden' : '' }}" data-scene-alternate-import-panel>
                <p class="text-sm font-bold text-gray-900">استيراد النسخة البديلة</p>
                <p class="mt-1 text-xs leading-6 text-gray-500">
                    الصق القصة البديلة كاملة. سيُملأ النص البديل فقط، ولن تتغير العناوين أو يُحفظ شيء تلقائيًا.
                </p>
                <textarea
                    rows="5"
                    class="mt-3 block w-full resize-y rounded-lg border-gray-300 text-right text-sm leading-6 shadow-sm focus:border-fuchsia-500 focus:ring-fuchsia-500"
                    dir="rtl"
                    placeholder="مشهد 1: ...&#10;...&#10;مشهد 13: ..."
                    data-scene-alternate-import-source
                ></textarea>
                <button
                    type="button"
                    class="mt-3 w-full rounded-xl bg-fuchsia-600 px-4 py-2.5 text-sm font-bold text-white transition hover:bg-fuchsia-700 disabled:cursor-wait disabled:opacity-60 sm:w-auto"
                    data-scene-import-alternate
                    data-import-url="{{ route('admin.stories.scene-import-preview') }}"
                >
                    استيراد النص البديل
                </button>
                <p class="mt-3 hidden text-sm font-semibold" role="status" aria-live="polite" data-scene-import-alternate-status></p>
            </div>
        </div>

        <div class="my-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs font-bold leading-6 text-amber-800" data-scene-gender-guidance>
            النص الأساسي محايد ويُستخدم لكل الطلبات في القصة المناسبة للجنسين.
        </div>

        <div class="mb-4 flex flex-wrap gap-2 text-xs text-gray-600">
            <span class="font-bold">المتغيرات المدعومة:</span>
            <code class="rounded bg-white px-2 py-1" dir="ltr">@{{child_name}}</code>
            <code class="rounded bg-white px-2 py-1" dir="ltr">@{{child_age}}</code>
            <code class="rounded bg-white px-2 py-1" dir="ltr">@{{story_title}}</code>
        </div>

        <div class="space-y-3">
            @foreach(range(1, 13) as $sceneNumber)
                @php
                    $template = $sceneTemplates->get($sceneNumber);
                    $originalComplete = filled(old("scenes.$sceneNumber.text_template", $template?->text_template));
                    $alternateComplete = filled(old("scenes.$sceneNumber.alternate_text_template", $template?->alternate_text_template));
                @endphp
                <details class="overflow-hidden rounded-xl border border-gray-200 bg-white" data-scene-editor-item>
                    <summary class="flex cursor-pointer list-none flex-col gap-2 px-4 py-3 text-sm font-bold text-gray-800 sm:flex-row sm:items-center sm:justify-between">
                        <span>المشهد {{ $sceneNumber }}</span>
                        <span class="flex flex-wrap gap-2">
                            <span class="rounded-full px-2.5 py-1 text-xs {{ $originalComplete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}" data-scene-original-status>
                                الأساسي: {{ $originalComplete ? 'مكتمل' : 'غير مكتمل' }}
                            </span>
                            <span class="rounded-full px-2.5 py-1 text-xs {{ $alternateComplete ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }} {{ $storyGender === 'both' ? 'hidden' : '' }}" data-scene-alternate-status>
                                البديل: {{ $alternateComplete ? 'مكتمل' : 'غير مكتمل' }}
                            </span>
                        </span>
                    </summary>
                    <div class="space-y-4 border-t border-gray-100 p-4">
                        <input type="hidden" name="scenes[{{ $sceneNumber }}][scene_number]" value="{{ $sceneNumber }}">

                        <div>
                            <x-input-label for="scene-title-{{ $sceneNumber }}" :value="'عنوان المشهد '.$sceneNumber.' (مشترك واختياري)'" />
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

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div>
                                <x-input-label for="scene-text-{{ $sceneNumber }}" value="النص الأساسي" data-scene-original-label />
                                <textarea
                                    id="scene-text-{{ $sceneNumber }}"
                                    name="scenes[{{ $sceneNumber }}][text_template]"
                                    rows="8"
                                    maxlength="10000"
                                    class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-right leading-7 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    dir="rtl"
                                    data-scene-original-template
                                >{{ old("scenes.$sceneNumber.text_template", $template?->text_template) }}</textarea>
                                <x-input-error :messages="$errors->get("scenes.$sceneNumber.text_template")" class="mt-2" />
                            </div>

                            <div class="{{ $storyGender === 'both' ? 'hidden' : '' }}" data-scene-alternate-field>
                                <x-input-label for="scene-alternate-text-{{ $sceneNumber }}" value="النص البديل" data-scene-alternate-label />
                                <textarea
                                    id="scene-alternate-text-{{ $sceneNumber }}"
                                    name="scenes[{{ $sceneNumber }}][alternate_text_template]"
                                    rows="8"
                                    maxlength="10000"
                                    class="mt-1 block w-full resize-y rounded-lg border-gray-300 text-right leading-7 shadow-sm focus:border-fuchsia-500 focus:ring-fuchsia-500"
                                    dir="rtl"
                                    data-scene-alternate-template
                                >{{ old("scenes.$sceneNumber.alternate_text_template", $template?->alternate_text_template) }}</textarea>
                                <x-input-error :messages="$errors->get("scenes.$sceneNumber.alternate_text_template")" class="mt-2" />
                            </div>
                        </div>
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</details>
