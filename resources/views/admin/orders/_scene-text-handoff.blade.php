@php
    $sourceClasses = [
        'production_scene' => 'bg-violet-100 text-violet-700',
        'order_snapshot' => 'bg-emerald-100 text-emerald-700',
        'story_template_fallback' => 'bg-amber-100 text-amber-700',
        'missing' => 'bg-red-50 text-red-600',
    ];
@endphp

<details class="overflow-hidden rounded-2xl border border-indigo-200 bg-white shadow-sm" data-order-scene-texts>
    <summary class="flex cursor-pointer list-none flex-col gap-3 px-5 py-5 text-right sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h3 class="text-lg font-black text-gray-900">نصوص المشاهد</h3>
            <p class="mt-1 text-xs font-bold text-gray-500">
                المشاهد الجاهزة: {{ $sceneTextHandoff['ready_count'] }}/13
                <span class="mx-1">•</span>
                المصدر: {{ $sceneTextHandoff['source_summary'] }}
            </p>
        </div>
        <span class="w-fit rounded-full px-3 py-1.5 text-xs font-black {{ $sceneTextHandoff['all_ready'] ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
            {{ $sceneTextHandoff['all_ready'] ? 'جاهز للتسليم' : 'يحتاج استكمال' }}
        </span>
    </summary>

    <div class="border-t border-indigo-100 p-4 sm:p-6">
        @if($sceneTextHandoff['has_any'])
            @if($sceneTextHandoff['has_gender_fallback'])
                <div class="mb-5 rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-amber-950" role="alert">
                    <p class="font-black">تنبيه: بعض مشاهد النسخة البديلة غير مكتملة</p>
                    <p class="mt-1 text-sm font-bold leading-6">
                        استُخدم النص الأساسي بدل النص البديل في المشاهد:
                        {{ collect($sceneTextHandoff['gender_fallback_scene_numbers'])->implode('، ') }}.
                        النص قابل للنسخ، لكن راجع صياغة الجنس قبل الإنتاج.
                    </p>
                </div>
            @endif

            <div class="mb-5 flex flex-col gap-3 rounded-xl bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex flex-col gap-2 sm:flex-row">
                    <button type="button" class="w-full rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-50 sm:w-auto" data-scene-text-open-all>
                        فتح الكل
                    </button>
                    <button type="button" class="w-full rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-bold text-gray-700 hover:bg-gray-50 sm:w-auto" data-scene-text-close-all>
                        إغلاق الكل
                    </button>
                    <button
                        type="button"
                        class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-gray-300 sm:w-auto"
                        data-scene-text-copy-all
                        @disabled(! $sceneTextHandoff['all_ready'])
                    >
                        نسخ كل النصوص
                    </button>
                </div>

                @if($order->productionProject)
                    @can('production_studio.view')
                        <a href="{{ route('admin.production-studio.show', $order->productionProject) }}" class="text-sm font-black text-indigo-600 underline underline-offset-4">
                            فتح Production Studio
                        </a>
                    @endcan
                @elseif($sceneTextHandoff['is_legacy_fallback'])
                    <span class="text-xs font-bold text-amber-700">طلب قديم: النص معروض من قالب القصة الحالي.</span>
                @endif
            </div>

            <p class="mb-4 hidden rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-bold text-emerald-700" role="status" aria-live="polite" data-scene-text-global-status></p>

            <div class="space-y-3">
                @foreach($sceneTextHandoff['scenes'] as $scene)
                    <details class="overflow-hidden rounded-xl border {{ $scene['complete'] ? 'border-gray-200' : 'border-red-100' }} bg-white" data-scene-text-item>
                        <summary class="flex cursor-pointer list-none flex-col gap-2 px-4 py-3 text-right sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <span class="font-black text-gray-900">المشهد {{ $scene['scene_number'] }}</span>
                                @if($scene['title'])
                                    <span class="text-sm text-gray-500">— {{ $scene['title'] }}</span>
                                @endif
                            </div>
                            <span class="flex flex-wrap gap-2">
                                <span class="w-fit rounded-full px-2.5 py-1 text-xs font-black {{ $sourceClasses[$scene['source']] ?? $sourceClasses['missing'] }}">
                                    {{ $scene['source_label'] }}
                                </span>
                                @if($scene['variant_label'] && $scene['source'] !== 'production_scene')
                                    <span class="w-fit rounded-full px-2.5 py-1 text-xs font-black {{ $scene['uses_gender_fallback'] ? 'bg-amber-200 text-amber-900' : 'bg-sky-100 text-sky-700' }}">
                                        {{ $scene['variant_label'] }}
                                    </span>
                                @endif
                            </span>
                        </summary>

                        <div class="border-t border-gray-100 p-4">
                            @if($scene['complete'])
                                <textarea
                                    rows="7"
                                    readonly
                                    dir="rtl"
                                    class="block w-full resize-y rounded-xl border-gray-200 bg-slate-50 text-right text-sm leading-7 text-gray-900 shadow-inner"
                                    data-scene-text-value
                                    data-scene-number="{{ $scene['scene_number'] }}"
                                    data-scene-title="{{ $scene['title'] }}"
                                >{{ $scene['text'] }}</textarea>
                                <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                                    <button type="button" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-bold text-white hover:bg-indigo-700 sm:w-auto" data-scene-text-copy>
                                        نسخ النص
                                    </button>
                                    <span class="hidden text-sm font-bold text-emerald-700" role="status" aria-live="polite" data-scene-text-copy-status></span>
                                </div>
                            @else
                                <p class="rounded-lg bg-red-50 px-4 py-3 text-sm font-bold text-red-600">
                                    لا يوجد نص مكتمل لهذا المشهد في المصدر الحالي.
                                </p>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        @else
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-center">
                <h4 class="font-black text-amber-900">لا توجد نصوص مشاهد لهذه القصة بعد</h4>
                <p class="mt-2 text-sm leading-6 text-amber-700">أضف نصوص المشاهد في القصة، ثم ستظهر هنا تلقائيًا للطلبات القديمة.</p>
                @can('stories.update')
                    <a href="{{ route('admin.stories.edit', $order->story) }}" class="mt-4 inline-flex w-full items-center justify-center rounded-xl bg-amber-600 px-5 py-3 text-sm font-black text-white sm:w-auto">
                        فتح محرر القصة
                    </a>
                @endcan
            </div>
        @endif
    </div>
</details>
