@php
    $hasText = filled($scene->story_text);
    $hasVisual = filled($scene->visual_direction);
    $hasPose = filled($scene->child_action_pose);
    $hasSafeArea = filled($scene->text_safe_area_notes);
    $approvedImage = $sceneAssets->where('production_scene_id', $scene->id)->where('status', 'approved')->isNotEmpty();
    $ready = $hasText && $hasVisual && $hasPose && $approvedCharacterSheet && $profileReady;
    $improvementPreview = $sceneImprovementPreviews[$scene->id]['data'] ?? null;
@endphp

<div class="rounded-xl border border-gray-100 bg-white p-4 text-right"
     data-scene-row
     data-filter-missing-visual="{{ $hasVisual ? '0' : '1' }}"
     data-filter-ready="{{ $ready ? '1' : '0' }}"
     data-filter-has-image="{{ $sceneAssets->where('production_scene_id', $scene->id)->isNotEmpty() ? '1' : '0' }}"
     data-filter-needs-review="{{ $sceneAssets->where('production_scene_id', $scene->id)->where('status', 'under_review')->isNotEmpty() ? '1' : '0' }}"
     data-filter-approved="{{ $approvedImage ? '1' : '0' }}">
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
            <p class="font-black text-gray-950">مشهد {{ $scene->scene_number }} - {{ $scene->title ?? 'بدون عنوان' }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $scene->status ?: 'draft' }}</p>
        </div>
        <div class="flex flex-wrap justify-end gap-2">
            @include('admin.production-studio.partials.status-badge', ['label' => $hasText ? 'نص' : 'بدون نص', 'tone' => $hasText ? 'emerald' : 'amber'])
            @include('admin.production-studio.partials.status-badge', ['label' => $hasVisual ? 'توجيه بصري' : 'ينقص توجيه', 'tone' => $hasVisual ? 'emerald' : 'amber'])
            @include('admin.production-studio.partials.status-badge', ['label' => $hasPose ? 'وضع الطفل' : 'ينقص وضع الطفل', 'tone' => $hasPose ? 'emerald' : 'gray'])
            @include('admin.production-studio.partials.status-badge', ['label' => $hasSafeArea ? 'منطقة نص' : 'ينقص منطقة نص', 'tone' => $hasSafeArea ? 'emerald' : 'gray'])
            @include('admin.production-studio.partials.status-badge', ['label' => $approvedImage ? 'صورة معتمدة' : 'لا توجد صورة معتمدة', 'tone' => $approvedImage ? 'emerald' : 'gray'])
        </div>
    </div>

    <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" class="rounded-lg bg-gray-100 px-3 py-1.5 text-xs font-black text-gray-700 hover:bg-gray-200" data-studio-scene-toggle>تعديل سريع</button>
        @can('production_studio.ai_generate')
            <form method="POST" action="{{ route('admin.production-studio.ai.scene', [$project, $scene]) }}" data-studio-ai-form class="flex flex-wrap justify-end gap-2">
                @csrf
                <input type="hidden" name="model_code" value="{{ $defaultModel }}">
                <input type="hidden" name="style_preset" value="premium_storybook">
                @if($approvedCharacterSheet)
                    <input type="hidden" name="character_sheet_id" value="{{ $approvedCharacterSheet->id }}">
                @endif
                <button @disabled(!$aiAvailable || !$ready) class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-black text-white disabled:bg-gray-300">توليد</button>
                <div data-studio-ai-feedback class="hidden rounded-lg border px-3 py-1.5 text-xs font-bold"></div>
            </form>
        @endcan
        @can('production_studio.scene_edit')
            <form method="POST" action="{{ route('admin.production-studio.scenes.improve', [$project, $scene]) }}" class="flex flex-wrap justify-end gap-2">
                @csrf
                <input type="hidden" name="model_code" value="{{ $sceneImproveModel }}">
                <button @disabled(!$openAiAvailable || !$hasText) class="rounded-lg bg-purple-600 px-3 py-1.5 text-xs font-black text-white disabled:bg-gray-300">تحسين التوجيه البصري بالذكاء الاصطناعي</button>
            </form>
        @endcan
    </div>

    @if($improvementPreview)
        <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <p class="font-black text-emerald-800">معاينة تحسين جاهزة للمشهد {{ $scene->scene_number }}</p>
                <form method="POST" action="{{ route('admin.production-studio.scenes.apply-improvement', [$project, $scene]) }}">
                    @csrf
                    <button class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">تطبيق التحسين</button>
                </form>
            </div>
            <pre dir="ltr" class="mt-2 max-h-60 overflow-auto rounded-lg bg-white p-3 text-left text-xs">{{ json_encode($improvementPreview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    <div class="mt-4 hidden" data-studio-scene-editor>
        <form method="POST" action="{{ route('admin.production-studio.scenes.update', [$project, $scene]) }}" class="rounded-xl bg-gray-50 p-4">
            @csrf
            @method('PATCH')
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <label class="block">
                    <span class="text-xs font-black text-gray-500">رقم المشهد</span>
                    <input name="scene_number" value="{{ $scene->scene_number }}" @cannot('production_studio.scene_edit') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                </label>
                <label class="block md:col-span-2">
                    <span class="text-xs font-black text-gray-500">العنوان</span>
                    <input name="title" value="{{ $scene->title }}" @cannot('production_studio.scene_edit') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                </label>
                <label class="block">
                    <span class="text-xs font-black text-gray-500">الحالة</span>
                    <input name="status" value="{{ $scene->status }}" @cannot('production_studio.scene_edit') readonly @endcannot class="mt-1 w-full rounded-xl border-gray-300 text-right">
                </label>
            </div>
            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <textarea name="story_text" rows="4" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="نص المشهد">{{ $scene->story_text }}</textarea>
                <textarea name="visual_direction" rows="4" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="التوجيه البصري">{{ $scene->visual_direction }}</textarea>
                <textarea name="educational_value" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="القيمة التعليمية">{{ $scene->educational_value }}</textarea>
                <textarea name="child_action_pose" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="حركة أو وضع الطفل">{{ $scene->child_action_pose }}</textarea>
                <textarea name="environment" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="البيئة">{{ $scene->environment }}</textarea>
                <textarea name="mood_lighting" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="المزاج والإضاءة">{{ $scene->mood_lighting }}</textarea>
                <textarea name="supporting_characters" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="الشخصيات المساندة">{{ $scene->supporting_characters }}</textarea>
                <textarea name="key_objects" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="العناصر المهمة">{{ $scene->key_objects }}</textarea>
                <textarea name="continuity_notes" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات الاستمرارية">{{ $scene->continuity_notes }}</textarea>
                <textarea name="text_safe_area_notes" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات منطقة النص الآمنة">{{ $scene->text_safe_area_notes }}</textarea>
                <textarea name="review_notes" rows="3" @cannot('production_studio.scene_edit') readonly @endcannot class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات المراجعة">{{ $scene->review_notes }}</textarea>
            </div>
            @can('production_studio.scene_edit')
                <button class="mt-3 rounded-xl bg-gray-900 px-4 py-2 text-sm font-black text-white">حفظ المشهد</button>
            @endcan
        </form>
    </div>
</div>
