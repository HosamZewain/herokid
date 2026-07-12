<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">استوديو الإنتاج #{{ $project->id }}</h2>
    </x-slot>

    <x-slot name="headerActions">
        @can('production_studio.ai_review')
            <button type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-lg border border-gray-200 bg-white px-3 text-xs font-black text-gray-600 shadow-sm transition hover:border-indigo-200 hover:text-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                    aria-controls="production-job-log-drawer"
                    aria-expanded="false"
                    data-studio-job-drawer-open>
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 2m6-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="hidden sm:inline">سجل التوليد</span>
                <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] text-indigo-700" data-studio-active-job-count>{{ $project->generationJobs->whereIn('status', ['queued', 'processing'])->count() }}</span>
            </button>
        @endcan
    </x-slot>

    <x-slot name="leftDrawer">
        @can('production_studio.ai_review')
            @include('admin.production-studio.partials.generation-job-drawer', ['project' => $project])
        @endcan
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
        $approvedReferencePhotoIndices = array_values(array_unique(array_map('intval', $profile?->approved_reference_photos ?? [])));
        $analysisPhotoIndices = $approvedReferencePhotoIndices !== []
            ? $approvedReferencePhotoIndices
            : array_keys($photos);
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
        $promptCompiler = app(\App\Services\Ai\ProductionPromptCompiler::class);
        $scenePromptPreviews = $project->scenes->mapWithKeys(function ($scene) use ($promptCompiler, $project, $approvedCharacterSheet) {
            return [$scene->id => $promptCompiler->compile(
                project: $project,
                scene: $scene,
                jobType: 'scene_image',
                stylePreset: 'premium_storybook',
                characterSheet: $approvedCharacterSheet,
            )];
        });
        $hasStoryDraft = $project->storyVersions->isNotEmpty();
        $qaProgress = $project->qaProgress();
        $qaFailed = $project->qaChecks->where('result', 'fail')->count();
        $qaPending = $project->qaChecks->where('result', 'not_reviewed')->count();
        $totalScenes = $project->scenes->count();
        $missingVisualScenes = $project->scenes->filter(fn ($scene) => blank($scene->visual_direction))->count();
        $readyScenes = $project->scenes->filter(fn ($scene) => $scene->hasImagePromptContext() && $scene->isPersonalizedForImageGeneration())->count();
        $personalizedScenes = $project->scenes->filter(fn ($scene) => $scene->isPersonalizedForImageGeneration())->count();
        $conflictingScenes = $project->scenes->filter(fn ($scene) => $scene->oldHeroConflicts() !== [])->count();
        $approvedSceneImages = $sceneAssets->where('status', 'approved')->count();
        $jobCompleted = $project->generationJobs->where('status', 'completed')->count();
        $jobFailed = $project->generationJobs->where('status', 'failed')->count();
        $jobProcessing = $project->generationJobs->whereIn('status', ['queued', 'processing'])->count();
        $bulkExcludedSceneIds = $sceneAssets
            ->whereIn('status', ['under_review', 'approved'])
            ->pluck('production_scene_id')
            ->merge($project->generationJobs
                ->where('job_type', 'scene_image')
                ->whereIn('status', ['queued', 'processing'])
                ->pluck('production_scene_id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();
        $bulkCandidateScenes = $project->scenes
            ->reject(fn ($scene) => $bulkExcludedSceneIds->contains((int) $scene->id))
            ->sortBy('scene_number')
            ->values();
        $bulkBlockedScenes = $bulkCandidateScenes
            ->reject(fn ($scene) => $scene->hasImagePromptContext() && $scene->isPersonalizedForImageGeneration())
            ->values();
        $sceneExtractionJobs = $project->generationJobs
            ->where('job_type', 'scene_extraction')
            ->sortByDesc('created_at');
        $latestSceneExtractionJob = $sceneExtractionJobs->first();
        $pendingSceneExtractionJob = $sceneExtractionJobs
            ->where('job_type', 'scene_extraction')
            ->whereIn('status', ['queued', 'processing'])
            ->first();
        $failedSceneExtractionJob = $latestSceneExtractionJob?->status === 'failed' ? $latestSceneExtractionJob : null;
        $scenePersonalization = data_get($sceneExtractionPreview, 'personalization', []);
        $personalizedPreviewData = data_get($sceneExtractionPreview, 'personalized_data', data_get($sceneExtractionPreview, 'data'));
        $personalizationNeedsAiGenderRewrite = data_get($scenePersonalization, 'gender_adaptation_needed') && ! data_get($scenePersonalization, 'gender_adaptation_applied');
        $pendingCharacterAnalysisJob = $project->generationJobs
            ->where('job_type', 'character_analysis')
            ->whereIn('status', ['queued', 'processing'])
            ->sortByDesc('created_at')
            ->first();
        $failedCharacterAnalysisJob = $project->generationJobs
            ->where('job_type', 'character_analysis')
            ->where('status', 'failed')
            ->sortByDesc('created_at')
            ->first();
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
        $approvedCoverAssets = $coverAssets->where('status', 'approved');
        $backCoverAssets = $project->assets->where('asset_type', 'back_cover_image')->where('status', 'approved');
        $layoutStatusLabels = ['draft' => 'مسودة', 'queued' => 'في قائمة الانتظار', 'processing' => 'جارٍ التوليد', 'ready' => 'جاهز', 'failed' => 'فشل'];
        $activityFilters = ['all' => 'All', 'project' => 'project', 'story' => 'story', 'character' => 'character', 'ai' => 'AI', 'asset' => 'asset', 'qa' => 'QA', 'status' => 'status'];
        $canManageAutomation = auth()->user()->hasPermission('production_studio.automation_manage');
        $automationEnabled = \App\Support\ProductionAutomation::enabled();
        $automationStatusText = $automationRun
            ? '#'.$automationRun->id.' · '.$automationRun->status.' · '.($automationRun->current_stage ?: $automationRun->current_step_key)
            : ($automationEnabled ? 'جاهز للفحص قبل التشغيل' : 'معطل من الإعدادات');
        $automationInitialProgress = $automationRun
            ? app(\App\Services\ProductionStudio\ProductionAutomationProgress::class)->percentage($automationRun)
            : 0;
        $automationStatusTone = $automationRun
            ? match ($automationRun->status) {
                \App\Support\ProductionAutomation::STATUS_COMPLETED => 'emerald',
                \App\Support\ProductionAutomation::STATUS_CANCELLED, \App\Support\ProductionAutomation::STATUS_FAILED => 'red',
                \App\Support\ProductionAutomation::STATUS_PAUSED_BUDGET, \App\Support\ProductionAutomation::STATUS_PAUSED_REVIEW, \App\Support\ProductionAutomation::STATUS_PROVIDER_FAILED => 'amber',
                default => 'indigo',
            }
            : ($automationEnabled ? 'emerald' : 'amber');
        if ($canManageAutomation || $automationRun) {
            $sectionNav = array_slice($sectionNav, 0, 1, true)
                + ['automation-run' => 'الإنتاج التلقائي']
                + array_slice($sectionNav, 1, null, true);
        }
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

        @if($canManageAutomation || $automationRun)
            @include('admin.production-studio.partials.workflow-card-open', [
                'id' => 'automation-run',
                'title' => 'الإنتاج التلقائي',
                'description' => 'تشغيل دورة الإنتاج الآلية مع الفحص المسبق، الميزانية، الإيقاف، الاستئناف، والإلغاء.',
                'status' => $automationStatusText,
                'statusTone' => $automationStatusTone,
                'warning' => $automationEnabled ? null : 'Automation flag disabled',
                'summary' => $automationRun ? 'تابع الدورة الحالية أو أوقفها بأمان' : 'ابدأ بفحص قبل التشغيل ثم شغّل Pilot بميزانية محدودة',
            ])
                <div class="space-y-4 text-right" data-automation-panel
                     data-preflight-url="{{ route('admin.production-studio.automation.preflight', $project) }}"
                     data-start-url="{{ route('admin.production-studio.automation.start', $project) }}"
                     data-status-url="{{ route('admin.production-studio.automation.status', $project) }}"
                     data-pause-url="{{ route('admin.production-studio.automation.pause', $project) }}"
                     data-resume-url="{{ route('admin.production-studio.automation.resume', $project) }}"
                     data-cancel-url="{{ route('admin.production-studio.automation.cancel', $project) }}"
                     data-story-approve-url="{{ route('admin.production-studio.automation.story-preparation.approve', $project) }}"
                     data-retry-step-url="{{ route('admin.production-studio.automation.retry-step', $project) }}">
                    @unless($automationEnabled)
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-black text-amber-900">
                            الإنتاج التلقائي غير مفعل في الكاش الحالي. فعّل <span dir="ltr">HERO_KID_PRODUCTION_STUDIO_AUTOMATION_ENABLED=true</span> ثم شغّل <span dir="ltr">php artisan config:cache</span>.
                        </div>
                    @endunless

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div class="rounded-xl bg-gray-50 p-4">
                            <p class="text-xs font-bold text-gray-400">الحالة</p>
                            <p class="mt-1 font-black text-gray-900" data-automation-run-status>{{ $automationRun?->status ?? 'لا توجد دورة' }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-4">
                            <p class="text-xs font-bold text-gray-400">المرحلة</p>
                            <p class="mt-1 font-black text-gray-900" data-automation-run-stage>{{ $automationRun?->current_stage ?? '-' }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-4">
                            <p class="text-xs font-bold text-gray-400">الخطوة</p>
                            <p class="mt-1 font-black text-gray-900" data-automation-run-step>{{ $automationRun?->current_step_key ?? '-' }}</p>
                        </div>
                        <div class="rounded-xl bg-gray-50 p-4">
                            <p class="text-xs font-bold text-gray-400">الميزانية</p>
                            <p class="mt-1 font-black text-gray-900" data-automation-run-budget>{{ $automationRun?->hard_budget ? '$'.$automationRun->hard_budget : '-' }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl border border-indigo-100 bg-white p-4 shadow-sm" data-automation-lifecycle>
                        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-xs font-black uppercase tracking-wide text-indigo-500">Live lifecycle</p>
                                <h3 class="mt-1 text-lg font-black text-gray-950">مسار الإنتاج التلقائي</h3>
                                <p class="mt-1 text-sm font-bold text-gray-500" data-automation-current-stage>
                                    {{ $automationRun ? ($automationRun->current_stage ?: $automationRun->current_step_key) : 'لم تبدأ دورة بعد' }}
                                </p>
                            </div>
                            <div class="rounded-2xl bg-indigo-50 px-5 py-3 text-center">
                                <p class="text-xs font-black text-indigo-500">التقدم المعتمد</p>
                                <p class="text-3xl font-black text-indigo-700" data-automation-progress-label>{{ $automationInitialProgress }}%</p>
                            </div>
                        </div>
                        <div class="mt-4 h-4 overflow-hidden rounded-full bg-gray-100 ring-1 ring-gray-200">
                            <div class="h-full rounded-full bg-indigo-600 transition-all duration-500" data-automation-progress-bar style="width: {{ $automationInitialProgress }}%"></div>
                        </div>
                        <div class="mt-3 grid grid-cols-1 gap-2 text-xs font-bold text-gray-500 md:grid-cols-4">
                            <div>0-40%: تحضير القصة والهوية</div>
                            <div>40-80%: الغلاف والمشاهد</div>
                            <div>80-95%: ملفات الطباعة</div>
                            <div>100%: اعتماد بشري نهائي</div>
                        </div>
                        <div class="mt-4 hidden rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold text-amber-900" data-automation-blockers></div>
                        <div class="mt-4 hidden rounded-xl border border-amber-200 bg-amber-50 p-4" data-automation-review-actions></div>
                        <div class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-4" data-automation-phase-grid>
                            <div class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-sm font-bold text-gray-500 lg:col-span-4">
                                اضغط "تحديث الحالة" أو انتظر التحديث التلقائي لعرض مراحل الدورة.
                            </div>
                        </div>
                    </div>

                    @can('production_studio.automation_manage')
                        @if(! $automationRun)
                            <form data-automation-start-form class="rounded-xl border border-indigo-100 bg-indigo-50 p-4">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                                    <label class="text-sm font-black text-indigo-950">Hard budget USD
                                        <input name="hard_budget" type="number" min="0" step="0.01" value="2.00" required class="mt-2 w-full rounded-xl border-indigo-200 text-left" dir="ltr">
                                    </label>
                                    <label class="text-sm font-black text-indigo-950">الجودة
                                        <select name="generation_quality" class="mt-2 w-full rounded-xl border-indigo-200">
                                            <option value="high">high</option>
                                            <option value="medium">medium</option>
                                        </select>
                                    </label>
                                    <label class="text-sm font-black text-indigo-950">تزامن المشاهد
                                        <input name="scene_concurrency" type="number" min="1" max="5" value="{{ config('production_studio.automation.scene_concurrency', 2) }}" class="mt-2 w-full rounded-xl border-indigo-200 text-left" dir="ltr">
                                    </label>
                                    <label class="text-sm font-black text-indigo-950">النمط
                                        <select name="style_preset" class="mt-2 w-full rounded-xl border-indigo-200">
                                            @foreach($stylePresets as $key => $label)
                                                <option value="{{ $key }}" @selected($key === config('production_studio.automation.default_style_preset', 'premium_storybook'))>{{ $key }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                </div>
                                <div class="mt-4 flex flex-col gap-3 md:flex-row">
                                    <button type="button" data-automation-preflight class="rounded-xl bg-white px-5 py-3 text-sm font-black text-indigo-700 ring-1 ring-indigo-200 hover:bg-indigo-50">فحص قبل التشغيل</button>
                                    <button type="button" data-automation-start @disabled(!$automationEnabled) class="rounded-xl bg-indigo-600 px-5 py-3 text-sm font-black text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-gray-300">بدء الإنتاج التلقائي</button>
                                </div>
                            </form>
                        @else
                            <div class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                                <p class="text-sm font-bold leading-7 text-gray-700">
                                    توجد دورة إنتاج تلقائي حالية. استخدم الأزرار التالية فقط عند الحاجة، فالاستئناف سيكمل من آخر خطوة آمنة ولا يعيد إنشاء الأصول المتوافقة.
                                </p>
                                <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4">
                                    <button type="button" data-automation-status class="rounded-xl bg-white px-4 py-3 text-sm font-black text-gray-700 ring-1 ring-gray-200 hover:bg-gray-50">تحديث الحالة</button>
                                    <button type="button" data-automation-pause class="rounded-xl bg-amber-600 px-4 py-3 text-sm font-black text-white hover:bg-amber-700">إيقاف مؤقت</button>
                                    <button type="button" data-automation-resume @disabled(!$automationEnabled) class="rounded-xl bg-indigo-600 px-4 py-3 text-sm font-black text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-gray-300">استئناف</button>
                                    <div class="flex gap-2">
                                        <input name="cancel_reason" data-automation-cancel-reason value="pilot_cancelled" class="min-w-0 flex-1 rounded-xl border-gray-200 text-right text-sm">
                                        <button type="button" data-automation-cancel class="rounded-xl bg-red-600 px-4 py-3 text-sm font-black text-white hover:bg-red-700">إلغاء</button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @else
                        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-black text-amber-900">
                            تحتاج صلاحية <span dir="ltr">production_studio.automation_manage</span> لبدء أو إدارة الإنتاج التلقائي.
                        </div>
                    @endcan

                    <div data-automation-feedback class="hidden rounded-xl border p-4 text-sm font-bold leading-7"></div>
                </div>
            @include('admin.production-studio.partials.workflow-card-close')
        @endif

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
                            <p class="mt-1 text-sm leading-7 text-indigo-900">هذه الخطوة تبني معاينة منظمة لـ 13 مشهدًا من نص القصة. بعد نجاح المهمة راجع المعاينة ثم اضغط “تأكيد واستبدال المشاهد الحالية” لحفظها في القائمة.</p>
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
                    @if($pendingSceneExtractionJob)
                        <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-right text-sm font-bold text-blue-800">
                            مهمة استخراج المشاهد #{{ $pendingSceneExtractionJob->id }} حالتها: {{ $pendingSceneExtractionJob->status }}.
                            شغّل الكرون/queue worker ثم حدّث الصفحة لظهور المعاينة. هذه المهمة لا تحفظ المشاهد تلقائيًا إلا بعد التأكيد.
                        </div>
                    @endif
                    @if($failedSceneExtractionJob)
                        <div class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-right text-sm font-bold text-red-700">
                            آخر محاولة استخراج مشاهد فشلت: {{ $failedSceneExtractionJob->error_message ?: 'حدث خطأ غير معروف.' }}
                        </div>
                    @endif

                    @if($sceneExtractionPreview)
                        <div class="mt-4 rounded-xl border border-emerald-200 bg-white p-4">
                            <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p class="font-black text-emerald-800">معاينة جاهزة للحفظ: {{ count(data_get($sceneExtractionPreview, 'data.scenes', [])) }} مشهد</p>
                                    <p class="text-xs text-gray-500">المصدر: {{ data_get($sceneExtractionPreview, 'source') }}</p>
                                </div>
                            </div>
                            <div class="mt-4 grid grid-cols-1 gap-3 rounded-xl border border-indigo-100 bg-indigo-50 p-4 text-right md:grid-cols-2 xl:grid-cols-4">
                                <div><p class="text-xs font-bold text-indigo-500">بطل القالب المكتشف</p><p class="mt-1 font-black text-indigo-950">{{ data_get($scenePersonalization, 'template_hero_name') ?: 'غير مؤكد' }}</p></div>
                                <div><p class="text-xs font-bold text-indigo-500">درجة الثقة</p><p class="mt-1 font-black text-indigo-950">{{ data_get($scenePersonalization, 'confidence', 'low') }}</p></div>
                                <div><p class="text-xs font-bold text-indigo-500">الطفل البديل</p><p class="mt-1 font-black text-indigo-950">{{ data_get($scenePersonalization, 'child_hero_name') ?: $order->child_name }}</p></div>
                                <div><p class="text-xs font-bold text-indigo-500">تعديل صياغة الجنس</p><p class="mt-1 font-black text-indigo-950">{{ data_get($scenePersonalization, 'gender_adaptation_needed') ? (data_get($scenePersonalization, 'gender_adaptation_applied') ? 'تم عبر OpenAI' : 'مطلوب') : 'غير مطلوب' }}</p></div>
                                <div class="md:col-span-2 xl:col-span-4"><p class="text-xs font-bold text-indigo-500">الشخصيات المساندة</p><p class="mt-1 text-sm font-bold text-indigo-950">{{ implode('، ', data_get($scenePersonalization, 'supporting_characters', [])) ?: 'لم يتم اكتشاف أسماء مساندة' }}</p></div>
                            </div>
                            @if(data_get($scenePersonalization, 'warnings'))
                                <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm font-bold leading-7 text-amber-800">
                                    @foreach(data_get($scenePersonalization, 'warnings', []) as $warning)
                                        <p>• {{ $warning }}</p>
                                    @endforeach
                                </div>
                            @endif
                            <form method="POST" action="{{ route('admin.production-studio.story-versions.apply-scenes', $project) }}" class="mt-4 rounded-xl border border-gray-100 p-4">
                                @csrf
                                <input type="hidden" name="confirm_personalization" value="1">
                                <label class="block text-right">
                                    <span class="text-sm font-black text-gray-800">اسم بطل القالب المراد استبداله</span>
                                    <input name="detected_hero_name" value="{{ data_get($scenePersonalization, 'template_hero_name') }}" class="mt-2 w-full rounded-xl border-gray-300 text-right" placeholder="مثال: جنا">
                                    <span class="mt-1 block text-xs text-gray-500">يمكنك تصحيح الاسم قبل الحفظ. سيتم التغيير داخل مشاهد هذا المشروع فقط.</span>
                                </label>
                                <div class="mt-3 flex flex-wrap justify-end gap-2">
                                    <button name="personalization_action" value="skip" class="rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-black text-gray-700">حفظ بدون تخصيص</button>
                                    <button name="personalization_action" value="confirm" @disabled($personalizationNeedsAiGenderRewrite) class="rounded-xl bg-emerald-600 px-4 py-2 text-sm font-black text-white disabled:bg-gray-300">تأكيد تخصيص المشاهد باسم {{ $order->child_name }}</button>
                                </div>
                                @if($personalizationNeedsAiGenderRewrite)
                                    <p class="mt-2 text-sm font-bold text-amber-700">اختلاف الجنس يحتاج إعادة بناء عبر OpenAI قبل السماح بتأكيد التخصيص.</p>
                                @elseif(data_get($scenePersonalization, 'status') !== 'personalized')
                                    <p class="mt-2 text-sm font-bold text-amber-700">راجع اسم بطل القالب أو صححه ثم اضغط تأكيد التخصيص.</p>
                                @endif
                            </form>
                            <details class="mt-3">
                                <summary class="cursor-pointer text-sm font-black text-indigo-700">عرض JSON المشاهد المخصصة</summary>
                                <pre dir="ltr" class="mt-2 max-h-72 overflow-auto rounded-lg bg-gray-50 p-3 text-left text-xs">{{ json_encode($personalizedPreviewData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
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
            <div class="space-y-5">
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
                                @forelse($analysisPhotoIndices as $photoIndex)
                                    <label class="rounded-xl bg-white px-3 py-2 text-sm font-bold text-gray-700 ring-1 ring-purple-100">
                                        <input type="checkbox" name="reference_photo_indices[]" value="{{ $photoIndex }}" @checked($profile?->primaryFaceReferenceIndex() === (int) $photoIndex || ($primaryFaceIndex === null && $loop->first))>
                                        صورة {{ ((int) $photoIndex) + 1 }}
                                    </label>
                                @empty
                                    <p class="rounded-xl bg-white px-3 py-2 text-sm font-bold text-amber-700 ring-1 ring-amber-100">لا توجد صور طفل مرفوعة على الطلب.</p>
                                @endforelse
                            </div>
                            <button @disabled(!$visionModelReady || count($analysisPhotoIndices) === 0) class="rounded-xl bg-purple-600 px-4 py-2.5 text-sm font-black text-white disabled:bg-gray-300">تحليل صور الطفل بالذكاء الاصطناعي</button>
                        </form>
                        @if($approvedReferencePhotoIndices === [] && count($analysisPhotoIndices) > 0)
                            <p class="mt-2 text-sm font-bold text-purple-800">لم يتم اعتماد صور مرجعية بعد؛ سيتم تحليل صور الطلب الأصلية مؤقتًا. بعد التحليل اختر صورة الوجه الأساسية واحفظ ملف الشخصية.</p>
                        @endif
                        @unless($visionModelReady)
                            <p class="mt-2 text-sm font-bold text-amber-700">فعّل نموذج OpenAI افتراضي بقدرة vision_to_text قبل تحليل صور الطفل.</p>
                        @endunless
                        @if($pendingCharacterAnalysisJob)
                            <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 p-3 text-right text-sm font-bold text-blue-800">
                                مهمة تحليل الصور #{{ $pendingCharacterAnalysisJob->id }} حالتها: {{ $pendingCharacterAnalysisJob->status }}.
                                شغّل الكرون/queue worker ثم حدّث الصفحة لظهور المعاينة.
                            </div>
                        @endif
                        @if($failedCharacterAnalysisJob)
                            <div class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-right text-sm font-bold text-red-700">
                                آخر محاولة تحليل صور فشلت: {{ $failedCharacterAnalysisJob->error_message ?: 'حدث خطأ غير معروف.' }}
                            </div>
                        @endif

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
            </div>

            <form method="POST" action="{{ route('admin.production-studio.character-profile.update', $project) }}" class="mt-5 space-y-5">
                @csrf
                @method('PATCH')
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
                            <option value="{{ $model->code }}" @selected($model->code === $characterSheetModel)>
                                {{ $model->provider->public_name }} — {{ $model->display_name }} · ${{ $model->estimatedCost() }}
                            </option>
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
            'summary' => $personalizedScenes.' مخصصة · '.$conflictingScenes.' تعارض أسماء · '.$readyScenes.' مكتملة المحتوى · '.$approvedSceneImages.' صور معتمدة',
        ])
            <div class="grid grid-cols-1 gap-3 text-right md:grid-cols-3 xl:grid-cols-6">
                <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-400">الإجمالي</p><p class="font-black">{{ $totalScenes }}</p></div>
                <div class="rounded-xl bg-emerald-50 p-4"><p class="text-xs text-emerald-600">جاهزة للتوليد</p><p class="font-black">{{ $readyScenes }}</p></div>
                <div class="rounded-xl bg-amber-50 p-4"><p class="text-xs text-amber-600">ناقصة توجيه</p><p class="font-black">{{ $missingVisualScenes }}</p></div>
                <div class="rounded-xl bg-indigo-50 p-4"><p class="text-xs text-indigo-600">صور معتمدة</p><p class="font-black">{{ $approvedSceneImages }}</p></div>
                <div class="rounded-xl bg-blue-50 p-4"><p class="text-xs text-blue-600">مشاهد مخصصة</p><p class="font-black">{{ $personalizedScenes }}</p></div>
                <div class="rounded-xl bg-red-50 p-4"><p class="text-xs text-red-600">تعارض أسماء</p><p class="font-black">{{ $conflictingScenes }}</p></div>
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
                        'sceneGenerationModels' => $aiModelsByCapability['scene_generation'] ?? collect(),
                        'sceneImproveModelReady' => $sceneImproveModelReady,
                        'sceneImproveModel' => $sceneImproveModel,
                        'sceneImprovementPreviews' => $sceneImprovementPreviews,
                        'scenePromptPreview' => $scenePromptPreviews[$scene->id] ?? null,
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
            'summary' => $bulkCandidateScenes->count().' مشهد يحتاج توليدًا | '.$bulkBlockedScenes->count().' غير جاهز',
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

            <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50 p-4 text-right text-sm font-bold leading-7 text-blue-900">
                يمكنك اختيار موديل توليد الصور لكل محاولة. fal.ai مناسب للتجارب السريعة، وOpenAI Image متاح كخيار إضافي عند تفعيله من إعدادات مزودي الذكاء الاصطناعي. السعر المعروض بجوار كل موديل تقديري لكل صورة.
            </div>

            @can('production_studio.ai_generate')
                <div class="mt-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-right">
                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div>
                            <h3 class="font-black text-emerald-950">توليد كل المشاهد الناقصة</h3>
                            <p class="mt-1 text-sm leading-7 text-emerald-900">
                                سيتم إنشاء مهمة Queue مستقلة لكل مشهد. لن يعاد توليد مشهد له صورة معتمدة، أو صورة بانتظار المراجعة، أو مهمة جارية.
                            </p>
                            <p class="mt-2 text-sm font-black text-emerald-950">
                                {{ $bulkCandidateScenes->count() }} مشهد يحتاج توليدًا
                                @if($bulkBlockedScenes->isNotEmpty())
                                    · {{ $bulkBlockedScenes->count() }} غير جاهز
                                @endif
                            </p>
                        </div>
                        @include('admin.production-studio.partials.status-badge', [
                            'label' => $bulkBlockedScenes->isEmpty() && $bulkCandidateScenes->isNotEmpty() ? 'جاهز للتوليد الجماعي' : 'يحتاج استكمال',
                            'tone' => $bulkBlockedScenes->isEmpty() && $bulkCandidateScenes->isNotEmpty() ? 'emerald' : 'amber',
                        ])
                    </div>

                    @if($bulkBlockedScenes->isNotEmpty())
                        <p class="mt-3 rounded-lg border border-amber-200 bg-white p-3 text-xs font-bold leading-6 text-amber-900">
                            أكمل أولًا:
                            {{ $bulkBlockedScenes->map(fn ($scene) => 'مشهد '.$scene->scene_number.' — '.($scene->title ?: 'بدون عنوان'))->implode('، ') }}
                        </p>
                    @endif

                    <form method="POST" action="{{ route('admin.production-studio.ai.scenes.bulk', $project) }}"
                          data-studio-ai-form data-studio-bulk-ai-form data-scene-count="{{ $bulkCandidateScenes->count() }}"
                          class="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-4">
                        @csrf
                        <input type="hidden" name="confirm_bulk_generation" value="1">
                        <input type="hidden" name="identity_lock" value="1">
                        @if($approvedCharacterSheet)
                            <input type="hidden" name="character_sheet_id" value="{{ $approvedCharacterSheet->id }}">
                        @endif
                        <select name="model_code" @disabled(!$aiAvailable) class="rounded-xl border-emerald-200 text-right" data-bulk-model>
                            @foreach($aiModelsByCapability['scene_generation'] ?? collect() as $model)
                                <option value="{{ $model->code }}" @selected($model->code === $defaultModel)
                                        data-cost-medium="{{ data_get($model->configuration_json, 'quality_costs.medium', $model->estimatedCost()) }}"
                                        data-cost-high="{{ data_get($model->configuration_json, 'quality_costs.high', $model->estimatedCost()) }}">
                                    {{ $model->provider->public_name }} — {{ $model->display_name }} · ${{ $model->estimatedCost() }}
                                </option>
                            @endforeach
                        </select>
                        <select name="style_preset" @disabled(!$aiAvailable) class="rounded-xl border-emerald-200 text-right">
                            @foreach($stylePresets as $key => $label)
                                <option value="{{ $key }}">{{ $key }}</option>
                            @endforeach
                        </select>
                        <select name="generation_quality" @disabled(!$aiAvailable) class="rounded-xl border-emerald-200 text-right" data-bulk-quality>
                            <option value="medium">Draft · medium</option>
                            <option value="high">Final · high</option>
                        </select>
                        <input name="prompt_notes" @disabled(!$aiAvailable) class="rounded-xl border-emerald-200 text-right" placeholder="ملاحظة تطبق على كل المشاهد">
                        <p class="rounded-xl border border-emerald-200 bg-white p-3 text-sm font-black text-emerald-900 lg:col-span-3" data-bulk-cost-summary>
                            اختر الموديل والجودة لعرض التكلفة الإجمالية التقديرية.
                        </p>
                        <button @disabled(!$aiAvailable || !$profileReady || !$approvedCharacterSheet || $bulkCandidateScenes->isEmpty() || $bulkBlockedScenes->isNotEmpty())
                                class="rounded-xl bg-emerald-700 px-4 py-3 text-sm font-black text-white disabled:cursor-not-allowed disabled:bg-gray-300">
                            توليد {{ $bulkCandidateScenes->count() }} مشهد دفعة واحدة
                        </button>
                        <div data-studio-ai-feedback class="hidden rounded-xl border p-3 text-sm font-bold lg:col-span-4"></div>
                    </form>
                </div>
            @endcan

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
                                    <option value="{{ $model->code }}" @selected($model->code === $premiumModel)>
                                        {{ $model->provider->public_name }} — {{ $model->display_name }} · ${{ $model->estimatedCost() }}
                                    </option>
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
                                    <option value="{{ $model->code }}" @selected($model->code === $defaultModel)>
                                        {{ $model->provider->public_name }} — {{ $model->display_name }} · ${{ $model->estimatedCost() }}
                                    </option>
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
                            <input type="hidden" name="identity_lock" value="1">
                            <select name="generation_quality" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                                <option value="medium">Draft · medium</option>
                                <option value="high">Final · high</option>
                            </select>
                            <select name="output_count" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right">
                                <option value="1">نسخة واحدة</option>
                                <option value="2">نسختان · تكلفة مضاعفة</option>
                            </select>
                            <input name="prompt_notes" @disabled(!$aiAvailable) class="rounded-xl border-gray-300 text-right" placeholder="ملاحظات اختيارية">
                            <p class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-bold text-emerald-800">Identity Lock مفعّل. صورة الوجه الأساسية هي المرجع الأول والحاكم، والرسم المعتمد مرجع ثانوي فقط.</p>
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
                @foreach($sceneAssets->sortBy(fn ($asset) => sprintf('%04d-%04d', $asset->scene?->scene_number ?? 9999, $asset->version_number)) as $asset)
                    @include('admin.production-studio.partials.asset-card', ['asset' => $asset, 'project' => $project])
                @endforeach
            </div>

        @include('admin.production-studio.partials.workflow-card-close')

        @include('admin.production-studio.partials.workflow-card-open', [
            'id' => 'layout-print',
            'title' => 'الإخراج والطباعة',
            'description' => 'تجهيز 28 صفحة A4 ثم إنشاء Reader PDF ونسخة A3 مفروضة للطباعة.',
            'status' => $printLayout ? ($layoutStatusLabels[$printLayout->status] ?? $printLayout->status) : 'لم يبدأ',
            'statusTone' => $printLayout?->status === 'ready' ? 'emerald' : ($printLayout?->status === 'failed' ? 'red' : 'amber'),
            'warning' => $layoutReadiness['ready'] ? null : count($layoutReadiness['errors']).' متطلبات ناقصة',
            'summary' => $layoutReadiness['approved_scenes'].'/13 صورة مشهد معتمدة | 7 شيت A3 Duplex | 14 وجه طباعة',
        ])
            <div class="space-y-5 text-right" data-layout-workspace>
                <div class="rounded-xl border border-indigo-100 bg-indigo-50 p-4">
                    <p class="font-black text-indigo-950">ترتيب العمل</p>
                    <p class="mt-2 text-sm leading-7 text-indigo-800">1. اعتمد الغلاف و13 صورة مشهد. 2. راجع نص ومكانه لكل مشهد. 3. احفظ وافتح المعاينة. 4. ولّد الملفات وانتظر الـ Queue. 5. نزّل الملفات وراجع Proof Checklist.</p>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">المشاهد المعتمدة</p><p class="mt-1 text-xl font-black">{{ $layoutReadiness['approved_scenes'] }}/13</p></div>
                    <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">صفحات القارئ</p><p class="mt-1 text-xl font-black">28 A4</p></div>
                    <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">شيتات الطباعة</p><p class="mt-1 text-xl font-black">7 A3</p></div>
                    <div class="rounded-xl {{ $layoutReadiness['ready'] ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800' }} p-4"><p class="text-xs">الجاهزية</p><p class="mt-1 text-lg font-black">{{ $layoutReadiness['ready'] ? 'جاهز للتوليد' : 'يحتاج استكمال' }}</p></div>
                </div>

                @if(!$layoutReadiness['ready'])
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm font-bold leading-7 text-amber-900">
                        <p class="font-black">أكمل المتطلبات التالية:</p>
                        <ul class="mt-2 list-inside list-disc">
                            @foreach($layoutReadiness['errors'] as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <form method="POST" action="{{ route('admin.production-studio.layout.assets.store', $project) }}" enctype="multipart/form-data" class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                        @csrf
                        <input type="hidden" name="asset_type" value="cover_image">
                        <label class="block text-sm font-black text-gray-800">رفع غلاف أمامي يدوي</label>
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required class="mt-3 block w-full rounded-lg border border-gray-200 bg-white p-3 text-sm">
                        <button class="mt-3 rounded-lg bg-gray-900 px-4 py-2 text-sm font-black text-white">رفع الغلاف الأمامي</button>
                    </form>
                    <form method="POST" action="{{ route('admin.production-studio.layout.assets.store', $project) }}" enctype="multipart/form-data" class="rounded-xl border border-gray-100 bg-gray-50 p-4">
                        @csrf
                        <input type="hidden" name="asset_type" value="back_cover_image">
                        <label class="block text-sm font-black text-gray-800">رفع غلاف خلفي اختياري</label>
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required class="mt-3 block w-full rounded-lg border border-gray-200 bg-white p-3 text-sm">
                        <button class="mt-3 rounded-lg bg-gray-900 px-4 py-2 text-sm font-black text-white">رفع الغلاف الخلفي</button>
                    </form>
                </div>

                <form method="POST" action="{{ route('admin.production-studio.layout.generate', $project) }}" data-studio-layout-form class="space-y-5">
                    @csrf
                    <div class="grid grid-cols-1 gap-4 rounded-xl border border-gray-100 p-4 md:grid-cols-2 lg:grid-cols-4">
                        <label class="text-sm font-black text-gray-700 lg:col-span-2">عنوان الكتاب
                            <input name="book_title" value="{{ old('book_title', data_get($layoutSettings, 'book_title', $project->order?->story?->title ?: 'HeroKid')) }}" required class="mt-2 w-full rounded-lg border-gray-200 text-right">
                        </label>
                        <label class="text-sm font-black text-gray-700">سطر اسم الطفل على الغلاف
                            <input name="cover_subtitle" value="{{ old('cover_subtitle', data_get($layoutSettings, 'cover_subtitle', '')) }}" class="mt-2 w-full rounded-lg border-gray-200 text-right">
                        </label>
                        <label class="text-sm font-black text-gray-700">مكان عنوان الغلاف
                            <select name="cover_title_position" class="mt-2 w-full rounded-lg border-gray-200">
                                <option value="top" @selected(data_get($layoutSettings, 'cover_title_position', 'top') === 'top')>أعلى الغلاف</option>
                                <option value="bottom" @selected(data_get($layoutSettings, 'cover_title_position', 'top') === 'bottom')>أسفل الغلاف</option>
                            </select>
                        </label>
                        <label class="text-sm font-black text-gray-700">الغلاف الأمامي
                            <select name="cover_asset_id" required class="mt-2 w-full rounded-lg border-gray-200 text-right">
                                <option value="">اختر غلافًا معتمدًا</option>
                                @foreach($approvedCoverAssets as $asset)
                                    <option value="{{ $asset->id }}" @selected((int) old('cover_asset_id', data_get($layoutSettings, 'cover_asset_id')) === $asset->id)>#{{ $asset->id }} — {{ $asset->label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-black text-gray-700">الغلاف الخلفي
                            <select name="back_cover_asset_id" class="mt-2 w-full rounded-lg border-gray-200 text-right">
                                <option value="">تصميم HeroKid تلقائي</option>
                                @foreach($backCoverAssets as $asset)
                                    <option value="{{ $asset->id }}" @selected((int) old('back_cover_asset_id', data_get($layoutSettings, 'back_cover_asset_id')) === $asset->id)>#{{ $asset->id }} — {{ $asset->label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-black text-gray-700 lg:col-span-2">نص الغلاف الخلفي
                            <textarea name="back_cover_text" rows="2" class="mt-2 w-full rounded-lg border-gray-200 text-right">{{ old('back_cover_text', data_get($layoutSettings, 'back_cover_text', '')) }}</textarea>
                        </label>
                        <label class="text-sm font-black text-gray-700">الموقع
                            <input name="website" value="{{ old('website', data_get($layoutSettings, 'website', '')) }}" class="mt-2 w-full rounded-lg border-gray-200 text-left" dir="ltr">
                        </label>
                        <label class="text-sm font-black text-gray-700">اتجاه التجليد
                            <select name="binding_direction" class="mt-2 w-full rounded-lg border-gray-200">
                                <option value="rtl" @selected(data_get($layoutSettings, 'binding_direction', 'rtl') === 'rtl')>عربي — التجليد من اليمين</option>
                                <option value="ltr" @selected(data_get($layoutSettings, 'binding_direction', 'rtl') === 'ltr')>يسار إلى يمين</option>
                            </select>
                        </label>
                        <input type="hidden" name="duplex_flip" value="short_edge">
                        <label class="text-sm font-black text-gray-700">حجم النص
                            <input type="number" name="font_size" min="14" max="30" value="{{ old('font_size', data_get($layoutSettings, 'font_size', 20)) }}" class="mt-2 w-full rounded-lg border-gray-200">
                        </label>
                        <label class="text-sm font-black text-gray-700">وضوح خلفية النص %
                            <input type="number" name="text_panel_opacity" min="70" max="100" value="{{ old('text_panel_opacity', data_get($layoutSettings, 'text_panel_opacity', 92)) }}" class="mt-2 w-full rounded-lg border-gray-200">
                        </label>
                    </div>

                    <details class="rounded-xl border border-gray-100 bg-gray-50" open>
                        <summary class="cursor-pointer p-4 font-black text-gray-900">نصوص المشاهد ومكان النص</summary>
                        <div class="space-y-3 border-t border-gray-100 p-4">
                            @foreach($project->scenes->sortBy('scene_number') as $scene)
                                @php($sceneLayout = data_get($layoutSettings, 'scenes.'.$scene->id, []))
                                <div class="grid grid-cols-1 gap-3 rounded-xl bg-white p-4 ring-1 ring-gray-100 lg:grid-cols-6">
                                    <div class="lg:col-span-1"><p class="font-black">مشهد {{ $scene->scene_number }}</p><p class="text-xs text-gray-500">صفحتا {{ $scene->scene_number * 2 }}–{{ $scene->scene_number * 2 + 1 }}</p></div>
                                    <textarea name="scenes[{{ $scene->id }}][text_content]" rows="4" required class="rounded-lg border-gray-200 text-right lg:col-span-3">{{ old('scenes.'.$scene->id.'.text_content', $sceneLayout['text_content'] ?? $scene->story_text) }}</textarea>
                                    <label class="text-xs font-black text-gray-600">صفحة النص
                                        <select name="scenes[{{ $scene->id }}][text_side]" class="mt-2 w-full rounded-lg border-gray-200">
                                            <option value="right" @selected(($sceneLayout['text_side'] ?? null) === 'right')>اليمنى</option>
                                            <option value="left" @selected(($sceneLayout['text_side'] ?? null) === 'left')>اليسرى</option>
                                        </select>
                                    </label>
                                    <label class="text-xs font-black text-gray-600">موضع النص
                                        <select name="scenes[{{ $scene->id }}][text_position]" class="mt-2 w-full rounded-lg border-gray-200">
                                            <option value="top" @selected(($sceneLayout['text_position'] ?? null) === 'top')>أعلى</option>
                                            <option value="center" @selected(($sceneLayout['text_position'] ?? null) === 'center')>منتصف</option>
                                            <option value="bottom" @selected(($sceneLayout['text_position'] ?? 'bottom') === 'bottom')>أسفل</option>
                                        </select>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </details>

                    <div data-layout-feedback class="hidden rounded-xl border p-4 text-sm font-black"></div>
                    <div class="flex flex-col gap-3 md:flex-row">
                        <button type="submit" data-layout-action="generate" formaction="{{ route('admin.production-studio.layout.generate', $project) }}" class="rounded-xl bg-indigo-600 px-6 py-3 font-black text-white disabled:cursor-not-allowed disabled:bg-gray-300" @disabled(!$layoutReadiness['ready'])>توليد ملفات الإخراج والطباعة</button>
                        <button type="submit" data-layout-action="save" formaction="{{ route('admin.production-studio.layout.save', $project) }}" class="rounded-xl bg-gray-900 px-6 py-3 font-black text-white">حفظ الإعدادات</button>
                        <a href="{{ route('admin.production-studio.layout.preview', $project) }}" target="_blank" class="rounded-xl bg-gray-100 px-6 py-3 text-center font-black text-gray-700">معاينة 28 صفحة</a>
                    </div>
                </form>

                <div class="rounded-xl border border-gray-100 bg-gray-50 p-4" data-layout-status-card data-layout-status-url="{{ $printLayout ? route('admin.production-studio.layout.status', [$project, $printLayout]) : '' }}">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div><p class="font-black text-gray-950">آخر إصدار</p><p class="text-sm text-gray-500" data-layout-status-label>{{ $printLayout ? 'v'.$printLayout->version_number.' — '.($layoutStatusLabels[$printLayout->status] ?? $printLayout->status) : 'لا يوجد إصدار بعد' }}</p></div>
                        @if($printLayout?->generated_at)<p class="text-xs text-gray-500">{{ $printLayout->generated_at->format('Y-m-d H:i') }}</p>@endif
                    </div>
                    <p data-layout-error class="mt-3 text-sm font-bold text-red-600">{{ $printLayout?->error_message }}</p>
                    <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-4" data-layout-downloads>
                        @if($printLayout?->isReady())
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'reader']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Reader Order PDF</a>
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'print']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Print-Ready A3 PDF</a>
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'manifest']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Print Manifest</a>
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'proof']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Proof Checklist</a>
                        @endif
                    </div>
                </div>
            </div>
        @include('admin.production-studio.partials.workflow-card-close')

        <?php
            $finalProofRun = $automationRun ?? null;
            $currentProof = $finalProofRun?->currentProof;
            $canReviewFinalProof = auth()->user()->hasPermission('production_studio.final_proof_review');
            $finalProofReviewable = $finalProofRun?->status === \App\Support\ProductionAutomation::STATUS_FILES_READY;
            $finalProofCompleted = $finalProofRun?->status === \App\Support\ProductionAutomation::STATUS_COMPLETED;
        ?>
        @if($finalProofRun && in_array($finalProofRun->status, [\App\Support\ProductionAutomation::STATUS_FILES_READY, \App\Support\ProductionAutomation::STATUS_COMPLETED, \App\Support\ProductionAutomation::STATUS_PAUSED_REVIEW], true))
            @include('admin.production-studio.partials.workflow-card-open', [
                'id' => 'final-proof',
                'title' => 'المراجعة النهائية قبل الطباعة',
                'description' => 'اعتماد بشري إلزامي بعد ملفات Phase 4.',
                'status' => $currentProof ? 'v'.$currentProof->proof_version.' — '.$currentProof->status : ($finalProofReviewable ? 'جاهز للمراجعة' : $finalProofRun->status),
                'statusTone' => $finalProofCompleted ? 'emerald' : ($currentProof?->status === 'failed' ? 'red' : 'amber'),
                'warning' => $finalProofCompleted ? null : 'لا تصل إلى 100% بدون اعتماد نهائي',
                'summary' => $finalProofCompleted ? 'جاهز للطباعة اليدوية' : 'راجع الملفات، اطبع عينة، ثم أكمل القائمة',
            ])
                <div class="space-y-4 text-right">
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">Run</p><p class="font-black">#{{ $finalProofRun->id }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">الحالة</p><p class="font-black">{{ $finalProofRun->status }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">المرحلة</p><p class="font-black">{{ $finalProofRun->current_stage }}</p></div>
                        <div class="rounded-xl bg-gray-50 p-4"><p class="text-xs text-gray-500">Proof</p><p class="font-black">{{ $currentProof ? 'v'.$currentProof->proof_version : 'غير منشأ' }}</p></div>
                    </div>

                    @if($printLayout?->isValidatedAutomationReady())
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'reader']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Reader PDF</a>
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'print']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Imposed A3 PDF</a>
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'manifest']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Manifest</a>
                            <a href="{{ route('admin.production-studio.layout.download', [$project, $printLayout, 'proof']) }}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Proof Checklist</a>
                        </div>
                    @endif

                    @if($currentProof)
                        <div class="grid grid-cols-1 gap-3 text-xs md:grid-cols-3">
                            <div class="rounded-xl bg-gray-50 p-3"><p class="font-black text-gray-600">Reader SHA-256</p><p class="break-all font-mono text-gray-800">{{ $currentProof->reader_pdf_checksum }}</p></div>
                            <div class="rounded-xl bg-gray-50 p-3"><p class="font-black text-gray-600">Imposed SHA-256</p><p class="break-all font-mono text-gray-800">{{ $currentProof->imposed_pdf_checksum }}</p></div>
                            <div class="rounded-xl bg-gray-50 p-3"><p class="font-black text-gray-600">Manifest SHA-256</p><p class="break-all font-mono text-gray-800">{{ $currentProof->manifest_checksum }}</p></div>
                        </div>
                    @endif

                    @if($canReviewFinalProof && $finalProofReviewable && !$currentProof)
                        <form method="POST" action="{{ route('admin.production-studio.automation.final-proof.draft', $project) }}">
                            @csrf
                            <button class="rounded-xl bg-indigo-600 px-5 py-3 font-black text-white">إنشاء مسودة مراجعة نهائية</button>
                        </form>
                    @endif

                    @if($canReviewFinalProof && $finalProofReviewable && $currentProof && in_array($currentProof->status, ['draft', 'in_review'], true))
                        <form method="POST" action="{{ route('admin.production-studio.automation.final-proof.approve', [$project, $currentProof]) }}" data-final-proof-form class="space-y-4">
                            @csrf
                            <input type="hidden" name="reviewed_checksums[reader_pdf]" value="{{ $currentProof->reader_pdf_checksum }}">
                            <input type="hidden" name="reviewed_checksums[imposed_pdf]" value="{{ $currentProof->imposed_pdf_checksum }}">
                            <input type="hidden" name="reviewed_checksums[manifest]" value="{{ $currentProof->manifest_checksum }}">

                            <details class="rounded-xl border border-gray-100 bg-gray-50" open>
                                <summary class="cursor-pointer p-4 font-black text-gray-900">قائمة المراجعة الإلزامية</summary>
                                <div class="grid grid-cols-1 gap-3 border-t border-gray-100 p-4 md:grid-cols-2">
                                    @foreach($finalProofChecklist as $key => $item)
                                        <label class="rounded-xl bg-white p-3 ring-1 ring-gray-100">
                                            <span class="block text-xs font-black text-gray-500">{{ $item['group'] }}</span>
                                            <span class="mt-1 block text-sm font-black text-gray-900">{{ $item['label'] }}</span>
                                            <select name="checklist[{{ $key }}][value]" required data-final-proof-check class="mt-2 w-full rounded-lg border-gray-200">
                                                <option value="">اختر</option>
                                                <option value="pass">pass</option>
                                                <option value="fail">fail</option>
                                                <option value="not_applicable">not_applicable</option>
                                            </select>
                                            <input name="checklist[{{ $key }}][reason]" class="mt-2 w-full rounded-lg border-gray-200 text-sm" placeholder="سبب عند الفشل أو عدم الانطباق">
                                        </label>
                                    @endforeach
                                </div>
                            </details>

                            <details class="rounded-xl border border-gray-100 bg-gray-50" open>
                                <summary class="cursor-pointer p-4 font-black text-gray-900">بيانات الطباعة التجريبية والرفض</summary>
                                <div class="grid grid-cols-1 gap-3 border-t border-gray-100 p-4 md:grid-cols-3">
                                    <input name="print_test_metadata[proof_print_date]" type="date" required class="rounded-lg border-gray-200">
                                    <input name="print_test_metadata[printer_name]" required class="rounded-lg border-gray-200" placeholder="Printer name">
                                    <input name="print_test_metadata[printer_model]" class="rounded-lg border-gray-200" placeholder="Printer model">
                                    <input name="print_test_metadata[paper_size]" required value="A3 landscape" class="rounded-lg border-gray-200">
                                    <input name="print_test_metadata[cover_paper_type]" class="rounded-lg border-gray-200" placeholder="Cover paper">
                                    <input name="print_test_metadata[cover_paper_gsm]" class="rounded-lg border-gray-200" placeholder="Cover GSM">
                                    <input name="print_test_metadata[inner_paper_type]" class="rounded-lg border-gray-200" placeholder="Inner paper">
                                    <input name="print_test_metadata[inner_paper_gsm]" class="rounded-lg border-gray-200" placeholder="Inner GSM">
                                    <input name="print_test_metadata[duplex_setting]" required class="rounded-lg border-gray-200" placeholder="Duplex setting">
                                    <input name="print_test_metadata[flip_edge]" required value="short_edge" class="rounded-lg border-gray-200">
                                    <input name="print_test_metadata[print_quality]" required class="rounded-lg border-gray-200" placeholder="Print quality">
                                    <input name="print_test_metadata[test_copies]" type="number" min="1" max="20" required value="1" class="rounded-lg border-gray-200">
                                    <textarea name="print_test_metadata[reviewer_notes]" rows="2" class="rounded-lg border-gray-200 md:col-span-3" placeholder="Reviewer notes"></textarea>
                                    <select name="affected_component" class="rounded-lg border-gray-200">
                                        <option value="">Affected component for rejection</option>
                                        <option value="story_text">story_text</option>
                                        <option value="cover">cover</option>
                                        <option value="specific_scene">specific_scene</option>
                                        <option value="reader_layout">reader_layout</option>
                                        <option value="imposition">imposition</option>
                                        <option value="font_or_arabic_rendering">font_or_arabic_rendering</option>
                                        <option value="image_quality">image_quality</option>
                                        <option value="color_output">color_output</option>
                                        <option value="duplex_or_binding">duplex_or_binding</option>
                                        <option value="other">other</option>
                                    </select>
                                    <input name="affected_scene_number" type="number" min="1" max="13" class="rounded-lg border-gray-200" placeholder="Scene number">
                                    <input name="failure_category" class="rounded-lg border-gray-200" placeholder="Failure category">
                                    <textarea name="decision_reason" rows="2" class="rounded-lg border-gray-200 md:col-span-3" placeholder="Approval reason"></textarea>
                                    <textarea name="reason" rows="2" class="rounded-lg border-gray-200 md:col-span-3" placeholder="Rejection reason"></textarea>
                                    <textarea name="notes" rows="2" class="rounded-lg border-gray-200 md:col-span-3" placeholder="Final notes"></textarea>
                                </div>
                            </details>

                            <div class="flex flex-col gap-3 md:flex-row">
                                <button type="submit" data-final-proof-approve disabled class="rounded-xl bg-emerald-600 px-5 py-3 font-black text-white disabled:cursor-not-allowed disabled:bg-gray-300">اعتماد نهائي وجاهز للطباعة</button>
                                <button type="submit" formaction="{{ route('admin.production-studio.automation.final-proof.reject', [$project, $currentProof]) }}" class="rounded-xl bg-red-600 px-5 py-3 font-black text-white">رفض وإرجاع للتصحيح</button>
                            </div>
                        </form>
                    @endif

                    @if($currentProof?->hasReport())
                        <a href="{{ \Illuminate\Support\Facades\URL::temporarySignedRoute('admin.production-studio.automation.proof-report', now()->addMinutes(10), [$project, $finalProofRun, $currentProof]) }}" class="inline-block rounded-xl bg-gray-900 px-5 py-3 font-black text-white">تنزيل تقرير المراجعة النهائي</a>
                    @endif

                    @if($finalProofRun->proofs->isNotEmpty())
                        <div class="space-y-2">
                            <p class="font-black text-gray-900">محاولات المراجعة السابقة</p>
                            @foreach($finalProofRun->proofs->sortByDesc('proof_version') as $proofAttempt)
                                <div class="rounded-xl bg-gray-50 p-3 text-sm">
                                    <span class="font-black">v{{ $proofAttempt->proof_version }}</span>
                                    <span class="mx-2 text-gray-400">|</span>
                                    <span>{{ $proofAttempt->status }}</span>
                                    @if($proofAttempt->reviewed_at)<span class="mx-2 text-gray-400">|</span><span>{{ $proofAttempt->reviewed_at->format('Y-m-d H:i') }}</span>@endif
                                    @if($proofAttempt->affected_component)<span class="mx-2 text-gray-400">|</span><span>{{ $proofAttempt->affected_component }}</span>@endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @include('admin.production-studio.partials.workflow-card-close')
        @endif

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
            const scene = job.scene_number
                ? ` - المشهد ${job.scene_number}: ${job.scene_title || 'بدون عنوان'}`
                : '';
            const version = job.asset_version ? ` - v${job.asset_version}` : '';
            return `#${job.id} - ${job.job_type}${scene}${version} - ${job.status} - $${cost}`;
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

        function appendSceneAssetPreview(job) {
            if (!job || job.job_type !== 'scene_image' || !job.production_scene_id || !job.asset_url) return;

            const row = document.querySelector(`[data-scene-row="${job.production_scene_id}"]`);
            const section = row?.querySelector('[data-scene-assets]');
            const grid = row?.querySelector('[data-scene-assets-grid]');

            if (!row || !section || !grid || grid.querySelector(`[data-studio-inline-asset="${job.asset_id}"]`)) {
                return;
            }

            section.classList.remove('hidden');
            row.dataset.filterHasImage = '1';
            row.dataset.filterNeedsReview = '1';

            const card = document.createElement('div');
            card.className = 'rounded-xl border border-indigo-100 bg-white p-3 text-right shadow-sm';
            card.dataset.studioInlineAsset = job.asset_id || '';
            card.innerHTML = `
                <a href="${job.asset_url}" target="_blank" class="block overflow-hidden rounded-lg bg-gray-100">
                    <img src="${job.asset_url}" alt="${job.asset_label || 'Generated scene image'}" class="aspect-square w-full object-cover">
                </a>
                <div class="mt-3 space-y-2">
                    <p class="font-black text-gray-900">المشهد ${job.scene_number || ''} — ${job.scene_title || 'بدون عنوان'}</p>
                    <p class="text-xs font-bold text-gray-500">صورة المشهد — الإصدار v${job.asset_version || 1}</p>
                    <p class="text-xs font-bold text-indigo-700">بانتظار المراجعة. حدّث الصفحة إذا كنت تريد أزرار الاعتماد والرفض هنا.</p>
                </div>
            `;
            grid.prepend(card);
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
                    appendSceneAssetPreview(job);
                    studioFeedback(feedback, 'success', job.job_type === 'scene_image'
                        ? 'اكتملت المهمة وظهرت الصورة داخل بطاقة المشهد. حدّث الصفحة إذا كنت تريد أزرار الاعتماد والرفض.'
                        : 'اكتملت المهمة. يمكنك مراجعة المخرج في سجل التوليد أو تحديث الصفحة لعرض الصورة الجديدة.');
                    return;
                }

                if (job.status === 'failed') {
                    studioFeedback(feedback, 'error', job.error_message || 'فشلت مهمة التوليد.');
                    return;
                }

                studioFeedback(feedback, 'info', `حالة المهمة #${job.id}: ${job.status}. إذا بقيت Queued تأكد من تشغيل Queue Worker على السيرفر.`);
            }
        }

        async function pollBulkStudioJobs(statusUrl, feedback) {
            if (!statusUrl) return;

            for (let attempt = 0; attempt < 180; attempt += 1) {
                await new Promise(resolve => setTimeout(resolve, attempt === 0 ? 1200 : 10000));

                try {
                    const response = await fetch(statusUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    if (!response.ok) {
                        studioFeedback(feedback, 'error', 'تعذر قراءة حالة مهام التوليد الجماعي. المهام محفوظة ويمكن متابعة حالتها بعد تحديث الصفحة.');
                        return;
                    }

                    const payload = await response.json();
                    const jobs = payload.jobs || [];
                    jobs.forEach(job => {
                        upsertStudioJob(job, '');
                        if (job.status === 'completed') appendSceneAssetPreview(job);
                    });

                    const completed = jobs.filter(job => job.status === 'completed').length;
                    const failed = jobs.filter(job => job.status === 'failed').length;
                    const pending = jobs.length - completed - failed;

                    if (pending === 0) {
                        studioFeedback(feedback, failed > 0 ? 'error' : 'success',
                            `انتهى التوليد الجماعي: ${completed} مكتملة، ${failed} فاشلة. الصور ظهرت داخل بطاقات المشاهد.`);
                        return;
                    }

                    studioFeedback(feedback, 'info',
                        `التوليد الجماعي مستمر: ${completed} مكتملة، ${failed} فاشلة، ${pending} في الانتظار أو المعالجة.`);
                } catch (error) {
                    studioFeedback(feedback, 'error', 'انقطع الاتصال أثناء متابعة المهام. المهام مستمرة داخل Queue ويمكن مراجعتها بعد تحديث الصفحة.');
                    return;
                }
            }

            studioFeedback(feedback, 'info', 'المهام ما زالت محفوظة داخل Queue. يمكنك تحديث الصفحة لاحقًا لمراجعة النتائج.');
        }

        function updateBulkCostSummary(form) {
            if (!form) return;

            const model = form.querySelector('[data-bulk-model]')?.selectedOptions[0];
            const quality = form.querySelector('[data-bulk-quality]')?.value || 'medium';
            const count = Number(form.dataset.sceneCount || 0);
            const unitCost = Number(model?.dataset[quality === 'high' ? 'costHigh' : 'costMedium'] || 0);
            const summary = form.querySelector('[data-bulk-cost-summary]');
            if (summary) {
                summary.textContent = `${count} مشهد × $${unitCost.toFixed(4)} = تكلفة إجمالية تقديرية $${(count * unitCost).toFixed(4)}. ستنشأ نسخة واحدة لكل مشهد.`;
            }
        }

        function renderLayoutStatus(layout) {
            const card = document.querySelector('[data-layout-status-card]');
            if (!card || !layout) return;

            const labels = { draft: 'مسودة', queued: 'في قائمة الانتظار', processing: 'جارٍ التوليد', ready: 'جاهز', failed: 'فشل' };
            card.dataset.layoutStatusUrl = card.dataset.layoutStatusUrl || '';
            card.querySelector('[data-layout-status-label]').textContent = `v${layout.version} — ${labels[layout.status] || layout.status}`;
            card.querySelector('[data-layout-error]').textContent = layout.error_message || '';

            const downloads = card.querySelector('[data-layout-downloads]');
            if (downloads && layout.downloads && Object.keys(layout.downloads).length) {
                downloads.innerHTML = `
                    <a href="${layout.downloads.reader}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Reader Order PDF</a>
                    <a href="${layout.downloads.print}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Print-Ready A3 PDF</a>
                    <a href="${layout.downloads.manifest}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Print Manifest</a>
                    <a href="${layout.downloads.proof}" class="rounded-lg bg-white px-4 py-3 text-center font-black text-indigo-700 ring-1 ring-indigo-100">Proof Checklist</a>
                `;
            }
        }

        async function pollLayout(statusUrl, feedback) {
            for (let attempt = 0; attempt < 30; attempt += 1) {
                await new Promise(resolve => setTimeout(resolve, attempt === 0 ? 1200 : 5000));
                const response = await fetch(statusUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                if (!response.ok) {
                    studioFeedback(feedback, 'error', 'تعذر قراءة حالة الإخراج.');
                    return;
                }
                const payload = await response.json();
                renderLayoutStatus(payload.layout);

                if (payload.layout.status === 'ready') {
                    studioFeedback(feedback, 'success', 'اكتملت ملفات الإخراج. روابط التنزيل جاهزة أسفل البطاقة.');
                    return;
                }
                if (payload.layout.status === 'failed') {
                    studioFeedback(feedback, 'error', payload.layout.error_message || 'فشل توليد ملفات الإخراج.');
                    return;
                }
                studioFeedback(feedback, 'info', `حالة الإخراج: ${payload.layout.status}. تأكد من تشغيل Queue Worker إذا بقيت المهمة في الانتظار.`);
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const root = document.querySelector('[data-studio-project]');
            if (!root) return;

            const jobDrawer = document.querySelector('[data-studio-job-drawer]');
            const jobDrawerOverlay = document.querySelector('[data-studio-job-drawer-overlay]');
            const jobDrawerPanel = document.getElementById('production-job-log-drawer');
            const jobDrawerRefresh = document.querySelector('[data-studio-job-log-refresh]');
            const jobDrawerStatus = document.querySelector('[data-studio-job-log-status]');
            let jobDrawerReturnFocus = null;

            function setJobDrawerOpen(open, trigger = null) {
                if (!jobDrawer || !jobDrawerPanel || !jobDrawerOverlay) return;

                if (open) {
                    jobDrawerReturnFocus = trigger || document.activeElement;
                }

                jobDrawer.classList.toggle('pointer-events-none', !open);
                jobDrawerOverlay.classList.toggle('opacity-0', !open);
                jobDrawerOverlay.classList.toggle('opacity-100', open);
                jobDrawerPanel.classList.toggle('-translate-x-full', !open);
                jobDrawerPanel.classList.toggle('translate-x-0', open);
                jobDrawerPanel.setAttribute('aria-hidden', open ? 'false' : 'true');
                document.querySelectorAll('[data-studio-job-drawer-open]').forEach(button => {
                    button.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                document.body.classList.toggle('overflow-hidden', open);

                if (open) {
                    window.setTimeout(() => document.querySelector('[data-studio-job-drawer-close]')?.focus(), 180);
                } else if (jobDrawerReturnFocus instanceof HTMLElement) {
                    jobDrawerReturnFocus.focus();
                }
            }

            async function refreshJobLog() {
                if (!jobDrawer || !jobDrawerRefresh) return;

                const url = jobDrawer.dataset.jobLogUrl;
                const list = jobDrawer.querySelector('[data-studio-job-list]');
                const originalText = jobDrawerRefresh.querySelector('[data-refresh-label]')?.textContent || 'تحديث';
                jobDrawerRefresh.disabled = true;
                jobDrawerRefresh.querySelector('[data-refresh-icon]')?.classList.add('animate-spin');
                if (jobDrawerRefresh.querySelector('[data-refresh-label]')) {
                    jobDrawerRefresh.querySelector('[data-refresh-label]').textContent = 'جارٍ التحديث';
                }
                if (jobDrawerStatus) jobDrawerStatus.textContent = 'جارٍ تحميل أحدث مهام التوليد...';

                try {
                    const response = await fetch(url, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.ok === false) {
                        throw new Error(payload.message || 'تعذر تحديث سجل التوليد.');
                    }

                    if (list) list.innerHTML = payload.html || '';
                    jobDrawer.dataset.loaded = '1';
                    document.querySelectorAll('[data-studio-active-job-count]').forEach(badge => {
                        badge.textContent = payload.active_count ?? 0;
                    });
                    if (jobDrawerStatus) {
                        jobDrawerStatus.textContent = `آخر تحديث: ${payload.refreshed_at} · عرض ${payload.count} مهمة`;
                    }
                } catch (error) {
                    if (jobDrawerStatus) jobDrawerStatus.textContent = error.message || 'تعذر تحديث سجل التوليد.';
                } finally {
                    jobDrawerRefresh.disabled = false;
                    jobDrawerRefresh.querySelector('[data-refresh-icon]')?.classList.remove('animate-spin');
                    if (jobDrawerRefresh.querySelector('[data-refresh-label]')) {
                        jobDrawerRefresh.querySelector('[data-refresh-label]').textContent = originalText;
                    }
                }
            }

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

            document.querySelectorAll('[data-studio-bulk-ai-form]').forEach(updateBulkCostSummary);

            const finalProofForm = document.querySelector('[data-final-proof-form]');
            if (finalProofForm) {
                const approveButton = finalProofForm.querySelector('[data-final-proof-approve]');
                const checks = Array.from(finalProofForm.querySelectorAll('[data-final-proof-check]'));
                const syncFinalProofApproval = () => {
                    if (!approveButton) return;
                    approveButton.disabled = checks.length === 0 || checks.some(select => select.value !== 'pass');
                };

                checks.forEach(select => select.addEventListener('change', syncFinalProofApproval));
                syncFinalProofApproval();
            }

            const automationPanel = document.querySelector('[data-automation-panel]');
            if (automationPanel) {
                const automationFeedback = automationPanel.querySelector('[data-automation-feedback]');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
                const automationPhaseDefinitions = [
                    { key: 'preflight', label: 'الفحص المسبق', steps: ['preflight'] },
                    { key: 'story', label: 'تحضير القصة', steps: ['story_preparation'] },
                    { key: 'profile', label: 'ملف الشخصية', steps: ['character_profile'] },
                    { key: 'reference', label: 'مرجع الطفل', steps: ['child_reference'] },
                    { key: 'cover', label: 'الغلاف', steps: ['cover'] },
                    { key: 'scenes', label: 'المشاهد 13', prefix: 'scene_' },
                    { key: 'layout', label: 'الإخراج والطباعة', steps: ['layout_print'] },
                    { key: 'proof', label: 'المراجعة النهائية', steps: ['final_proof'] },
                ];
                const automationStepStatusLabels = {
                    pending: 'لم يبدأ',
                    queued: 'في الانتظار',
                    running: 'قيد التنفيذ',
                    waiting_review: 'بانتظار مراجعة',
                    completed: 'مكتمل',
                    skipped: 'متجاوز',
                    failed_recoverable: 'تعثر قابل للاستئناف',
                    provider_failed: 'فشل مزود',
                    failed: 'فشل',
                    cancelled: 'ملغي',
                };

                function automationPayload() {
                    const form = automationPanel.querySelector('[data-automation-start-form]');
                    if (!form) return {};

                    return Object.fromEntries(new FormData(form).entries());
                }

                function automationBlockerText(payload) {
                    const blockers = payload.preflight?.blockers || payload.automation?.blockers || [];
                    if (Array.isArray(blockers) && blockers.length) {
                        return blockers.map(blocker => typeof blocker === 'string' ? blocker : (blocker.summary || blocker.code || JSON.stringify(blocker))).join(' | ');
                    }

                    return payload.message || 'تم تنفيذ الطلب.';
                }

                function automationEscape(value) {
                    return String(value ?? '').replace(/[&<>"']/g, char => ({
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;',
                    }[char]));
                }

                function automationPhaseTone(status) {
                    return {
                        completed: 'border-emerald-200 bg-emerald-50 text-emerald-900',
                        running: 'border-indigo-200 bg-indigo-50 text-indigo-900',
                        review: 'border-amber-200 bg-amber-50 text-amber-900',
                        failed: 'border-red-200 bg-red-50 text-red-900',
                        cancelled: 'border-gray-300 bg-gray-100 text-gray-700',
                        partial: 'border-blue-200 bg-blue-50 text-blue-900',
                        pending: 'border-gray-100 bg-gray-50 text-gray-600',
                    }[status] || 'border-gray-100 bg-gray-50 text-gray-600';
                }

                function automationPhaseSummary(steps) {
                    const total = steps.length;
                    if (total === 0) return { status: 'pending', label: 'غير متاح', detail: '0/0' };

                    const completed = steps.filter(step => ['completed', 'skipped'].includes(step.status)).length;
                    const active = steps.filter(step => ['queued', 'running'].includes(step.status)).length;
                    const review = steps.filter(step => step.status === 'waiting_review').length;
                    const failed = steps.filter(step => ['failed_recoverable', 'provider_failed', 'failed'].includes(step.status)).length;
                    const cancelled = steps.filter(step => step.status === 'cancelled').length;
                    const latestIssue = steps.find(step => step.safe_failure_summary || step.safe_failure_code);

                    if (completed === total) {
                        return { status: 'completed', label: 'مكتمل', detail: `${completed}/${total}` };
                    }

                    if (failed > 0) {
                        return { status: 'failed', label: 'يحتاج تدخل', detail: `${completed}/${total}`, issue: latestIssue?.safe_failure_summary || latestIssue?.safe_failure_code };
                    }

                    if (review > 0) {
                        return { status: 'review', label: 'بانتظار مراجعة', detail: `${completed}/${total}`, issue: latestIssue?.safe_failure_summary || latestIssue?.safe_failure_code };
                    }

                    if (active > 0) {
                        return { status: 'running', label: 'قيد التنفيذ', detail: `${completed}/${total}` };
                    }

                    if (cancelled > 0) {
                        return { status: 'cancelled', label: 'ملغي', detail: `${completed}/${total}` };
                    }

                    if (completed > 0) {
                        return { status: 'partial', label: 'جزئي', detail: `${completed}/${total}` };
                    }

                    return { status: 'pending', label: 'لم يبدأ', detail: `${completed}/${total}` };
                }

                function automationBlockers(automation) {
                    const blockers = automation?.blockers || [];
                    if (!Array.isArray(blockers)) return [];

                    return blockers.map(blocker => typeof blocker === 'string' ? blocker : (blocker.summary || blocker.code || '')).filter(Boolean);
                }

                function renderAutomationLifecycle(automation) {
                    const run = automation?.run;
                    const progress = Number(run?.progress || 0);
                    const progressLabel = automationPanel.querySelector('[data-automation-progress-label]');
                    const progressBar = automationPanel.querySelector('[data-automation-progress-bar]');
                    const currentStage = automationPanel.querySelector('[data-automation-current-stage]');
                    const blockerBox = automationPanel.querySelector('[data-automation-blockers]');
                    const phaseGrid = automationPanel.querySelector('[data-automation-phase-grid]');

                    if (progressLabel) progressLabel.textContent = `${progress}%`;
                    if (progressBar) progressBar.style.width = `${Math.max(0, Math.min(100, progress))}%`;
                    if (currentStage) {
                        currentStage.textContent = run
                            ? `المرحلة الحالية: ${run.current_stage || '-'} | الخطوة: ${run.current_step_key || '-'} | الحالة: ${run.status || '-'}`
                            : 'لم تبدأ دورة بعد';
                    }

                    const blockers = automationBlockers(automation);
                    if (blockerBox) {
                        blockerBox.classList.toggle('hidden', blockers.length === 0);
                        blockerBox.textContent = blockers.length ? `العوائق الحالية: ${blockers.join(' | ')}` : '';
                    }

                    if (!phaseGrid) return;

                    const steps = Array.isArray(automation?.steps) ? automation.steps : [];
                    if (!run || steps.length === 0) {
                        phaseGrid.innerHTML = '<div class="rounded-xl border border-gray-100 bg-gray-50 p-4 text-sm font-bold text-gray-500 lg:col-span-4">لا توجد دورة إنتاج تلقائي بعد. ابدأ بالفحص قبل التشغيل.</div>';
                        return;
                    }

                    phaseGrid.innerHTML = automationPhaseDefinitions.map(phase => {
                        const phaseSteps = phase.prefix
                            ? steps.filter(step => String(step.key || '').startsWith(phase.prefix))
                            : steps.filter(step => phase.steps.includes(step.key));
                        const summary = automationPhaseSummary(phaseSteps);
                        const activeMarker = phaseSteps.some(step => step.key === run.current_step_key) ? 'ring-2 ring-indigo-300' : '';
                        const statusList = phaseSteps.slice(0, 4).map(step => automationStepStatusLabels[step.status] || step.status).join('، ');
                        const extra = phaseSteps.length > 4 ? ` +${phaseSteps.length - 4}` : '';

                        return `
                            <div class="rounded-xl border p-4 ${automationPhaseTone(summary.status)} ${activeMarker}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <p class="font-black">${phase.label}</p>
                                        <p class="mt-1 text-xs font-bold opacity-80">${statusList}${extra}</p>
                                    </div>
                                    <span class="rounded-full bg-white/70 px-2 py-1 text-xs font-black">${summary.detail}</span>
                                </div>
                                <p class="mt-3 text-sm font-black">${summary.label}</p>
                                ${summary.issue ? `<p class="mt-2 text-xs font-bold leading-5">${summary.issue}</p>` : ''}
                            </div>
                        `;
                    }).join('');
                }

                function renderAutomationReviewActions(automation) {
                    const reviewBox = automationPanel.querySelector('[data-automation-review-actions]');
                    if (!reviewBox) return;

                    const run = automation?.run || {};
                    const story = automation?.phase2?.story_preparation || {};
                    const actions = story.available_actions || {};
                    const isStoryReview = (run.status === 'paused_review' && run.current_step_key === 'story_preparation')
                        || story.status === 'waiting_review';

                    if (!isStoryReview) {
                        reviewBox.classList.add('hidden');
                        reviewBox.innerHTML = '';
                        return;
                    }

                    const code = story.safe_failure_code || run.safe_failure_code || 'story_preparation_review';
                    const summary = story.safe_failure_summary || run.safe_failure_summary || 'تحضير القصة يحتاج مراجعة بشرية قبل متابعة الإنتاج.';
                    const sceneCount = story.scene_count ?? 0;
                    const versionLabel = story.story_version_number ? `مسودة #${story.story_version_number}` : (story.story_version_id ? `مسودة #${story.story_version_id}` : 'لا توجد مسودة معتمدة بعد');
                    const validationMessages = [];
                    const validation = story.validation || {};

                    if (Array.isArray(validation.errors)) {
                        validationMessages.push(...validation.errors.slice(0, 4));
                    }
                    if (Array.isArray(validation.blocking_flags)) {
                        validationMessages.push(...validation.blocking_flags.slice(0, 4));
                    }

                    reviewBox.classList.remove('hidden');
                    reviewBox.innerHTML = `
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <p class="text-sm font-black text-amber-950">مراجعة تحضير القصة مطلوبة</p>
                                <p class="mt-2 text-sm font-bold leading-7 text-amber-900">${automationEscape(summary)}</p>
                                <div class="mt-3 flex flex-wrap gap-2 text-xs font-black text-amber-900">
                                    <span class="rounded-full bg-white px-3 py-1 ring-1 ring-amber-200" dir="ltr">${automationEscape(code)}</span>
                                    <span class="rounded-full bg-white px-3 py-1 ring-1 ring-amber-200">المشاهد الحالية: ${automationEscape(sceneCount)}/13</span>
                                    <span class="rounded-full bg-white px-3 py-1 ring-1 ring-amber-200">${automationEscape(versionLabel)}</span>
                                </div>
                                ${validationMessages.length ? `
                                    <ul class="mt-3 list-inside list-disc space-y-1 text-xs font-bold leading-6 text-amber-900">
                                        ${validationMessages.map(message => `<li>${automationEscape(message)}</li>`).join('')}
                                    </ul>
                                ` : ''}
                                <p class="mt-3 text-xs font-bold leading-6 text-amber-800">
                                    الاعتماد اليدوي يتطلب وجود 13 مشهدًا مكتملة الحقول في مساحة القصة. لا تضغط استئناف فقط إذا كان سبب الوقف يحتاج تصحيحًا.
                                </p>
                            </div>
                            <div class="flex shrink-0 flex-col gap-2 sm:flex-row lg:flex-col">
                                <button type="button" data-studio-open-section="story-workspace" class="rounded-xl bg-white px-4 py-2.5 text-sm font-black text-amber-900 ring-1 ring-amber-200 hover:bg-amber-100">
                                    افتح مساحة القصة
                                </button>
                                ${actions.manual_review ? `
                                    <button type="button" data-automation-approve-story class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-black text-white hover:bg-emerald-700">
                                        اعتماد تحضير القصة يدويًا
                                    </button>
                                ` : ''}
                                ${actions.retry ? `
                                    <button type="button" data-automation-retry-story class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-black text-white hover:bg-indigo-700">
                                        إعادة محاولة تحضير القصة
                                    </button>
                                ` : ''}
                            </div>
                        </div>
                    `;
                }

                function updateAutomationSummary(automation) {
                    if (!automation?.run) return;

                    const run = automation.run;
                    const status = automationPanel.querySelector('[data-automation-run-status]');
                    const stage = automationPanel.querySelector('[data-automation-run-stage]');
                    const step = automationPanel.querySelector('[data-automation-run-step]');
                    const budget = automationPanel.querySelector('[data-automation-run-budget]');

                    if (status) status.textContent = run.status || '-';
                    if (stage) stage.textContent = run.current_stage || '-';
                    if (step) step.textContent = run.current_step_key || '-';
                    if (budget && automation.costs?.hard_budget) budget.textContent = `$${automation.costs.hard_budget}`;
                    renderAutomationLifecycle(automation);
                    renderAutomationReviewActions(automation);
                }

                async function automationRequest(url, options = {}) {
                    const response = await fetch(url, {
                        method: options.method || 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: options.body === undefined ? JSON.stringify(automationPayload()) : JSON.stringify(options.body),
                        cache: 'no-store',
                    });
                    const payload = await response.json().catch(() => ({}));

                    if (!response.ok || payload.ok === false) {
                        throw new Error(automationBlockerText(payload) || 'Automation request failed.');
                    }

                    return payload;
                }

                async function refreshAutomationStatus(showFeedback = false) {
                    if (showFeedback) {
                        studioFeedback(automationFeedback, 'info', 'جارٍ تحديث حالة الإنتاج التلقائي...');
                    }

                    const response = await fetch(automationPanel.dataset.statusUrl, {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        cache: 'no-store',
                    });
                    const payload = await response.json();

                    if (!response.ok || payload.ok === false) {
                        throw new Error(payload.message || 'تعذر تحديث حالة الإنتاج التلقائي.');
                    }

                    updateAutomationSummary(payload.automation);
                    if (showFeedback) {
                        studioFeedback(automationFeedback, 'success', payload.automation?.run ? `الحالة الحالية: ${payload.automation.run.status}` : 'لا توجد دورة إنتاج تلقائي لهذا المشروع.');
                    }

                    return payload.automation;
                }

                automationPanel.querySelector('[data-automation-preflight]')?.addEventListener('click', async function () {
                    studioFeedback(automationFeedback, 'info', 'جارٍ تنفيذ الفحص قبل التشغيل...');
                    try {
                        const payload = await automationRequest(automationPanel.dataset.preflightUrl);
                        const warnings = payload.preflight?.warnings?.length ? ` تحذيرات: ${payload.preflight.warnings.join(' | ')}` : '';
                        const estimate = payload.preflight?.base_estimated_cost ? ` التكلفة الأساسية: $${payload.preflight.base_estimated_cost}.` : '';
                        studioFeedback(automationFeedback, payload.preflight?.ok ? 'success' : 'error', `${automationBlockerText(payload)}.${estimate}${warnings}`);
                    } catch (error) {
                        studioFeedback(automationFeedback, 'error', error.message || 'فشل الفحص قبل التشغيل.');
                    }
                });

                automationPanel.querySelector('[data-automation-start]')?.addEventListener('click', async function () {
                    if (!window.confirm('بدء الإنتاج التلقائي سيستخدم الميزانية المحددة وقد يرسل طلبات مزود مدفوعة. هل تريد المتابعة؟')) {
                        return;
                    }

                    studioFeedback(automationFeedback, 'info', 'جارٍ بدء الإنتاج التلقائي...');
                    try {
                        const payload = await automationRequest(automationPanel.dataset.startUrl);
                        updateAutomationSummary(payload.automation);
                        studioFeedback(automationFeedback, 'success', payload.message || 'تم إنشاء دورة الإنتاج التلقائي. سيظهر التقدم هنا تلقائيًا.');
                        refreshAutomationStatus(false).catch(() => {});
                    } catch (error) {
                        studioFeedback(automationFeedback, 'error', error.message || 'تعذر بدء الإنتاج التلقائي.');
                    }
                });

                automationPanel.querySelector('[data-automation-status]')?.addEventListener('click', async function () {
                    try {
                        await refreshAutomationStatus(true);
                    } catch (error) {
                        studioFeedback(automationFeedback, 'error', 'تعذر تحديث حالة الإنتاج التلقائي.');
                    }
                });

                automationPanel.querySelector('[data-automation-pause]')?.addEventListener('click', async function () {
                    studioFeedback(automationFeedback, 'info', 'جارٍ الإيقاف المؤقت...');
                    try {
                        const payload = await automationRequest(automationPanel.dataset.pauseUrl, { body: { reason: 'manual_pause_from_studio' } });
                        updateAutomationSummary(payload.automation);
                        studioFeedback(automationFeedback, 'success', 'تم إيقاف الإنتاج التلقائي مؤقتًا.');
                    } catch (error) {
                        studioFeedback(automationFeedback, 'error', error.message || 'تعذر الإيقاف المؤقت.');
                    }
                });

                automationPanel.querySelector('[data-automation-resume]')?.addEventListener('click', async function () {
                    studioFeedback(automationFeedback, 'info', 'جارٍ الاستئناف...');
                    try {
                        const payload = await automationRequest(automationPanel.dataset.resumeUrl, { body: {} });
                        updateAutomationSummary(payload.automation);
                        studioFeedback(automationFeedback, 'success', 'تم استئناف الإنتاج التلقائي.');
                    } catch (error) {
                        studioFeedback(automationFeedback, 'error', error.message || 'تعذر الاستئناف.');
                    }
                });

                automationPanel.querySelector('[data-automation-cancel]')?.addEventListener('click', async function () {
                    const reason = automationPanel.querySelector('[data-automation-cancel-reason]')?.value || 'manual_cancel';
                    if (!window.confirm('إلغاء دورة الإنتاج التلقائي لا يحذف الأصول التاريخية، لكنه يوقف الدورة الحالية. هل تريد الإلغاء؟')) {
                        return;
                    }

                    studioFeedback(automationFeedback, 'info', 'جارٍ إلغاء الدورة...');
                    try {
                        const payload = await automationRequest(automationPanel.dataset.cancelUrl, { body: { reason } });
                        updateAutomationSummary(payload.automation);
                        studioFeedback(automationFeedback, 'success', 'تم إلغاء دورة الإنتاج التلقائي.');
                    } catch (error) {
                        studioFeedback(automationFeedback, 'error', error.message || 'تعذر الإلغاء.');
                    }
                });

                automationPanel.addEventListener('click', async function (event) {
                    const approveStoryButton = event.target.closest('[data-automation-approve-story]');
                    const retryStoryButton = event.target.closest('[data-automation-retry-story]');

                    if (approveStoryButton) {
                        const reason = window.prompt('سبب اعتماد تحضير القصة يدويًا بعد المراجعة', 'manual_story_review_after_correction');
                        if (!reason) return;

                        approveStoryButton.disabled = true;
                        studioFeedback(automationFeedback, 'info', 'جارٍ اعتماد تحضير القصة يدويًا...');
                        try {
                            const payload = await automationRequest(automationPanel.dataset.storyApproveUrl, { body: { reason } });
                            updateAutomationSummary(payload.automation);
                            studioFeedback(automationFeedback, 'success', 'تم اعتماد تحضير القصة، وسيكمل الإنتاج من الخطوة الآمنة التالية.');
                            refreshAutomationStatus(false).catch(() => {});
                        } catch (error) {
                            studioFeedback(automationFeedback, 'error', error.message || 'تعذر اعتماد تحضير القصة.');
                        } finally {
                            approveStoryButton.disabled = false;
                        }
                    }

                    if (retryStoryButton) {
                        if (!window.confirm('إعادة محاولة تحضير القصة قد تستخدم تكلفة إضافية حسب المزود. هل تريد المتابعة؟')) {
                            return;
                        }

                        retryStoryButton.disabled = true;
                        studioFeedback(automationFeedback, 'info', 'جارٍ إعادة محاولة تحضير القصة...');
                        try {
                            const payload = await automationRequest(automationPanel.dataset.retryStepUrl, {
                                body: {
                                    step_key: 'story_preparation',
                                    confirm_additional_budget_exposure: true,
                                    reason: 'retry_story_preparation_after_review',
                                },
                            });
                            updateAutomationSummary(payload.automation);
                            studioFeedback(automationFeedback, 'success', 'تمت جدولة إعادة محاولة تحضير القصة.');
                            refreshAutomationStatus(false).catch(() => {});
                        } catch (error) {
                            studioFeedback(automationFeedback, 'error', error.message || 'تعذر إعادة محاولة تحضير القصة.');
                        } finally {
                            retryStoryButton.disabled = false;
                        }
                    }
                });

                refreshAutomationStatus(false).catch(() => {});
                window.setInterval(() => {
                    refreshAutomationStatus(false).catch(() => {});
                }, 10000);
            }

            document.addEventListener('click', function (event) {
                const jobDrawerOpener = event.target.closest('[data-studio-job-drawer-open]');
                if (jobDrawerOpener) {
                    setJobDrawerOpen(true, jobDrawerOpener);
                    if (jobDrawer?.dataset.loaded !== '1') refreshJobLog();
                    return;
                }

                if (event.target.closest('[data-studio-job-drawer-close], [data-studio-job-drawer-overlay]')) {
                    setJobDrawerOpen(false);
                    return;
                }

                if (event.target.closest('[data-studio-job-log-refresh]')) {
                    refreshJobLog();
                    return;
                }

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

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && jobDrawerPanel?.getAttribute('aria-hidden') === 'false') {
                    setJobDrawerOpen(false);
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
            const bulkControl = event.target.closest('[data-bulk-model], [data-bulk-quality]');
            if (bulkControl) {
                updateBulkCostSummary(bulkControl.closest('[data-studio-bulk-ai-form]'));
                return;
            }

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
            const layoutForm = event.target.closest('[data-studio-layout-form]');
            if (layoutForm) {
                event.preventDefault();
                const button = event.submitter;
                const feedback = layoutForm.querySelector('[data-layout-feedback]');
                const originalText = button?.textContent;
                const action = button?.getAttribute('formaction') || layoutForm.action;

                if (button) {
                    button.disabled = true;
                    button.textContent = 'جارٍ التنفيذ...';
                }
                studioFeedback(feedback, 'info', 'جارٍ حفظ إعدادات الإخراج...');

                try {
                    const response = await fetch(action, {
                        method: 'POST',
                        body: new FormData(layoutForm),
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || payload.ok === false) {
                        const validationMessage = payload.errors ? Object.values(payload.errors).flat().join(' ') : null;
                        studioFeedback(feedback, 'error', validationMessage || payload.message || 'تعذر تنفيذ الإخراج.');
                        return;
                    }

                    renderLayoutStatus(payload.layout);
                    studioFeedback(feedback, 'success', payload.message || 'تم حفظ إعدادات الإخراج.');
                    if (payload.status_url) pollLayout(payload.status_url, feedback);
                } catch (error) {
                    studioFeedback(feedback, 'error', 'حدث خطأ في الاتصال أثناء حفظ الإخراج.');
                } finally {
                    if (button) {
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                }
                return;
            }

            const form = event.target.closest('[data-studio-ai-form]');
            if (!form) return;

            event.preventDefault();

            if (form.matches('[data-studio-bulk-ai-form]')) {
                const model = form.querySelector('[data-bulk-model]')?.selectedOptions[0];
                const quality = form.querySelector('[data-bulk-quality]')?.value || 'medium';
                const count = Number(form.dataset.sceneCount || 0);
                const unitCost = Number(model?.dataset[quality === 'high' ? 'costHigh' : 'costMedium'] || 0);
                const totalCost = count * unitCost;
                if (!window.confirm(`سيتم إنشاء ${count} مهمة توليد مستقلة بتكلفة إجمالية تقديرية $${totalCost.toFixed(4)}. هل تريد المتابعة؟`)) {
                    return;
                }
            }

            if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
                return;
            }

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

                if (Array.isArray(payload.jobs)) {
                    payload.jobs.forEach(job => upsertStudioJob(job, ''));
                    if (payload.jobs.length > 0) {
                        studioFeedback(feedback, 'success', `${payload.message} التكلفة الإجمالية التقديرية: $${payload.estimated_total_cost}.`);
                        pollBulkStudioJobs(payload.status_url, feedback);
                    }
                }

                if (payload.asset) {
                    const card = form.closest('[data-studio-asset-card]');
                    const status = card?.querySelector('.text-xs.text-gray-500');
                    if (status) {
                        status.textContent = status.textContent.replace(/ - .+$/, ` - ${payload.asset.status}`);
                    }
                }

                if (payload.deleted_asset_id) {
                    document.querySelectorAll(`[data-studio-asset-card="${payload.deleted_asset_id}"]`).forEach((card) => {
                        const notice = document.createElement('div');
                        notice.className = 'rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-center text-sm font-black text-emerald-800';
                        notice.textContent = payload.message || 'تم حذف الصورة المولدة نهائيًا.';
                        card.replaceWith(notice);
                        window.setTimeout(() => notice.remove(), 1500);
                    });
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
