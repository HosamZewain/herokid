<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">استوديو الإنتاج #{{ $project->id }}</h2>
    </x-slot>

    @php
        $statusTone = match ($project->status) {
            'completed', 'ready_for_print', 'approved' => 'emerald',
            'cancelled' => 'red',
            'archived' => 'gray',
            default => 'indigo',
        };
        $photos = $order->uploaded_photos ?? [];
        $profile = $project->characterProfile;
        $snapshot = $project->source_snapshot_json ?? [];
        $characterSheets = $project->assets->where('asset_type', 'character_sheet');
        $sceneAssets = $project->assets->where('asset_type', 'scene_image');
        $coverAssets = $project->assets->where('asset_type', 'cover_image');
        $approvedCharacterSheet = $characterSheets->firstWhere('is_primary', true);
        $primaryFaceIndex = $profile?->primaryFaceReferenceIndex();
        $defaultModel = $defaultModelsByCapability['scene_generation'] ?? null;
        $characterSheetModel = $defaultModelsByCapability['character_sheet'] ?? $defaultModel;
        $premiumModel = $defaultModelsByCapability['cover_generation'] ?? ($defaultModelsByCapability['premium_retry'] ?? null);
        $visionModel = $defaultTextModelsByCapability['vision_to_text'] ?? null;
        $sceneExtractionModel = $defaultTextModelsByCapability['scene_extraction'] ?? null;
        $sceneImproveModel = $defaultTextModelsByCapability['prompt_enhancement'] ?? null;
        $visionModelReady = filled($visionModel);
        $sceneExtractionModelReady = filled($sceneExtractionModel);
        $sceneImproveModelReady = filled($sceneImproveModel);
        $profileReady = (bool) $profile?->isReadyForAiGeneration();
        $missingProfileFields = $profile?->missingAiGenerationFields() ?? ['character_profile' => 'ملف الشخصية'];
        $hasStoryDraft = $project->storyVersions->isNotEmpty();
        $qaProgress = $project->qaProgress();
        $qaFailed = $project->qaChecks->where('result', 'fail')->count();
        $qaPending = $project->qaChecks->where('result', 'not_reviewed')->count();
        $totalScenes = $project->scenes->count();
        $missingVisualScenes = $project->scenes->filter(fn ($scene) => blank($scene->visual_direction))->count();
        $readyScenes = $project->scenes->filter(fn ($scene) => filled($scene->visual_direction))->count();
        $approvedSceneImages = $sceneAssets->where('status', 'approved')->count();
        $jobCompleted = $project->generationJobs->where('status', 'completed')->count();
        $jobFailed = $project->generationJobs->where('status', 'failed')->count();
        $jobProcessing = $project->generationJobs->whereIn('status', ['queued', 'processing'])->count();
        $latestActivity = $project->activityLogs->sortByDesc('created_at')->first();
        $referencePhotoSummary = $primaryFaceIndex !== null ? 'صورة الوجه الأساسية #'.($primaryFaceIndex + 1) : 'لا توجد صورة وجه أساسية';
        $stageDefaultMap = [
            'intake' => 'order-child-data',
            'story_review' => 'story-workspace',
            'character_profile' => 'character-profile',
            'scene_preparation' => 'scenes',
            'image_generation' => 'ai-production',
            'image_review' => 'ai-production',
            'layout' => 'layout-print',
            'quality_check' => 'qa-checklist',
            'print_ready' => 'layout-print',
        ];
        $defaultOpenSection = $stageDefaultMap[$project->current_stage] ?? 'overview';
        $nextAction = match (true) {
            ! $profileReady => ['label' => 'أكمل ملف الشخصية', 'section' => 'character-profile'],
            ! $approvedCharacterSheet => ['label' => 'اعتمد الصورة المرجعية للطفل', 'section' => 'child-reference'],
            $missingVisualScenes > 0 => ['label' => 'أضف التوجيه البصري للمشاهد', 'section' => 'scenes'],
            $project->generationJobs->isEmpty() => ['label' => 'ولّد مشهدًا أو غلافًا واحدًا', 'section' => 'ai-production'],
            $project->assets->where('status', 'under_review')->isNotEmpty() => ['label' => 'راجع المخرجات المنتظرة', 'section' => 'ai-production'],
            $qaProgress < 100 => ['label' => 'أكمل مراجعة الجودة', 'section' => 'qa-checklist'],
            default => ['label' => 'راجع الإخراج والطباعة', 'section' => 'layout-print'],
        };
        $sectionNav = [
            'overview' => 'نظرة عامة',
            'order-child-data' => 'بيانات الطلب والطفل',
            'story-workspace' => 'مساحة القصة',
            'character-profile' => 'ملف الشخصية',
            'child-reference' => 'الصورة المرجعية',
            'scenes' => 'المشاهد',
            'ai-production' => 'إنتاج الصور',
            'layout-print' => 'الإخراج والطباعة',
            'qa-checklist' => 'مراجعة الجودة',
            'activity-log' => 'سجل النشاط',
        ];
        $layoutPrintItems = ['Reader Order PDF', 'Print-Ready Booklet PDF', 'Print Manifest', 'Proof Print Checklist'];
        $activityFilters = ['all' => 'All', 'project' => 'project', 'story' => 'story', 'character' => 'character', 'ai' => 'AI', 'asset' => 'asset', 'qa' => 'QA', 'status' => 'status'];
        $activityLogs = $project->activityLogs->sortByDesc('created_at')->values()->map(function ($log, $index) {
            $action = $log->action;
            $type = 'project';

            if (str_starts_with($action, 'ai_')) {
                $type = 'ai';
            } elseif (str_contains($action, 'qa')) {
                $type = 'qa';
            } elseif (str_contains($action, 'story')) {
                $type = 'story';
            } elseif (str_contains($action, 'character')) {
                $type = 'character';
            } elseif (str_contains($action, 'asset')) {
                $type = 'asset';
            } elseif (str_contains($action, 'status')) {
                $type = 'status';
            }

            return [
                'log' => $log,
                'type' => $type,
                'is_extra' => $index >= 10,
            ];
        });
    @endphp

    <div class="space-y-6" dir="rtl" data-studio-project="{{ $project->id }}" data-default-section="{{ $defaultOpenSection }}">
        <div class="rounded-2xl border border-indigo-100 bg-indigo-50 p-5 text-right">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p class="text-sm font-black text-indigo-700">Production Studio</p>
                    <h1 class="mt-1 text-2xl font-black text-gray-950">مشروع إنتاج معزول للطلب {{ $order->order_number }}</h1>
                    <p class="mt-2 text-sm leading-7 text-indigo-900">مساحة داخلية اختيارية. لا تغيّر حالة الطلب الأصلي أو برومبت الإنتاج الحالي.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.orders.show', $order) }}" class="rounded-xl border border-indigo-200 bg-white px-4 py-2 text-sm font-black text-indigo-700 hover:bg-indigo-50">فتح الطلب الأصلي</a>
                    <a href="{{ route('admin.production-studio.index') }}" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">كل مشاريع الاستوديو</a>
                </div>
            </div>
        </div>

        <nav class="flex flex-wrap gap-2 rounded-2xl border border-gray-100 bg-white p-3 text-sm font-black shadow-sm" aria-label="Production Studio workflow">
            @foreach($sectionNav as $sectionId => $label)
                <a href="#{{ $sectionId }}" data-studio-nav="{{ $sectionId }}" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">{{ $label }}</a>
            @endforeach
        </nav>

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'overview',
            'title' => 'نظرة عامة',
            'description' => 'ملخص سريع لحالة مشروع الاستوديو وما يجب عمله بعد ذلك.',
            'status' => $project->statusLabel(),
            'statusTone' => $statusTone,
            'summary' => 'التالي: '.$nextAction['label'],
        ])
            <div class="grid grid-cols-1 gap-4 text-right md:grid-cols-3 xl:grid-cols-6">
                <div class="rounded-xl bg-gray-50 p-4 md:col-span-2">
                    <p class="text-xs font-bold text-gray-400">رقم الطلب</p>
                    <a href="{{ route('admin.orders.show', $order) }}" class="mt-1 block font-black text-indigo-700 hover:underline">{{ $order->order_number }}</a>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">الطفل</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->child_name ?? 'Not available' }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 md:col-span-2">
                    <p class="text-xs font-bold text-gray-400">القصة</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->story?->title ?? 'Not available' }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">المرحلة</p>
                    <p class="mt-1 font-black text-gray-900">{{ $project->stageLabel() }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">المسؤول</p>
                    <p class="mt-1 font-black text-gray-900">{{ $project->assignedTo?->name ?? 'غير معين' }}</p>
                </div>
                <div class="rounded-xl bg-emerald-50 p-4">
                    <p class="text-xs font-bold text-emerald-600">تقدم QA</p>
                    <p class="mt-1 font-black text-gray-900">{{ $qaProgress }}%</p>
                </div>
                @can('production_studio.ai_view_costs')
                    <div class="rounded-xl bg-indigo-50 p-4">
                        <p class="text-xs font-bold text-indigo-500">محاولات AI</p>
                        <p class="mt-1 font-black text-gray-900">{{ $aiCostSummary['attempts'] }}</p>
                    </div>
                    <div class="rounded-xl bg-indigo-50 p-4">
                        <p class="text-xs font-bold text-indigo-500">تقديري</p>
                        <p class="mt-1 font-black text-gray-900">${{ $aiCostSummary['estimated'] }}</p>
                    </div>
                    <div class="rounded-xl bg-indigo-50 p-4">
                        <p class="text-xs font-bold text-indigo-500">فعلي</p>
                        <p class="mt-1 font-black text-gray-900">${{ $aiCostSummary['actual'] }}</p>
                    </div>
                @endcan
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-right">
                    <p class="font-black text-indigo-900">الإجراء المقترح التالي</p>
                    <button type="button" data-studio-open-section="{{ $nextAction['section'] }}" class="mt-3 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">{{ $nextAction['label'] }}</button>
                </div>
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-right lg:col-span-2">
                    <p class="font-black text-gray-900">آخر نشاط</p>
                    <p class="mt-2 text-sm text-gray-600">{{ $latestActivity?->description ?? 'لا يوجد نشاط مسجل بعد.' }}</p>
                    @if($latestActivity)
                        <p class="mt-1 text-xs text-gray-400">{{ $latestActivity->actor?->name ?? 'System' }} · {{ $latestActivity->created_at?->format('Y-m-d H:i') }}</p>
                    @endif
                </div>
            </div>

            @can('production_studio.manage')
                <details class="mt-5 rounded-xl border border-gray-100 bg-gray-50 p-4 text-right">
                    <summary class="cursor-pointer font-black text-gray-900">إدارة حالة المشروع والملاحظات</summary>
                    <form method="POST" action="{{ route('admin.production-studio.update', $project) }}" class="mt-4 space-y-4">
                        @csrf
                        @method('PATCH')
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                            <label class="block">
                                <span class="text-sm font-black text-gray-700">حالة الاستوديو</span>
                                <select name="status" class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                    @foreach($statuses as $value => $label)
                                        <option value="{{ $value }}" @selected($project->status === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm font-black text-gray-700">المرحلة</span>
                                <select name="current_stage" class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                    <option value="">بدون مرحلة</option>
                                    @foreach($stages as $value => $label)
                                        <option value="{{ $value }}" @selected($project->current_stage === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="block">
                                <span class="text-sm font-black text-gray-700">المسؤول</span>
                                <select name="assigned_to_user_id" class="mt-2 w-full rounded-xl border-gray-300 text-right">
                                    <option value="">غير معين</option>
                                    @foreach($assignees as $assignee)
                                        <option value="{{ $assignee->id }}" @selected($project->assigned_to_user_id === $assignee->id)>{{ $assignee->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                        <textarea name="production_notes" rows="3" class="w-full rounded-xl border-gray-300 text-right" placeholder="ملاحظات الإنتاج">{{ old('production_notes', $project->production_notes) }}</textarea>
                        <input name="qa_override_reason" value="{{ old('qa_override_reason') }}" class="w-full rounded-xl border-gray-300 text-right" placeholder="سبب تجاوز QA عند النقل إلى جاهز للطباعة">
                        <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ بيانات المشروع</button>
                    </form>
                </details>
            @endcan

            <details class="mt-4 rounded-xl border border-gray-100 bg-gray-50 p-4 text-right">
                <summary class="cursor-pointer font-black text-gray-900">إجراءات الأرشفة والإلغاء</summary>
                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                    @can('production_studio.archive')
                        @if($project->status === 'archived')
                            <form method="POST" action="{{ route('admin.production-studio.reopen', $project) }}">
                                @csrf
                                <button class="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2.5 text-sm font-black text-indigo-700 hover:bg-indigo-100">إعادة فتح المشروع</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.production-studio.archive', $project) }}">
                                @csrf
                                <button class="w-full rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-black text-gray-700 hover:bg-gray-100">أرشفة المشروع</button>
                            </form>
                        @endif
                    @endcan
                    @can('production_studio.delete_or_cancel')
                        <form method="POST" action="{{ route('admin.production-studio.cancel', $project) }}" class="flex gap-2">
                            @csrf
                            <input name="cancel_reason" class="min-w-0 flex-1 rounded-xl border-gray-300 text-right text-sm" placeholder="سبب الإلغاء">
                            <button class="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-black text-red-700 hover:bg-red-100">إلغاء</button>
                        </form>
                    @endcan
                </div>
            </details>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'order-child-data',
            'title' => 'بيانات الطلب والطفل',
            'description' => 'مرجع قراءة فقط من الطلب الأصلي والصور المرفقة.',
            'status' => count($photos) ? 'صور مرفقة' : 'لا توجد صور',
            'statusTone' => count($photos) ? 'emerald' : 'amber',
            'summary' => ($order->parent_name ?? $order->user?->name ?? 'Not available').' · '.($order->child_name ?? 'Not available'),
        ])
            <div class="grid grid-cols-1 gap-4 text-right lg:grid-cols-3">
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">العميل</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->parent_name ?? $order->user?->name ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ data_get($order->delivery_details, 'phone', data_get($order->delivery_details, 'mobile', '')) }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">الطفل</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->child_name ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">{{ $order->child_age ?? 'Not available' }} سنة - {{ $order->child_gender ?? 'Not available' }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4">
                    <p class="text-xs font-bold text-gray-400">القصة المختارة</p>
                    <p class="mt-1 font-black text-gray-900">{{ $order->story?->title ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">الفئة العمرية: {{ $order->story?->age_range ?? 'Not available' }}</p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-gray-100 p-4 text-right">
                    <p class="font-black text-gray-900">الاهتمامات وملاحظات الوالد</p>
                    <p class="mt-2 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $order->interests ?: 'Not available' }}</p>
                    <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $order->parent_notes ?: 'Not available' }}</p>
                </div>
                <div class="rounded-xl border border-gray-100 p-4 text-right">
                    <p class="font-black text-gray-900">الإضافات المرتبطة</p>
                    @php($addOns = $order->items->where('item_type', 'product_add_on'))
                    @forelse($addOns as $addOn)
                        <div class="mt-2 rounded-lg bg-gray-50 px-3 py-2 text-sm">
                            <span class="font-black text-gray-900">{{ $addOn->title }}</span>
                            <span class="text-gray-500"> - {{ $addOn->quantity }} × {{ number_format($addOn->unit_price_cents / 100, 0) }} ج.م</span>
                        </div>
                    @empty
                        <p class="mt-2 text-sm text-gray-500">لا توجد إضافات مرتبطة.</p>
                    @endforelse
                </div>
            </div>

            <div class="mt-5">
                <p class="mb-3 text-right font-black text-gray-900">صور الطفل الأصلية</p>
                @if(count($photos))
                    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                        @foreach($photos as $photo)
                            <a href="{{ route('admin.production-studio.photo', [$project, $loop->index]) }}" target="_blank" class="block rounded-xl border border-gray-100 bg-gray-50 p-2 hover:border-indigo-200">
                                <div class="aspect-square overflow-hidden rounded-lg bg-gray-100">
                                    <img src="{{ route('admin.production-studio.photo', [$project, $loop->index]) }}" alt="صورة الطفل {{ $loop->iteration }}" class="h-full w-full object-cover" loading="lazy">
                                </div>
                                <p class="mt-1 text-center text-xs font-bold text-gray-500">صورة {{ $loop->iteration }}</p>
                            </a>
                        @endforeach
                    </div>
                @else
                    <p class="text-right text-sm text-gray-500">لا توجد صور مرفقة بهذا الطلب.</p>
                @endif
            </div>

            @if($existingProductionPrompt)
                <details class="mt-5 rounded-xl border border-gray-100 bg-slate-50 p-4">
                    <summary class="cursor-pointer text-right font-black text-gray-900">عرض برومبت الطلب الأصلي</summary>
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <button type="button" data-copy-target="existing-production-prompt" class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">نسخ برومبت الطلب الحالي</button>
                    </div>
                    <textarea id="existing-production-prompt" rows="10" dir="ltr" readonly class="mt-3 w-full rounded-xl border-gray-300 bg-white font-mono text-sm text-left">{{ $existingProductionPrompt }}</textarea>
                </details>
            @endif

            <details class="mt-5 rounded-xl border border-gray-100 bg-gray-50 p-4 text-right">
                <summary class="cursor-pointer font-black text-gray-800">عرض لقطة بيانات المشروع عند الإنشاء</summary>
                <pre dir="ltr" class="mt-3 max-h-72 overflow-auto rounded-lg bg-white p-3 text-left text-xs text-gray-700">{{ json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </details>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'story-workspace',
            'title' => 'مساحة القصة',
            'description' => 'نسخ العمل الخاصة بهذا الطلب دون تعديل القصة الأصلية.',
            'status' => $hasStoryDraft ? 'توجد مسودة' : 'لا توجد مسودة',
            'statusTone' => $hasStoryDraft ? 'emerald' : 'amber',
            'summary' => $project->storyVersions->count().' نسخة · '.$totalScenes.' مشهد',
        ])
            <div class="flex flex-col gap-3 border-b border-gray-100 pb-4 md:flex-row md:items-center md:justify-between">
                <div class="text-right">
                    <p class="font-black text-gray-900">{{ $order->story?->title ?? 'Not available' }}</p>
                    <p class="mt-1 text-sm text-gray-500">القصة الأصلية مرجع فقط.</p>
                </div>
                @can('production_studio.story_edit')
                    <form method="POST" action="{{ route('admin.production-studio.story-versions.from-story', $project) }}">
                        @csrf
                        <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">إنشاء مسودة من القصة الأصلية</button>
                    </form>
                @endcan
            </div>

            <details class="mt-4 rounded-xl bg-gray-50 p-4 text-right">
                <summary class="cursor-pointer font-black text-gray-900">عرض النص الأصلي الكامل</summary>
                <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $order->story?->full_desc ?? $order->story?->short_desc ?? 'Not available' }}</p>
            </details>

            @can('production_studio.story_edit')
                <div class="mt-5 rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-right">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <h3 class="font-black text-gray-950">بناء المشاهد من مسودة القصة</h3>
                            <p class="mt-1 text-sm leading-7 text-indigo-900">يتم استخدام parser محلي أولًا عند وجود عناوين مشاهد واضحة. إذا لم يكفِ، يتم استخدام OpenAI لإرجاع JSON منظم.</p>
                        </div>
                        @unless($openAiAvailable && $sceneExtractionModelReady)
                            @include('admin.production-studio.partials.status-badge', ['label' => 'OpenAI أو نموذج استخراج المشاهد غير مهيأ', 'tone' => 'amber'])
                        @endunless
                    </div>
                    <form method="POST" action="{{ route('admin.production-studio.story-versions.extract-scenes', $project) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                        @csrf
                        <select name="source_version_id" class="rounded-xl border-gray-300 text-right">
                            <option value="">القصة الأصلية أو آخر مسودة</option>
                            @foreach($project->storyVersions as $version)
                                <option value="{{ $version->id }}">مسودة {{ $version->version_number }} - {{ $version->title ?? 'بدون عنوان' }}</option>
                            @endforeach
                        </select>
                        <select name="model_code" @disabled(!$sceneExtractionModelReady) class="rounded-xl border-gray-300 text-right">
                            @foreach($textModelsByCapability['scene_extraction'] ?? collect() as $model)
                                <option value="{{ $model->code }}" @selected($model->code === $sceneExtractionModel)>{{ $model->provider->public_name }} — {{ $model->display_name }}</option>
                            @endforeach
                        </select>
                        <button @disabled(!$sceneExtractionModelReady) class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white disabled:bg-gray-300">بناء المشاهد من مسودة القصة</button>
                    </form>
                    @unless($sceneExtractionModelReady)
                        <p class="mt-2 text-sm font-bold text-amber-700">فعّل نموذج OpenAI افتراضي بقدرة scene_extraction قبل استخدام استخراج المشاهد.</p>
                    @endunless

                    @if($sceneExtractionPreview)
                        <div class="mt-4 rounded-xl border border-emerald-200 bg-white p-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p class="font-black text-emerald-800">معاينة جاهزة للحفظ: {{ count(data_get($sceneExtractionPreview, 'data.scenes', [])) }} مشهد</p>
                                    <p class="text-xs text-gray-500">المصدر: {{ data_get($sceneExtractionPreview, 'source') }}</p>
                                </div>
                                <form method="POST" action="{{ route('admin.production-studio.story-versions.apply-scenes', $project) }}">
                                    @csrf
                                    <button class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">تأكيد واستبدال المشاهد الحالية</button>
                                </form>
                            </div>
                            <details class="mt-3">
                                <summary class="cursor-pointer text-sm font-black text-indigo-700">عرض JSON المشاهد</summary>
                                <pre dir="ltr" class="mt-2 max-h-72 overflow-auto rounded-lg bg-gray-50 p-3 text-left text-xs">{{ json_encode(data_get($sceneExtractionPreview, 'data'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </details>
                        </div>
                    @endif
                </div>
            @endcan

            <div class="mt-5 space-y-3">
                @forelse($project->storyVersions as $version)
                    <div class="rounded-xl border border-gray-100 p-4 text-right">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="font-black text-gray-950">نسخة {{ $version->version_number }} - {{ $version->title ?? 'بدون عنوان' }}</p>
                                <p class="text-sm text-gray-500">الحالة: {{ $version->status }} - العمر المستهدف: {{ $version->target_age_group ?? 'Not available' }}</p>
                            </div>
                            @can('production_studio.story_edit')
                                <form method="POST" action="{{ route('admin.production-studio.story-versions.review', [$project, $version]) }}" class="flex flex-col gap-2 md:flex-row">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" class="rounded-xl border-gray-300 text-sm">
                                        <option value="under_review" @selected($version->status === 'under_review')>تحت المراجعة</option>
                                        <option value="approved" @selected($version->status === 'approved')>معتمد</option>
                                        <option value="rejected" @selected($version->status === 'rejected')>مرفوض</option>
                                    </select>
                                    <input name="review_notes" value="{{ $version->review_notes }}" class="rounded-xl border-gray-300 text-sm" placeholder="ملاحظات المراجعة">
                                    <button class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-black text-white">حفظ</button>
                                </form>
                            @endcan
                        </div>
                        <details class="mt-3">
                            <summary class="cursor-pointer text-sm font-black text-indigo-700">عرض النص الكامل</summary>
                            <p class="mt-3 whitespace-pre-line text-sm leading-7 text-gray-600">{{ $version->full_story_content ?: 'لا يوجد محتوى محفوظ.' }}</p>
                        </details>
                    </div>
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-right text-sm text-gray-500">لم يتم إنشاء مسودة داخل الاستوديو بعد.</p>
                @endforelse
            </div>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'character-profile',
            'title' => 'ملف الشخصية',
            'description' => 'تحضير بيانات الهوية والصور المرجعية قبل أي توليد.',
            'status' => $profileReady ? 'مكتمل' : 'ناقص بيانات',
            'statusTone' => $profileReady ? 'emerald' : 'amber',
            'warning' => $profileReady ? null : 'ينقص: '.count($missingProfileFields),
            'summary' => $referencePhotoSummary,
        ])
            <form method="POST" action="{{ route('admin.production-studio.character-profile.update', $project) }}" class="space-y-5">
                @csrf
                @method('PATCH')
                <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-right">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p class="font-black text-indigo-900">تعبئة مبدئية يدوية</p>
                            <p class="mt-1 text-sm leading-7 text-indigo-800">لا يستخدم AI خارجي. يملأ نصًا مبدئيًا يمكن تعديله قبل الحفظ.</p>
                        </div>
                        <button type="button" data-fill-identity-summary class="rounded-xl bg-white px-4 py-2 text-sm font-black text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-100">تعبئة مبدئية يدوية</button>
                    </div>
                </div>

                @can('production_studio.character_profile_edit')
                    <div class="rounded-xl border border-purple-100 bg-purple-50 p-4 text-right">
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="font-black text-purple-950">تحليل صور الطفل بالذكاء الاصطناعي</p>
                                <p class="mt-1 text-sm leading-7 text-purple-900">يستخدم OpenAI لتحليل الصور المختارة وإرجاع حقول منظمة قابلة للمراجعة قبل الحفظ.</p>
                            </div>
                            @include('admin.production-studio.partials.status-badge', ['label' => ($openAiAvailable && $visionModelReady) ? 'OpenAI جاهز' : 'OpenAI أو نموذج تحليل الصور غير مهيأ', 'tone' => ($openAiAvailable && $visionModelReady) ? 'emerald' : 'amber'])
                        </div>
                        <form method="POST" action="{{ route('admin.production-studio.character-profile.analyze', $project) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                            @csrf
                            <select name="model_code" @disabled(!$visionModelReady) class="rounded-xl border-gray-300 text-right">
                                @foreach($textModelsByCapability['vision_to_text'] ?? collect() as $model)
                                    <option value="{{ $model->code }}" @selected($model->code === $visionModel)>{{ $model->provider->public_name }} — {{ $model->display_name }}</option>
                                @endforeach
                            </select>
                            <div class="flex flex-wrap justify-end gap-2 md:col-span-2">
                                @foreach($profile?->approved_reference_photos ?? [] as $photoIndex)
                                    <label class="rounded-xl bg-white px-3 py-2 text-sm font-bold text-gray-700 ring-1 ring-purple-100">
                                        <input type="checkbox" name="reference_photo_indices[]" value="{{ $photoIndex }}" @checked($profile?->primaryFaceReferenceIndex() === (int) $photoIndex)>
                                        صورة {{ ((int) $photoIndex) + 1 }}
                                    </label>
                                @endforeach
                            </div>
                            <button @disabled(!$visionModelReady || count($profile?->approved_reference_photos ?? []) === 0) class="rounded-xl bg-purple-600 px-4 py-2.5 text-sm font-black text-white disabled:bg-gray-300">تحليل صور الطفل بالذكاء الاصطناعي</button>
                        </form>
                        @unless($visionModelReady)
                            <p class="mt-2 text-sm font-bold text-amber-700">فعّل نموذج OpenAI افتراضي بقدرة vision_to_text قبل تحليل صور الطفل.</p>
                        @endunless

                        @if($characterAnalysisPreview)
                            <div class="mt-4 rounded-xl border border-emerald-200 bg-white p-4">
                                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <p class="font-black text-emerald-800">معاينة تحليل جاهزة. راجعها قبل التطبيق.</p>
                                    <form method="POST" action="{{ route('admin.production-studio.character-profile.apply-analysis', $project) }}">
                                        @csrf
                                        <button class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white">تطبيق التحليل على ملف الشخصية</button>
                                    </form>
                                </div>
                                <pre dir="ltr" class="mt-3 max-h-72 overflow-auto rounded-lg bg-gray-50 p-3 text-left text-xs">{{ json_encode(data_get($characterAnalysisPreview, 'data'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        @endif
                    </div>
                @endcan

                @unless($profileReady)
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-right text-sm font-bold text-amber-800">
                        أكمل ملف الشخصية واختر صور مرجعية واضحة قبل التوليد.
                        <span class="mt-1 block">الحقول الناقصة: {{ implode('، ', $missingProfileFields) }}</span>
                    </div>
                @endunless

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <fieldset class="rounded-xl border border-gray-100 p-4">
                        <legend class="px-2 text-sm font-black text-gray-900">Identity Summary</legend>
                        @foreach(['appearance_summary' => 'ملخص المظهر', 'eye_color_traits' => 'العين والملامح الظاهرة / eyes_and_visible_traits', 'skin_tone' => 'لون البشرة', 'typical_expression' => 'التعبير المعتاد / usual_expression'] as $field => $label)
                            <label class="mt-3 block text-right">
                                <span class="text-sm font-black text-gray-700">{{ $label }}</span>
                                <textarea name="{{ $field }}" rows="3" @cannot('production_studio.character_profile_edit') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">{{ old($field, $profile?->{$field}) }}</textarea>
                            </label>
                        @endforeach
                    </fieldset>

                    <fieldset class="rounded-xl border border-gray-100 p-4">
                        <legend class="px-2 text-sm font-black text-gray-900">Hair & Body</legend>
                        @foreach(['hair_details' => 'تفاصيل الشعر', 'face_shape_notes' => 'ملاحظات شكل الوجه', 'body_proportion_notes' => 'ملاحظات نسب الجسم', 'wardrobe_direction' => 'اتجاه الملابس', 'approved_visual_style' => 'الأسلوب البصري المعتمد'] as $field => $label)
                            <label class="mt-3 block text-right">
                                <span class="text-sm font-black text-gray-700">{{ $label }}</span>
                                <textarea name="{{ $field }}" rows="3" @cannot('production_studio.character_profile_edit') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">{{ old($field, $profile?->{$field}) }}</textarea>
                            </label>
                        @endforeach
                    </fieldset>

                    <fieldset class="rounded-xl border border-gray-100 p-4">
                        <legend class="px-2 text-sm font-black text-gray-900">Identity Rules</legend>
                        @foreach(['identity_rules' => 'قواعد الحفاظ على الهوية', 'negative_instructions' => 'تعليمات سلبية', 'confidence_notes' => 'ملاحظات الثقة', 'reference_photo_recommendations' => 'توصيات الصور المرجعية', 'analysis_warnings' => 'تحذيرات التحليل', 'reviewer_notes' => 'ملاحظات المراجع'] as $field => $label)
                            <label class="mt-3 block text-right">
                                <span class="text-sm font-black text-gray-700">{{ $label }}</span>
                                <textarea name="{{ $field }}" rows="3" @cannot('production_studio.character_profile_edit') readonly @endcannot class="mt-2 w-full rounded-xl border-gray-300 text-right">{{ old($field, $profile?->{$field}) }}</textarea>
                            </label>
                        @endforeach
                    </fieldset>
                </div>

                <div class="rounded-xl border border-gray-100 p-4 text-right">
                    <p class="font-black text-gray-900">References</p>
                    <div class="mt-2 rounded-xl bg-gray-50 p-3 text-xs leading-6 text-gray-600">
                        استخدم صورة وجه واضحة كمرجع الهوية الأساسي. استخدم صورة جسم كاملة فقط للنسب. تجنب الصور البعيدة أو الضبابية أو الملابس المتضاربة.
                    </div>
                    @if(count($photos))
                        <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-5">
                            @foreach($photos as $photo)
                                <label class="rounded-xl border border-gray-100 bg-gray-50 p-2">
                                    <img src="{{ route('admin.production-studio.photo', [$project, $loop->index]) }}" alt="مرجع {{ $loop->iteration }}" class="aspect-square w-full rounded-lg object-cover">
                                    <span class="mt-2 flex items-center justify-center gap-2 text-sm font-bold text-gray-700">
                                        <input type="checkbox" name="approved_reference_photos[]" value="{{ $loop->index }}" @checked(in_array($loop->index, $profile?->approved_reference_photos ?? [], true)) @cannot('production_studio.character_profile_edit') disabled @endcannot>
                                        صورة {{ $loop->iteration }}
                                    </span>
                                    <div class="mt-2 space-y-1 text-xs font-bold text-gray-600">
                                        <label class="flex items-center justify-center gap-1">
                                            <input type="radio" name="primary_face_reference_index" value="{{ $loop->index }}" @checked($profile?->primaryFaceReferenceIndex() === $loop->index) @cannot('production_studio.character_profile_edit') disabled @endcannot>
                                            وجه أساسي
                                        </label>
                                        <label class="flex items-center justify-center gap-1">
                                            <input type="radio" name="body_reference_index" value="{{ $loop->index }}" @checked($profile?->bodyReferenceIndex() === $loop->index) @cannot('production_studio.character_profile_edit') disabled @endcannot>
                                            جسم اختياري
                                        </label>
                                        <label class="flex items-center justify-center gap-1">
                                            <input type="radio" name="style_reference_index" value="{{ $loop->index }}" @checked($profile?->styleReferenceIndex() === $loop->index) @cannot('production_studio.character_profile_edit') disabled @endcannot>
                                            ستايل اختياري
                                        </label>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p class="mt-2 text-sm text-gray-500">لا توجد صور لاختيارها.</p>
                    @endif
                </div>

                @can('production_studio.character_profile_edit')
                    <button class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700">حفظ ملف الشخصية</button>
                @endcan
            </form>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'child-reference',
            'title' => 'الصورة المرجعية للطفل',
            'description' => 'توليد واعتماد الرسم المرجعي النظيف المستخدم لاحقًا للغلاف والمشاهد.',
            'status' => $approvedCharacterSheet ? 'معتمدة' : 'لا توجد صورة معتمدة',
            'statusTone' => $approvedCharacterSheet ? 'emerald' : 'amber',
            'summary' => $characterSheets->count().' نسخة مرجعية',
        ])
            @unless($aiAvailable)
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-right text-sm font-black text-amber-800">
                    AI generation is not configured yet.
                    @can('settings.ai_providers.view')
                        <a href="{{ route('admin.settings.ai-providers.index') }}" class="underline">إعداد المزودين</a>
                    @endcan
                </div>
            @endunless

            @can('production_studio.ai_generate')
                <form method="POST" action="{{ route('admin.production-studio.ai.character-sheet', $project) }}" data-studio-ai-form class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-4">
                    @csrf
                    <select name="model_code" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                        @foreach($aiModelsByCapability['character_sheet'] ?? collect() as $model)
                            <option value="{{ $model->code }}" @selected($model->code === $characterSheetModel)>{{ $model->provider->public_name }} — {{ $model->display_name }}</option>
                        @endforeach
                    </select>
                    <select name="style_preset" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                        @foreach($stylePresets as $key => $label)
                            <option value="{{ $key }}">{{ $key }}</option>
                        @endforeach
                    </select>
                    <input name="prompt_notes" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right lg:col-span-2" placeholder="ملاحظات اختيارية للتوليد">
                    <div class="grid grid-cols-2 gap-2 lg:col-span-4 md:grid-cols-5">
                        @foreach($profile?->approved_reference_photos ?? [] as $photoIndex)
                            <label class="rounded-xl bg-gray-50 p-2 text-center text-sm font-bold text-gray-700">
                                <input type="checkbox" name="reference_photo_indices[]" value="{{ $photoIndex }}" checked @disabled(!$aiAvailable)>
                                صورة {{ ((int) $photoIndex) + 1 }}
                            </label>
                        @endforeach
                    </div>
                    @unless($profileReady)
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-800 lg:col-span-4">
                            أكمل ملف الشخصية واختر صور مرجعية واضحة قبل التوليد. ناقص: {{ implode('، ', $missingProfileFields) }}
                        </div>
                    @endunless
                    <details class="rounded-xl bg-gray-50 p-3 text-xs leading-6 text-gray-600 lg:col-span-4">
                        <summary class="cursor-pointer font-black text-gray-900">معاينة أساس البرومبت</summary>
                        <p class="mt-2">Identity fidelity is the highest priority. Preserve exact face shape, eye spacing, nose, smile, cheeks, jawline, hairline, hairstyle, skin tone, apparent age, and natural proportions. Output one child only with no text, labels, logos, fake writing, profile-card layout, school badges, or poster design.</p>
                    </details>
                    <button @disabled(!$aiAvailable || !$profileReady) class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white disabled:cursor-not-allowed disabled:bg-gray-300">توليد الصورة المرجعية للطفل</button>
                    <div data-studio-ai-feedback class="hidden rounded-xl border p-3 text-sm font-bold lg:col-span-4"></div>
                </form>
            @endcan

            <div class="mt-5 grid grid-cols-1 gap-3 md:grid-cols-3">
                @forelse($characterSheets as $asset)
                    @include('admin.production-studio.partials.asset-card', ['asset' => $asset, 'project' => $project])
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-sm text-gray-500">لم يتم توليد الصورة المرجعية للطفل بعد.</p>
                @endforelse
            </div>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'scenes',
            'title' => 'المشاهد',
            'description' => 'قائمة compact للمشاهد مع مؤشرات الجاهزية وتعديل مشهد واحد في كل مرة.',
            'status' => $totalScenes.' مشهد',
            'statusTone' => $missingVisualScenes ? 'amber' : 'emerald',
            'warning' => $missingVisualScenes ? $missingVisualScenes.' ناقصة توجيه بصري' : null,
            'summary' => $readyScenes.' جاهزة للتوليد · '.$approvedSceneImages.' صور معتمدة',
        ])
            <div class="grid grid-cols-1 gap-3 text-right md:grid-cols-4">
                <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-400">الإجمالي</p><p class="font-black">{{ $totalScenes }}</p></div>
                <div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs text-emerald-600">جاهزة للتوليد</p><p class="font-black">{{ $readyScenes }}</p></div>
                <div class="rounded-xl bg-amber-50 p-4"><p class="text-xs text-amber-600">ناقصة توجيه</p><p class="font-black">{{ $missingVisualScenes }}</p></div>
                <div class="rounded-xl bg-indigo-50 p-4"><p class="text-xs text-indigo-600">صور معتمدة</p><p class="font-black">{{ $approvedSceneImages }}</p></div>
            </div>
            <div class="mt-4 flex flex-wrap justify-end gap-2 text-xs font-black" data-scene-filters>
                <button type="button" data-scene-filter="all" class="rounded-xl bg-indigo-600 px-3 py-2 text-white">All</button>
                <button type="button" data-scene-filter="missing-visual" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700">Missing visual direction</button>
                <button type="button" data-scene-filter="ready" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700">Ready for generation</button>
                <button type="button" data-scene-filter="has-image" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700">Has generated image</button>
                <button type="button" data-scene-filter="needs-review" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700">Needs review</button>
                <button type="button" data-scene-filter="approved" class="rounded-xl bg-gray-100 px-3 py-2 text-gray-700">Approved</button>
            </div>
            <div class="mt-5 space-y-3">
                @forelse($project->scenes as $scene)
                    @include('admin.production-studio.partials.scene-row', [
                        'scene' => $scene,
                        'project' => $project,
                        'sceneAssets' => $sceneAssets,
                        'approvedCharacterSheet' => $approvedCharacterSheet,
                        'profileReady' => $profileReady,
                        'aiAvailable' => $aiAvailable,
                        'openAiAvailable' => $openAiAvailable,
                        'defaultModel' => $defaultModel,
                        'sceneImproveModelReady' => $sceneImproveModelReady,
                        'sceneImproveModel' => $sceneImproveModel,
                        'sceneImprovementPreviews' => $sceneImprovementPreviews,
                    ])
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-right text-sm text-gray-500">لا توجد مشاهد بعد. أنشئ مسودة من القصة الأصلية أو أضف مشهدًا يدويًا.</p>
                @endforelse
            </div>
            @can('production_studio.scene_edit')
                <details class="mt-5 rounded-xl border border-dashed border-indigo-200 bg-indigo-50 p-4 text-right">
                    <summary class="cursor-pointer font-black text-indigo-900">إضافة مشهد يدوي</summary>
                    <form method="POST" action="{{ route('admin.production-studio.scenes.store', $project) }}" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
                        @csrf
                        <input name="scene_number" class="rounded-xl border-gray-300 text-right" placeholder="رقم المشهد" required>
                        <input name="title" class="rounded-xl border-gray-300 text-right" placeholder="عنوان المشهد">
                        <input name="status" value="draft" class="rounded-xl border-gray-300 text-right" placeholder="الحالة">
                        <textarea name="story_text" rows="3" class="rounded-xl border-gray-300 text-right md:col-span-3" placeholder="نص المشهد"></textarea>
                        <button class="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-black text-white hover:bg-indigo-700">إضافة</button>
                    </form>
                </details>
            @endcan
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'ai-production',
            'title' => 'إنتاج الصور بالذكاء الاصطناعي',
            'description' => 'توليد الغلاف أو مشهد واحد ومراجعة سجل المهام دون تكرار كل المشاهد.',
            'status' => $jobCompleted.' مكتملة / '.$jobFailed.' فاشلة / '.$jobProcessing.' قيد التنفيذ',
            'statusTone' => $jobFailed ? 'amber' : 'indigo',
            'summary' => 'لا يوجد توليد جماعي في هذه المرحلة',
        ])
            <div class="grid grid-cols-1 gap-3 text-right md:grid-cols-4">
                @include('admin.production-studio.partials.status-badge', ['label' => $aiAvailable ? 'المزود جاهز' : 'AI غير مهيأ', 'tone' => $aiAvailable ? 'emerald' : 'amber'])
                @include('admin.production-studio.partials.status-badge', ['label' => $profileReady ? 'ملف الشخصية مكتمل' : 'ملف الشخصية ناقص', 'tone' => $profileReady ? 'emerald' : 'amber'])
                @include('admin.production-studio.partials.status-badge', ['label' => $approvedCharacterSheet ? 'مرجع الطفل معتمد' : 'لا يوجد مرجع معتمد', 'tone' => $approvedCharacterSheet ? 'emerald' : 'amber'])
                @include('admin.production-studio.partials.status-badge', ['label' => 'Queue عبر cron/worker', 'tone' => 'gray'])
            </div>

            @unless($aiAvailable)
                <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-right text-sm font-black text-amber-800">
                    AI generation is not configured yet.
                    @can('settings.ai_providers.view')
                        <a href="{{ route('admin.settings.ai-providers.index') }}" class="underline">إعداد مزودي الذكاء الاصطناعي</a>
                    @endcan
                </div>
            @endunless

            <div class="mt-5 grid grid-cols-1 gap-4 xl:grid-cols-2">
                <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-right">
                    <h3 class="font-black text-gray-950">توليد غلاف</h3>
                    <p class="mt-2 text-xs leading-6 text-indigo-900">
                        @if($approvedCharacterSheet)
                            image_url: {{ $approvedCharacterSheet->label }}
                        @elseif($primaryFaceIndex !== null)
                            يفضل اعتماد صورة مرجعية للطفل قبل توليد الغلاف. يمكن استخدام صورة الوجه مؤقتًا بالتأكيد الصريح.
                        @else
                            لا توجد صورة وجه أساسية. أكمل ملف الشخصية أولًا.
                        @endif
                    </p>
                    @can('production_studio.ai_generate')
                        <form method="POST" action="{{ route('admin.production-studio.ai.cover', $project) }}" data-studio-ai-form class="mt-3 grid grid-cols-1 gap-3">
                            @csrf
                            <select name="model_code" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                                @foreach($aiModelsByCapability['cover_generation'] ?? collect() as $model)
                                    <option value="{{ $model->code }}" @selected($model->code === $premiumModel)>{{ $model->provider->public_name }} — {{ $model->display_name }}</option>
                                @endforeach
                            </select>
                            <select name="style_preset" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                                @foreach($stylePresets as $key => $label)
                                    <option value="{{ $key }}">{{ $key }}</option>
                                @endforeach
                            </select>
                            <input name="prompt_notes" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات الغلاف">
                            @if($approvedCharacterSheet)
                                <input type="hidden" name="character_sheet_id" value="{{ $approvedCharacterSheet->id }}">
                            @elseif($primaryFaceIndex !== null)
                                <label class="flex items-center justify-end gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-black text-amber-800">
                                    <span>استخدم صورة الوجه الأساسية مؤقتًا بدون صورة مرجعية معتمدة.</span>
                                    <input type="checkbox" name="confirm_primary_face_cover_fallback" value="1" required class="rounded border-amber-300">
                                </label>
                            @endif
                            <button @disabled(!$aiAvailable || !$profileReady || ($primaryFaceIndex === null && !$approvedCharacterSheet)) class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white disabled:bg-gray-300">توليد Artwork الغلاف</button>
                            <div data-studio-ai-feedback class="hidden rounded-xl border p-3 text-sm font-bold"></div>
                        </form>
                    @endcan
                </div>

                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-right">
                    <h3 class="font-black text-gray-950">توليد مشهد محدد</h3>
                    @php($firstScene = $project->scenes->first())
                    @can('production_studio.ai_generate')
                        <form method="POST" action="{{ $firstScene ? route('admin.production-studio.ai.scene', [$project, $firstScene]) : '#' }}" data-studio-ai-form data-scene-select-form class="mt-3 grid grid-cols-1 gap-3">
                            @csrf
                            <select data-scene-action-select class="rounded-xl border-gray-300 text-right" @disabled($project->scenes->isEmpty())>
                                @forelse($project->scenes as $scene)
                                    <option value="{{ $scene->id }}" data-action="{{ route('admin.production-studio.ai.scene', [$project, $scene]) }}" data-ready="{{ $scene->hasImagePromptContext() ? '1' : '0' }}">مشهد {{ $scene->scene_number }} - {{ $scene->title ?? 'بدون عنوان' }}</option>
                                @empty
                                    <option>لا توجد مشاهد</option>
                                @endforelse
                            </select>
                            <select name="model_code" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                                @foreach($aiModelsByCapability['scene_generation'] ?? collect() as $model)
                                    <option value="{{ $model->code }}" @selected($model->code === $defaultModel)>{{ $model->provider->public_name }} — {{ $model->display_name }}</option>
                                @endforeach
                            </select>
                            <select name="style_preset" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                                @foreach($stylePresets as $key => $label)
                                    <option value="{{ $key }}">{{ $key }}</option>
                                @endforeach
                            </select>
                            @if($approvedCharacterSheet)
                                <input type="hidden" name="character_sheet_id" value="{{ $approvedCharacterSheet->id }}">
                            @endif
                            <input name="prompt_notes" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات اختيارية">
                            <p class="text-xs font-bold text-gray-500" data-scene-readiness-note>اختر مشهدًا جاهزًا يحتوي على توجيه بصري.</p>
                            <button @disabled(!$aiAvailable || !$profileReady || !$approvedCharacterSheet || !$firstScene) class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white disabled:bg-gray-300">توليد المشهد المحدد</button>
                            <div data-studio-ai-feedback class="hidden rounded-xl border p-3 text-sm font-bold"></div>
                        </form>
                    @endcan
                </div>
            </div>

            <div class="mt-6 grid grid-cols-1 gap-3 md:grid-cols-3">
                @foreach($coverAssets as $asset)
                    @include('admin.production-studio.partials.asset-card', ['asset' => $asset, 'project' => $project])
                @endforeach
                @foreach($sceneAssets as $asset)
                    @include('admin.production-studio.partials.asset-card', ['asset' => $asset, 'project' => $project])
                @endforeach
            </div>

            <div class="mt-6 rounded-xl border border-gray-100 bg-gray-50 p-4 text-right">
                <h3 class="font-black text-gray-950">سجل مهام التوليد</h3>
                <div class="mt-3 space-y-2" data-studio-job-list>
                    @forelse($project->generationJobs->sortByDesc('created_at') as $job)
                        @include('admin.production-studio.partials.generation-job-row', ['job' => $job])
                    @empty
                        <p class="text-sm text-gray-500" data-studio-empty-jobs>لا توجد مهام توليد بعد.</p>
                    @endforelse
                </div>
            </div>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'layout-print',
            'title' => 'الإخراج والطباعة',
            'description' => 'مكان compact لمخرجات Reader PDF وPrint PDF والمانيفست.',
            'status' => 'قادم لاحقًا',
            'statusTone' => 'gray',
            'summary' => 'لا يتم توليد PDF تلقائيًا في هذه المرحلة',
        ])
            <div class="grid grid-cols-1 gap-4 text-right md:grid-cols-4">
                @foreach($layoutPrintItems as $assetLabel)
                    <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 p-4">
                        <p class="font-black text-gray-800">{{ $assetLabel }}</p>
                        <p class="mt-2 text-sm text-gray-500">مكان مخصص للمرحلة القادمة.</p>
                    </div>
                @endforeach
            </div>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'qa-checklist',
            'title' => 'مراجعة الجودة',
            'description' => 'بنود QA مجمعة حتى لا تظهر كل التفاصيل مرة واحدة.',
            'status' => $qaProgress.'%',
            'statusTone' => $qaProgress >= 100 ? 'emerald' : 'amber',
            'warning' => $qaFailed ? $qaFailed.' فاشلة' : ($qaPending ? $qaPending.' معلقة' : null),
            'summary' => 'لا يمكن النقل إلى جاهز للطباعة مع بنود إلزامية فاشلة بدون سبب تجاوز',
        ])
            <div class="grid grid-cols-1 gap-3 text-right md:grid-cols-3">
                <div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs text-emerald-600">التقدم</p><p class="font-black">{{ $qaProgress }}%</p></div>
                <div class="rounded-xl bg-red-50 p-4"><p class="text-xs text-red-600">فاشلة</p><p class="font-black">{{ $qaFailed }}</p></div>
                <div class="rounded-xl bg-amber-50 p-4"><p class="text-xs text-amber-600">معلقة</p><p class="font-black">{{ $qaPending }}</p></div>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-3 lg:grid-cols-2">
                @foreach($project->qaChecks->groupBy('category') as $category => $checks)
                    @include('admin.production-studio.partials.qa-subgroup', ['project' => $project, 'category' => $category, 'checks' => $checks])
                @endforeach
            </div>
        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'activity-log',
            'title' => 'سجل النشاط',
            'description' => 'آخر الأحداث فقط افتراضيًا مع إمكانية عرض المزيد.',
            'status' => $project->activityLogs->count().' حدث',
            'statusTone' => 'gray',
            'summary' => $latestActivity?->description ?? 'لا يوجد نشاط بعد',
        ])
            <div class="mb-4 flex flex-wrap justify-end gap-2 text-xs font-black" data-activity-filters>
                @foreach($activityFilters as $filter => $label)
                    <button type="button" data-activity-filter="{{ $filter }}" class="rounded-xl {{ $loop->first ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' }} px-3 py-2">{{ $label }}</button>
                @endforeach
            </div>
            <div class="space-y-3" data-activity-list>
                @forelse($activityLogs as $activity)
                    <div class="rounded-xl bg-gray-50 p-4 text-right {{ $activity['is_extra'] ? 'hidden' : '' }}" data-activity-item data-activity-type="{{ $activity['type'] }}" data-activity-extra="{{ $activity['is_extra'] ? '1' : '0' }}">
                        <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <p class="font-black text-gray-900">{{ $activity['log']->description }}</p>
                            <p class="text-xs text-gray-500">{{ $activity['log']->created_at?->format('Y-m-d H:i') }}</p>
                        </div>
                        <p class="mt-1 text-sm text-gray-500">{{ $activity['log']->actor?->name ?? 'System' }} - {{ $activity['log']->action }}</p>
                    </div>
                @empty
                    <p class="rounded-xl bg-gray-50 p-4 text-right text-sm text-gray-500">لا يوجد نشاط مسجل بعد.</p>
                @endforelse
            </div>
            @if($project->activityLogs->count() > 10)
                <button type="button" data-activity-show-more class="mt-4 rounded-xl bg-gray-100 px-4 py-2 text-sm font-black text-gray-700 hover:bg-gray-200">Show more</button>
            @endif
        @include('admin.production-studio.partials.workflow-card-close')
    </div>

    <script>
        function studioFeedback(element, type, message) {
            if (!element) return;

            element.classList.remove('hidden', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-800', 'border-red-200', 'bg-red-50', 'text-red-800', 'border-indigo-200', 'bg-indigo-50', 'text-indigo-800');

            const classes = {
                success: ['border-emerald-200', 'bg-emerald-50', 'text-emerald-800'],
                error: ['border-red-200', 'bg-red-50', 'text-red-800'],
                info: ['border-indigo-200', 'bg-indigo-50', 'text-indigo-800'],
            }[type] || ['border-indigo-200', 'bg-indigo-50', 'text-indigo-800'];

            element.classList.add(...classes);
            element.textContent = message;
        }

        function studioJobLabel(job) {
            const cost = job.actual_cost || job.estimated_cost || '0.0000';
            return `#${job.id} - ${job.job_type} - ${job.status} - $${cost}`;
        }

        function upsertStudioJob(job, statusUrl) {
            const list = document.querySelector('[data-studio-job-list]');
            if (!list || !job) return;

            document.querySelector('[data-studio-empty-jobs]')?.remove();

            let row = list.querySelector(`[data-studio-job-row="${job.id}"]`);
            if (!row) {
                row = document.createElement('div');
                row.className = 'rounded-xl bg-white p-3 text-sm ring-1 ring-gray-100';
                row.dataset.studioJobRow = job.id;
                row.dataset.statusUrl = statusUrl || '';
                row.innerHTML = `
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                        <p class="font-black text-gray-900"><span data-studio-job-label></span></p>
                        <p class="text-gray-500" data-studio-job-updated></p>
                    </div>
                    <p data-studio-job-error class="mt-2 text-xs font-bold text-red-600"></p>
                `;
                list.prepend(row);
            }

            row.querySelector('[data-studio-job-label]').textContent = studioJobLabel(job);
            row.querySelector('[data-studio-job-updated]').textContent = job.updated_at ? `آخر تحديث: ${job.updated_at}` : '';
            row.querySelector('[data-studio-job-error]').textContent = job.error_message || '';

            if (statusUrl) {
                row.dataset.statusUrl = statusUrl;
            }
        }

        async function pollStudioJob(statusUrl, feedback) {
            if (!statusUrl) return;

            for (let attempt = 0; attempt < 18; attempt += 1) {
                await new Promise(resolve => setTimeout(resolve, attempt === 0 ? 1200 : 5000));

                const response = await fetch(statusUrl, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    studioFeedback(feedback, 'error', 'تعذر قراءة حالة مهمة التوليد.');
                    return;
                }

                const payload = await response.json();
                const job = payload.job;
                upsertStudioJob(job, statusUrl);

                if (job.status === 'completed') {
                    studioFeedback(feedback, 'success', 'اكتملت المهمة. يمكنك مراجعة المخرج في سجل التوليد أو تحديث الصفحة لعرض الصورة الجديدة.');
                    return;
                }

                if (job.status === 'failed') {
                    studioFeedback(feedback, 'error', job.error_message || 'فشلت مهمة التوليد.');
                    return;
                }

                studioFeedback(feedback, 'info', `حالة المهمة #${job.id}: ${job.status}. إذا بقيت Queued تأكد من تشغيل Queue Worker على السيرفر.`);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const root = document.querySelector('[data-studio-project]');
            if (!root) return;

            const storageKey = `production-studio:${root.dataset.studioProject}:open-section`;
            const sections = Array.from(document.querySelectorAll('[data-studio-section]'));

            function sectionExists(id) {
                return sections.some(section => section.dataset.studioSection === id);
            }

            function setOpenSection(id, scroll = false) {
                if (!sectionExists(id)) id = 'overview';

                sections.forEach(section => {
                    const isOpen = section.dataset.studioSection === id;
                    const panel = section.querySelector('[data-studio-section-panel]');
                    const button = section.querySelector('[data-studio-section-toggle]');
                    const icon = section.querySelector('[data-studio-section-icon]');

                    panel?.classList.toggle('hidden', !isOpen);
                    button?.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    if (icon) icon.textContent = isOpen ? '−' : '+';
                });

                document.querySelectorAll('[data-studio-nav]').forEach(link => {
                    const isActive = link.dataset.studioNav === id;
                    link.classList.toggle('bg-indigo-600', isActive);
                    link.classList.toggle('text-white', isActive);
                    link.classList.toggle('bg-gray-100', !isActive);
                    link.classList.toggle('text-gray-700', !isActive);
                });

                localStorage.setItem(storageKey, id);

                if (scroll) {
                    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }

            const hashSection = window.location.hash ? window.location.hash.replace('#', '') : null;
            const savedSection = localStorage.getItem(storageKey);
            const initialSection = hashSection && sectionExists(hashSection)
                ? hashSection
                : (savedSection && sectionExists(savedSection) ? savedSection : root.dataset.defaultSection || 'overview');

            setOpenSection(initialSection, Boolean(hashSection));

            document.addEventListener('click', function (event) {
                const toggle = event.target.closest('[data-studio-section-toggle]');
                const opener = event.target.closest('[data-studio-open-section]');
                const nav = event.target.closest('[data-studio-nav]');
                const target = toggle?.dataset.studioSectionToggle || opener?.dataset.studioOpenSection || nav?.dataset.studioNav;

                if (target) {
                    event.preventDefault();
                    history.replaceState(null, '', `#${target}`);
                    setOpenSection(target, true);
                }
            });
        });

        document.addEventListener('toggle', function (event) {
            const sceneEditor = event.target.closest('[data-studio-scene-editor]');
            if (sceneEditor && event.target.open) {
                document.querySelectorAll('[data-studio-scene-editor]').forEach(details => {
                    if (details !== event.target) details.removeAttribute('open');
                });
            }
            const qaGroup = event.target.closest('[data-studio-qa-group]');
            if (qaGroup && event.target.open) {
                document.querySelectorAll('[data-studio-qa-group]').forEach(details => {
                    if (details !== event.target) details.removeAttribute('open');
                });
            }
        }, true);

        document.addEventListener('click', async function (event) {
            const fillButton = event.target.closest('[data-fill-identity-summary]');
            if (fillButton) {
                const defaults = {
                    appearance_summary: 'فتاة مصرية عمرها حوالي 7 سنوات، وجه طفولي طبيعي، ابتسامة هادئة، ملامح ناعمة، مظهر طبيعي مناسب لعمرها.',
                    hair_details: 'شعر بني داكن طويل وكثيف، مموج/كيرلي، فرق واضح في المنتصف أو قريب من المنتصف حسب الصورة المرجعية.',
                    skin_tone: 'بشرة فاتحة إلى قمحية فاتحة بدرجة طبيعية.',
                    eye_color_traits: 'عينان بنيتان، حواجب داكنة، ابتسامة هادئة، ملامح وجه طفولية.',
                    typical_expression: 'ابتسامة طبيعية وهادئة، تعبير ودود وواثق.',
                    identity_rules: 'يجب الحفاظ على نفس شكل الوجه، العينين، الأنف، الابتسامة، خط الشعر، ملمس الشعر، لون البشرة، العمر الظاهري، والنسب الجسدية. لا تجعل الطفلة أكبر سنًا أو أكثر تجميلًا أو كشخصية مختلفة.',
                    negative_instructions: 'لا تغير ملامح الوجه. لا تغير تسريحة الشعر. لا تجعلها تبدو أكبر من عمرها. لا تضف مكياج. لا تجعلها أنمي. لا تضف نصوص أو شعارات أو شارات مدرسة أو كتابة عشوائية. لا تنسخ أي شخصية محمية.',
                };

                Object.entries(defaults).forEach(([name, value]) => {
                    const field = document.querySelector(`[name="${name}"]`);
                    if (field && !field.value.trim()) {
                        field.value = value;
                    }
                });

                fillButton.textContent = 'تم ملء الحقول الناقصة';
                setTimeout(() => fillButton.textContent = 'توليد وصف الهوية من الصور', 1800);
                return;
            }

            const sceneToggle = event.target.closest('[data-studio-scene-toggle]');
            if (sceneToggle) {
                const editor = sceneToggle.closest('[data-scene-row]')?.querySelector('[data-studio-scene-editor]');
                if (editor) {
                    document.querySelectorAll('[data-studio-scene-editor]').forEach(other => {
                        if (other !== editor) other.classList.add('hidden');
                    });
                    editor.classList.toggle('hidden');
                }
                return;
            }

            const filterButton = event.target.closest('[data-scene-filter]');
            if (filterButton) {
                const filter = filterButton.dataset.sceneFilter;
                document.querySelectorAll('[data-scene-filters] button').forEach(button => {
                    button.classList.toggle('bg-indigo-600', button === filterButton);
                    button.classList.toggle('text-white', button === filterButton);
                    button.classList.toggle('bg-gray-100', button !== filterButton);
                    button.classList.toggle('text-gray-700', button !== filterButton);
                });
                document.querySelectorAll('[data-scene-row]').forEach(row => {
                    const show = filter === 'all' || row.dataset[`filter${filter.replace(/(^|-)([a-z])/g, (_, __, c) => c.toUpperCase())}`] === '1';
                    row.classList.toggle('hidden', !show);
                });
                return;
            }

            const activityFilter = event.target.closest('[data-activity-filter]');
            if (activityFilter) {
                const filter = activityFilter.dataset.activityFilter;
                document.querySelectorAll('[data-activity-filters] button').forEach(button => {
                    button.classList.toggle('bg-indigo-600', button === activityFilter);
                    button.classList.toggle('text-white', button === activityFilter);
                    button.classList.toggle('bg-gray-100', button !== activityFilter);
                    button.classList.toggle('text-gray-700', button !== activityFilter);
                });
                document.querySelectorAll('[data-activity-item]').forEach(item => {
                    const typeMatch = filter === 'all' || item.dataset.activityType === filter;
                    const withinDefault = item.dataset.activityExtra !== '1' || item.dataset.activityExpanded === '1';
                    item.classList.toggle('hidden', !(typeMatch && withinDefault));
                });
                return;
            }

            const showMore = event.target.closest('[data-activity-show-more]');
            if (showMore) {
                document.querySelectorAll('[data-activity-item]').forEach(item => {
                    item.dataset.activityExpanded = '1';
                    item.classList.remove('hidden');
                });
                showMore.remove();
                return;
            }

            const button = event.target.closest('[data-copy-target]');
            if (!button) return;

            const target = document.getElementById(button.dataset.copyTarget);
            if (!target) return;

            try {
                await navigator.clipboard.writeText(target.value || target.textContent || '');
                button.textContent = 'تم النسخ';
                setTimeout(() => button.textContent = 'نسخ برومبت الطلب الحالي', 1600);
            } catch (error) {
                target.select();
                document.execCommand('copy');
            }
        });

        document.addEventListener('change', function (event) {
            const select = event.target.closest('[data-scene-action-select]');
            if (!select) return;

            const option = select.selectedOptions[0];
            const form = select.closest('[data-scene-select-form]');
            const note = form?.querySelector('[data-scene-readiness-note]');

            if (form && option?.dataset.action) {
                form.action = option.dataset.action;
            }

            if (note) {
                note.textContent = option?.dataset.ready === '1'
                    ? 'المشهد يحتوي على توجيه بصري ويمكن توليده عند اكتمال المتطلبات.'
                    : 'هذا المشهد لا يحتوي على توجيه بصري بعد.';
            }
        });

        document.addEventListener('submit', async function (event) {
            const form = event.target.closest('[data-studio-ai-form]');
            if (!form) return;

            event.preventDefault();

            const button = form.querySelector('button[type="submit"], button:not([type])');
            const feedback = form.querySelector('[data-studio-ai-feedback]') || form.closest('[data-studio-asset-card]')?.querySelector('[data-studio-ai-feedback]');
            const originalButtonText = button?.textContent;

            if (button) {
                button.disabled = true;
                button.textContent = 'جارٍ التنفيذ...';
            }
            studioFeedback(feedback, 'info', 'جارٍ إرسال الطلب...');

            try {
                const response = await fetch(form.action, {
                    method: form.method || 'POST',
                    body: new FormData(form),
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                const payload = await response.json().catch(() => ({}));

                if (!response.ok || payload.ok === false) {
                    const validationMessage = payload.errors
                        ? Object.values(payload.errors).flat().join(' ')
                        : null;
                    studioFeedback(feedback, 'error', validationMessage || payload.message || 'تعذر تنفيذ الطلب.');
                    return;
                }

                studioFeedback(feedback, 'success', payload.message || 'تم تنفيذ الطلب بنجاح.');

                if (payload.job) {
                    upsertStudioJob(payload.job, payload.status_url);
                    pollStudioJob(payload.status_url, feedback);
                }

                if (payload.asset) {
                    const card = form.closest('[data-studio-asset-card]');
                    const status = card?.querySelector('.text-xs.text-gray-500');
                    if (status) {
                        status.textContent = status.textContent.replace(/ - .+$/, ` - ${payload.asset.status}`);
                    }
                }
            } catch (error) {
                studioFeedback(feedback, 'error', 'حدث خطأ في الاتصال. حاول مرة أخرى.');
            } finally {
                if (button) {
                    button.disabled = false;
                    button.textContent = originalButtonText;
                }
            }
        });
    </script>
</x-admin-layout>
