<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ProductionStudio\ApproveGeneratedAssetAction;
use App\Actions\ProductionStudio\CreateGenerationJobAction;
use App\Actions\ProductionStudio\RejectGeneratedAssetAction;
use App\DTOs\Ai\StructuredAiResult;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessStructuredAiJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\ProductionQaCheck;
use App\Models\ProductionScene;
use App\Models\ProductionStoryVersion;
use App\Models\SceneGenerationJob;
use App\Models\Story;
use App\Models\User;
use App\Services\Ai\AiProviderAvailability;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderManager;
use App\Support\AdminActivityLogger;
use App\Support\Ai\SupportedProviderRegistry;
use App\Support\ProductionStudio;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProductionStudioController extends Controller
{
    public function index(Request $request)
    {
        $this->ensureEnabled();

        $projects = ProductionProject::query()
            ->with(['order.story', 'order.user', 'assignedTo'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->status))
            ->when($request->filled('current_stage'), fn ($query) => $query->where('current_stage', $request->current_stage))
            ->when($request->filled('assigned_to_user_id'), fn ($query) => $query->where('assigned_to_user_id', $request->assigned_to_user_id))
            ->when($request->filled('story_id'), fn ($query) => $query->whereHas('order', fn ($orders) => $orders->where('story_id', $request->story_id)))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('created_at', '<=', $request->date_to))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim((string) $request->search);

                $query->whereHas('order', function ($orders) use ($search) {
                    $orders->where('order_number', 'like', "%{$search}%")
                        ->orWhere('parent_name', 'like', "%{$search}%")
                        ->orWhere('child_name', 'like', "%{$search}%");
                });
            })
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.production-studio.index', [
            'projects' => $projects,
            'statuses' => config('production_studio.statuses', []),
            'stages' => config('production_studio.stages', []),
            'assignees' => User::where('role', 'admin')->orderBy('name')->get(['id', 'name']),
            'stories' => Story::orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function storeFromOrder(Order $order)
    {
        $this->ensureEnabled();

        $project = $order->productionProject()->first();

        if ($project) {
            return redirect()
                ->route('admin.production-studio.show', $project)
                ->with('success', 'هذا الطلب موجود بالفعل داخل استوديو الإنتاج.');
        }

        $project = ProductionStudio::createProjectFromOrder($order, auth()->user());

        return redirect()
            ->route('admin.production-studio.show', $project)
            ->with('success', 'تم إنشاء مشروع استوديو الإنتاج بدون تغيير حالة الطلب الأصلي.');
    }

    public function show(ProductionProject $project, AiProviderAvailability $availability)
    {
        $this->ensureEnabled();

        $project->load([
            'order.user',
            'order.story',
            'order.items.product',
            'order.items.variant',
            'order.items.linkedAddOns.product',
            'assignedTo',
            'creator',
            'storyVersions.creator',
            'storyVersions.reviewer',
            'characterProfile',
            'scenes',
            'qaChecks.reviewer',
            'assets.uploader',
            'assets.reviewer',
            'assets.scene',
            'assets.generationJob.provider',
            'assets.generationJob.model',
            'generationJobs.provider',
            'generationJobs.model',
            'generationJobs.initiator',
            'activityLogs.actor',
        ]);

        $aiModels = AiModel::query()
            ->with('provider')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->filter(fn (AiModel $model): bool => collect($model->generation_capabilities_json ?? [])
                ->contains(fn (string $capability): bool => $availability->modelAvailable($model, $capability)))
            ->values();

        $imageCapabilities = ['character_sheet', 'scene_generation', 'cover_generation', 'premium_retry'];
        $textCapabilities = ['vision_to_text', 'text_to_json', 'prompt_enhancement', 'scene_extraction'];
        $imageModelsByCapability = collect($imageCapabilities)
            ->mapWithKeys(fn (string $capability) => [$capability => $availability->activeModelsForCapability($capability)]);
        $textModelsByCapability = collect($textCapabilities)
            ->mapWithKeys(fn (string $capability) => [$capability => $this->activeModelsForDriverCapability('openai', $capability)]);
        $openAiProvider = AiProvider::query()
            ->where('driver', 'openai')
            ->with('models')
            ->first();
        $providers = $aiModels->pluck('provider')->unique('id');

        return view('admin.production-studio.show', [
            'project' => $project,
            'order' => $project->order,
            'statuses' => config('production_studio.statuses', []),
            'stages' => config('production_studio.stages', []),
            'assignees' => User::where('role', 'admin')->orderBy('name')->get(['id', 'name']),
            'aiModels' => $aiModels,
            'aiAvailable' => $imageModelsByCapability->flatten(1)->isNotEmpty(),
            'openAiAvailable' => $textModelsByCapability->flatten(1)->isNotEmpty(),
            'aiModelsByCapability' => $imageModelsByCapability->all(),
            'textModelsByCapability' => $textModelsByCapability->all(),
            'defaultModelsByCapability' => $providers
                ->mapWithKeys(fn ($provider) => collect($imageCapabilities)
                    ->mapWithKeys(fn ($capability) => [$capability => $availability->defaultModelFor($provider, $capability)?->code])
                    ->filter()
                    ->all())
                ->all(),
            'defaultTextModelsByCapability' => $this->defaultModelCodesForDriverCapabilities($openAiProvider, $textCapabilities),
            'stylePresets' => config('production_studio.ai.style_presets', []),
            'aiCostSummary' => $project->aiCostSummary(),
            'characterAnalysisPreview' => session($this->characterAnalysisSessionKey($project))
                ?: $this->latestStructuredAiPreview($project, 'character_analysis'),
            'sceneExtractionPreview' => session($this->sceneExtractionSessionKey($project))
                ?: $this->latestStructuredAiPreview($project, 'scene_extraction'),
            'sceneImprovementPreviews' => $this->sceneImprovementPreviews($project),
            'existingProductionPrompt' => auth()->user()->hasPermission('orders.production_prompt.manage')
                ? StoryProductionPrompt::forOrder($project->order)
                : null,
        ]);
    }

    public function servePhoto(ProductionProject $project, int $index)
    {
        $this->ensureEnabled();

        $photos = $project->order?->uploaded_photos ?? [];

        if (! isset($photos[$index])) {
            abort(404);
        }

        $photoPath = $photos[$index];

        if (! is_string($photoPath) || str_contains($photoPath, '..')) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if ($disk->exists($photoPath)) {
            return response()->file($disk->path($photoPath), [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        $publicDisk = Storage::disk('public');

        if ($publicDisk->exists($photoPath)) {
            return response()->file($publicDisk->path($photoPath), [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        $legacyPath = storage_path('app/'.ltrim($photoPath, '/'));

        if (file_exists($legacyPath) && is_file($legacyPath)) {
            return response()->file($legacyPath, [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        abort(404);
    }

    public function serveGeneratedAsset(ProductionProject $project, ProductionProjectAsset $asset)
    {
        $this->ensureEnabled();
        abort_unless($asset->production_project_id === $project->id, 404);

        if (! is_string($asset->file_path) || str_contains($asset->file_path, '..')) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($asset->file_path)) {
            abort(404);
        }

        return response()->file($disk->path($asset->file_path), [
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    public function generateCharacterSheet(Request $request, ProductionProject $project, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();

        $validated = $request->validate($this->generationValidation('character_sheet'));
        $validated['job_type'] = 'character_sheet';
        $validated['generation_mode'] = 'character_sheet';

        try {
            $job = $action->execute($project, $validated);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء مهمة توليد الصورة المرجعية للطفل وهي الآن في قائمة الانتظار.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء مهمة توليد الصورة المرجعية للطفل وهي الآن في قائمة الانتظار.');
    }

    public function generateSceneImage(Request $request, ProductionProject $project, ProductionScene $scene, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();
        abort_unless($scene->production_project_id === $project->id, 404);

        $validated = $request->validate($this->generationValidation('scene_image'));
        $validated['job_type'] = 'scene_image';
        $validated['generation_mode'] = 'character_scene';

        try {
            $job = $action->execute($project, $validated, $scene);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء مهمة توليد صورة المشهد وهي الآن في قائمة الانتظار.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء مهمة توليد صورة المشهد وهي الآن في قائمة الانتظار.');
    }

    public function generateCoverImage(Request $request, ProductionProject $project, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();

        $validated = $request->validate($this->generationValidation('cover_image'));
        $validated['job_type'] = 'cover_image';
        $validated['generation_mode'] = 'cover_generation';

        try {
            $job = $action->execute($project, $validated);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء مهمة توليد الغلاف وهي الآن في قائمة الانتظار.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء مهمة توليد الغلاف وهي الآن في قائمة الانتظار.');
    }

    public function retryGeneration(Request $request, ProductionProject $project, SceneGenerationJob $generationJob, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();
        abort_unless($generationJob->production_project_id === $project->id, 404);

        $validated = $request->validate([
            'prompt_notes' => 'nullable|string|max:3000',
        ]);

        $payload = [
            'model_code' => $generationJob->model?->code,
            'job_type' => $generationJob->job_type,
            'generation_mode' => $generationJob->generation_mode,
            'style_preset' => data_get($generationJob->provider_request_json, 'style_preset', 'premium_storybook'),
            'reference_photo_indices' => data_get($generationJob->input_assets_json, 'reference_photo_indices', []),
            'character_sheet_id' => data_get($generationJob->input_assets_json, 'character_sheet_id'),
            'prompt_notes' => $validated['prompt_notes'] ?? null,
        ];

        try {
            $job = $action->execute($project, $payload, $generationJob->scene);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء محاولة جديدة بناءً على المهمة السابقة.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء محاولة جديدة بناءً على المهمة السابقة.');
    }

    public function approveAsset(Request $request, ProductionProject $project, ProductionProjectAsset $asset, ApproveGeneratedAssetAction $action)
    {
        $this->ensureEnabled();
        abort_unless($asset->production_project_id === $project->id, 404);

        $validated = $request->validate(['review_notes' => 'nullable|string|max:2000']);
        $action->execute($asset, $validated['review_notes'] ?? null);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'تم اعتماد المخرج.',
                'asset' => $this->assetPayload($asset->fresh()),
            ]);
        }

        return back()->with('success', 'تم اعتماد المخرج.');
    }

    public function rejectAsset(Request $request, ProductionProject $project, ProductionProjectAsset $asset, RejectGeneratedAssetAction $action)
    {
        $this->ensureEnabled();
        abort_unless($asset->production_project_id === $project->id, 404);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:2000',
            'archive' => 'nullable|boolean',
        ]);

        $action->execute($asset, $validated['rejection_reason'], (bool) ($validated['archive'] ?? false));

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'تم تحديث حالة المخرج.',
                'asset' => $this->assetPayload($asset->fresh()),
            ]);
        }

        return back()->with('success', 'تم تحديث حالة المخرج.');
    }

    public function generationJobStatus(ProductionProject $project, SceneGenerationJob $generationJob): JsonResponse
    {
        $this->ensureEnabled();
        abort_unless($generationJob->production_project_id === $project->id, 404);

        return response()->json([
            'ok' => true,
            'job' => $this->jobPayload($generationJob->fresh(['model.provider'])),
        ]);
    }

    public function update(Request $request, ProductionProject $project)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'status' => ['required', Rule::in(array_keys(config('production_studio.statuses', [])))],
            'current_stage' => ['nullable', Rule::in(array_keys(config('production_studio.stages', [])))],
            'assigned_to_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'production_notes' => ['nullable', 'string', 'max:5000'],
            'qa_override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validated['status'] === 'ready_for_print' && $project->hasBlockingQaFailures()) {
            abort_unless(auth()->user()->hasPermission('production_studio.qa_review'), 403);

            if (blank($validated['qa_override_reason'] ?? null)) {
                return back()
                    ->withErrors(['qa_override_reason' => 'لا يمكن نقل المشروع إلى جاهز للطباعة قبل إكمال مراجعة الجودة أو إدخال سبب تجاوز.'])
                    ->withInput();
            }

            $project->qaChecks()
                ->where('is_mandatory', true)
                ->whereIn('result', ['not_reviewed', 'fail'])
                ->update([
                    'override_allowed' => true,
                    'override_reason' => $validated['qa_override_reason'],
                    'reviewed_by_user_id' => auth()->id(),
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $before = $project->only(['status', 'current_stage', 'assigned_to_user_id', 'production_notes']);
        $project->update([
            'status' => $validated['status'],
            'current_stage' => $validated['current_stage'] ?? null,
            'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? null,
            'production_notes' => $validated['production_notes'] ?? null,
            'started_at' => $project->started_at ?: ($validated['status'] === 'in_progress' ? now() : null),
            'completed_at' => $validated['status'] === 'completed' ? now() : $project->completed_at,
        ]);

        ProductionStudio::log($project, 'project.updated', 'تم تحديث بيانات مشروع الاستوديو.', [
            'changes' => AdminActivityLogger::changedValues($before, $project->only(array_keys($before))),
        ], auth()->user());

        return back()->with('success', 'تم تحديث مشروع الاستوديو.');
    }

    public function archive(ProductionProject $project)
    {
        $this->ensureEnabled();

        $project->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);

        ProductionStudio::log($project, 'project.archived', 'تمت أرشفة مشروع الاستوديو.', [], auth()->user());

        return back()->with('success', 'تمت أرشفة المشروع بدون التأثير على الطلب الأصلي.');
    }

    public function cancel(Request $request, ProductionProject $project)
    {
        $this->ensureEnabled();

        $validated = $request->validate(['cancel_reason' => 'required|string|max:1000']);

        $project->update([
            'status' => 'cancelled',
            'cancel_reason' => $validated['cancel_reason'],
        ]);

        ProductionStudio::log($project, 'project.cancelled', 'تم إلغاء مشروع الاستوديو فقط.', [
            'reason' => $validated['cancel_reason'],
        ], auth()->user());

        return back()->with('success', 'تم إلغاء مشروع الاستوديو فقط. الطلب الأصلي لم يتغير.');
    }

    public function reopen(ProductionProject $project)
    {
        $this->ensureEnabled();

        $project->update([
            'status' => 'draft',
            'archived_at' => null,
            'cancel_reason' => null,
        ]);

        ProductionStudio::log($project, 'project.reopened', 'تمت إعادة فتح مشروع الاستوديو.', [], auth()->user());

        return back()->with('success', 'تمت إعادة فتح المشروع.');
    }

    public function createDraftFromStory(ProductionProject $project)
    {
        $this->ensureEnabled();

        $project->loadMissing('order.story');
        $story = $project->order->story;
        $versionNumber = ((int) $project->storyVersions()->max('version_number')) + 1;
        $content = $story?->full_story ?? $story?->full_desc ?? $story?->short_desc;

        $version = $project->storyVersions()->create([
            'version_number' => $versionNumber,
            'title' => $story?->title,
            'target_age_group' => $story?->age_range,
            'educational_values_json' => array_values(array_filter([$project->order->lesson, $story?->lesson_value])),
            'full_story_content' => $content,
            'status' => 'draft',
            'created_by_user_id' => auth()->id(),
        ]);

        if (! $project->scenes()->exists()) {
            foreach (range(1, 13) as $sceneNumber) {
                $project->scenes()->create([
                    'production_story_version_id' => $version->id,
                    'scene_number' => $sceneNumber,
                    'title' => 'Scene '.$sceneNumber,
                    'story_text' => ProductionStudio::sceneSeedText($content, $sceneNumber),
                    'educational_value' => $project->order->lesson ?? $story?->lesson_value,
                    'status' => 'draft',
                ]);
            }
        }

        ProductionStudio::log($project, 'story_version.created', 'تم إنشاء مسودة قصة داخل الاستوديو من القصة الأصلية.', [
            'version_id' => $version->id,
            'version_number' => $version->version_number,
        ], auth()->user());

        return back()->with('success', 'تم إنشاء مسودة القصة داخل الاستوديو بدون تعديل القصة الأصلية.');
    }

    public function reviewStoryVersion(Request $request, ProductionProject $project, ProductionStoryVersion $version)
    {
        $this->ensureEnabled();
        abort_unless($version->production_project_id === $project->id, 404);

        $validated = $request->validate([
            'status' => 'required|in:under_review,approved,rejected',
            'review_notes' => 'nullable|string|max:3000',
        ]);

        $version->update([
            'status' => $validated['status'],
            'review_notes' => $validated['review_notes'] ?? null,
            'reviewed_by_user_id' => auth()->id(),
            'approved_at' => $validated['status'] === 'approved' ? now() : null,
        ]);

        ProductionStudio::log($project, 'story_version.'.$validated['status'], 'تم تحديث مراجعة نسخة القصة.', [
            'version_id' => $version->id,
            'status' => $validated['status'],
        ], auth()->user());

        return back()->with('success', 'تم تحديث نسخة القصة.');
    }

    public function extractScenes(Request $request, ProductionProject $project, AiProviderAvailability $availability, AiProviderManager $providers)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'model_code' => ['nullable', 'string', 'exists:ai_models,code'],
            'source_version_id' => ['nullable', 'integer', 'exists:production_story_versions,id'],
        ]);

        $project->loadMissing(['order.story', 'storyVersions']);
        $version = isset($validated['source_version_id'])
            ? $project->storyVersions()->whereKey($validated['source_version_id'])->firstOrFail()
            : $project->storyVersions->sortByDesc('version_number')->first();
        $storyText = $version?->full_story_content
            ?: ($project->order->story?->full_story ?? $project->order->story?->full_desc ?? $project->order->story?->short_desc);

        try {
            $extracted = $this->deterministicSceneExtraction($project, $storyText);
            $source = 'deterministic_parser';
            $job = null;

            if (! $extracted) {
                $model = $this->resolveTextModel($validated['model_code'] ?? null, 'scene_extraction', $availability);
                $job = $this->createQueuedStructuredAiJob($project, $model, 'scene_extraction', 'scene_extraction', [
                    'source_version_id' => $version?->id,
                ]);
                $this->dispatchStructuredAiJob($job);

                return back()->with('success', 'تم إرسال استخراج المشاهد إلى قائمة المعالجة. حدّث الصفحة بعد تشغيل المهمة أو انتظر الكرون.');
            }
        } catch (\Throwable $exception) {
            return back()->withErrors(['scene_extraction' => $this->safeAiError($exception)])->withInput();
        }

        session()->put($this->sceneExtractionSessionKey($project), [
            'source' => $source,
            'job_id' => $job?->id,
            'data' => $extracted,
        ]);

        ProductionStudio::log($project, 'story_scenes.previewed', 'تم تجهيز معاينة المشاهد قبل حفظها.', [
            'source' => $source,
            'job_id' => $job?->id,
        ], auth()->user());

        return back()->with('success', 'تم بناء معاينة المشاهد. راجعها ثم أكد الحفظ.');
    }

    public function applyExtractedScenes(ProductionProject $project)
    {
        $this->ensureEnabled();

        $preview = session($this->sceneExtractionSessionKey($project))
            ?: $this->latestStructuredAiPreview($project, 'scene_extraction');
        $scenes = data_get($preview, 'data.scenes', []);

        if (! is_array($scenes) || count($scenes) !== 13) {
            return back()->withErrors(['scene_extraction' => 'لا توجد معاينة مشاهد صالحة للحفظ.']);
        }

        $project->scenes()->delete();

        foreach ($scenes as $scene) {
            $project->scenes()->create([
                'scene_number' => (int) ($scene['scene_number'] ?? 1),
                'title' => $scene['scene_title'] ?? null,
                'story_text' => $scene['written_text'] ?? null,
                'visual_direction' => $scene['visual_direction'] ?? null,
                'child_action_pose' => $scene['child_action_pose'] ?? null,
                'environment' => $scene['environment'] ?? null,
                'mood_lighting' => $scene['mood_lighting'] ?? null,
                'supporting_characters' => $scene['supporting_characters'] ?? null,
                'key_objects' => $scene['key_objects'] ?? null,
                'continuity_notes' => $scene['continuity_notes'] ?? null,
                'text_safe_area_notes' => $scene['safe_text_area_notes'] ?? null,
                'educational_value' => $scene['educational_value'] ?? null,
                'status' => 'draft',
                'ai_sync_status' => 'scenes_need_review',
            ]);
        }

        session()->forget($this->sceneExtractionSessionKey($project));
        $this->markStructuredAiPreviewApplied($preview);

        ProductionStudio::log($project, 'story_scenes.applied', 'تم حفظ المشاهد المستخرجة بعد المراجعة.', [
            'scene_count' => count($scenes),
            'source' => data_get($preview, 'source'),
        ], auth()->user());

        return back()->with('success', 'تم حفظ المشاهد المستخرجة. راجعها قبل توليد الصور.');
    }

    public function updateCharacterProfile(Request $request, ProductionProject $project)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'appearance_summary' => 'nullable|string|max:3000',
            'hair_details' => 'nullable|string|max:2000',
            'skin_tone' => 'nullable|string|max:1000',
            'eye_color_traits' => 'nullable|string|max:1000',
            'typical_expression' => 'nullable|string|max:1000',
            'identity_rules' => 'nullable|string|max:3000',
            'wardrobe_direction' => 'nullable|string|max:2000',
            'approved_visual_style' => 'nullable|string|max:2000',
            'negative_instructions' => 'nullable|string|max:3000',
            'face_shape_notes' => 'nullable|string|max:2000',
            'body_proportion_notes' => 'nullable|string|max:2000',
            'confidence_notes' => 'nullable|string|max:2000',
            'reference_photo_recommendations' => 'nullable|string|max:2000',
            'analysis_warnings' => 'nullable|string|max:2000',
            'approved_reference_photos' => 'nullable|array',
            'approved_reference_photos.*' => 'integer|min:0',
            'primary_face_reference_index' => 'nullable|integer|min:0',
            'body_reference_index' => 'nullable|integer|min:0',
            'style_reference_index' => 'nullable|integer|min:0',
            'reviewer_notes' => 'nullable|string|max:3000',
        ]);

        $approved = array_values(array_unique(array_map('intval', $validated['approved_reference_photos'] ?? [])));

        foreach (['primary_face_reference_index', 'body_reference_index', 'style_reference_index'] as $referenceField) {
            if (isset($validated[$referenceField]) && ! in_array((int) $validated[$referenceField], $approved, true)) {
                return back()
                    ->withErrors([$referenceField => 'يجب اختيار الصورة كصورة مرجعية معتمدة أولًا.'])
                    ->withInput();
            }
        }

        if (! isset($validated['primary_face_reference_index']) && $approved !== []) {
            $validated['primary_face_reference_index'] = $approved[0];
        }

        foreach (['primary_face_reference_index', 'body_reference_index', 'style_reference_index'] as $referenceField) {
            $validated[$referenceField] = $validated[$referenceField] ?? null;
        }

        $project->characterProfile()->updateOrCreate(
            ['production_project_id' => $project->id],
            $validated + [
                'approved_reference_photos' => $approved,
                'reference_photo_selection' => $approved,
            ]
        );

        ProductionStudio::log($project, 'character_profile.updated', 'تم تحديث ملف الشخصية داخل الاستوديو.', [], auth()->user());

        return back()->with('success', 'تم حفظ ملف الشخصية.');
    }

    public function analyzeCharacterProfile(Request $request, ProductionProject $project, AiProviderAvailability $availability, AiProviderManager $providers)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'model_code' => ['required', 'string', 'exists:ai_models,code'],
            'reference_photo_indices' => ['required', 'array', 'min:1', 'max:4'],
            'reference_photo_indices.*' => ['integer', 'min:0'],
        ]);

        try {
            $model = $this->resolveTextModel($validated['model_code'], 'vision_to_text', $availability);
            $indices = $this->approvedOrExistingPhotoIndices($project, $validated['reference_photo_indices']);
            $job = $this->createQueuedStructuredAiJob($project, $model, 'character_analysis', 'vision_to_text', [
                'reference_photo_indices' => $indices,
            ]);
            $this->dispatchStructuredAiJob($job);
        } catch (\Throwable $exception) {
            return back()->withErrors(['character_analysis' => $this->safeAiError($exception)])->withInput();
        }

        ProductionStudio::log($project, 'character_profile.ai_queued', 'تم إرسال تحليل صور الطفل إلى قائمة المعالجة.', [
            'job_id' => $job->id,
            'reference_photo_indices' => $indices,
        ], auth()->user());

        return back()->with('success', 'تم إرسال تحليل الصور إلى قائمة المعالجة. حدّث الصفحة بعد تشغيل المهمة أو انتظر الكرون.');
    }

    public function applyCharacterAnalysis(ProductionProject $project)
    {
        $this->ensureEnabled();

        $preview = session($this->characterAnalysisSessionKey($project))
            ?: $this->latestStructuredAiPreview($project, 'character_analysis');
        $data = data_get($preview, 'data');

        if (! is_array($data)) {
            return back()->withErrors(['character_analysis' => 'لا توجد معاينة تحليل صالحة للتطبيق.']);
        }

        $project->characterProfile()->updateOrCreate(
            ['production_project_id' => $project->id],
            [
                'appearance_summary' => $data['appearance_summary'] ?? null,
                'hair_details' => $data['hair_details'] ?? null,
                'skin_tone' => $data['skin_tone'] ?? null,
                'eye_color_traits' => $data['eyes_and_visible_traits'] ?? null,
                'typical_expression' => $data['usual_expression'] ?? null,
                'face_shape_notes' => $data['face_shape_notes'] ?? null,
                'body_proportion_notes' => $data['body_proportion_notes'] ?? null,
                'identity_rules' => $data['identity_rules'] ?? null,
                'negative_instructions' => $data['negative_instructions'] ?? null,
                'confidence_notes' => $data['confidence_notes'] ?? null,
                'reference_photo_recommendations' => $data['reference_photo_recommendations'] ?? null,
                'analysis_warnings' => $data['warnings'] ?? null,
            ]
        );

        session()->forget($this->characterAnalysisSessionKey($project));
        $this->markStructuredAiPreviewApplied($preview);

        ProductionStudio::log($project, 'character_profile.ai_applied', 'تم تطبيق تحليل صور الطفل على ملف الشخصية.', [
            'job_id' => data_get($preview, 'job_id'),
        ], auth()->user());

        return back()->with('success', 'تم تطبيق تحليل الصور على ملف الشخصية.');
    }

    public function storeScene(Request $request, ProductionProject $project)
    {
        $this->ensureEnabled();

        $validated = $this->sceneValidation($request);
        $project->scenes()->create($validated);

        ProductionStudio::log($project, 'scene.created', 'تمت إضافة مشهد جديد.', [
            'scene_number' => $validated['scene_number'],
        ], auth()->user());

        return back()->with('success', 'تمت إضافة المشهد.');
    }

    public function updateScene(Request $request, ProductionProject $project, ProductionScene $scene)
    {
        $this->ensureEnabled();
        abort_unless($scene->production_project_id === $project->id, 404);

        $validated = $this->sceneValidation($request);
        $scene->update($validated);

        ProductionStudio::log($project, 'scene.updated', 'تم تحديث مشهد.', [
            'scene_id' => $scene->id,
            'scene_number' => $scene->scene_number,
        ], auth()->user());

        return back()->with('success', 'تم تحديث المشهد.');
    }

    public function improveScene(Request $request, ProductionProject $project, ProductionScene $scene, AiProviderAvailability $availability, AiProviderManager $providers)
    {
        $this->ensureEnabled();
        abort_unless($scene->production_project_id === $project->id, 404);

        $validated = $request->validate([
            'model_code' => ['required', 'string', 'exists:ai_models,code'],
        ]);

        try {
            $model = $this->resolveTextModel($validated['model_code'], 'prompt_enhancement', $availability);
            $job = $this->createQueuedStructuredAiJob($project, $model, 'scene_improvement', 'prompt_enhancement', [], $scene);
            $this->dispatchStructuredAiJob($job);
        } catch (\Throwable $exception) {
            return back()->withErrors(['scene_improvement' => $this->safeAiError($exception)])->withInput();
        }

        ProductionStudio::log($project, 'scene.ai_improvement_queued', 'تم إرسال تحسين التوجيه البصري إلى قائمة المعالجة.', [
            'scene_id' => $scene->id,
            'job_id' => $job->id,
        ], auth()->user());

        return back()->with('success', 'تم إرسال تحسين المشهد إلى قائمة المعالجة. حدّث الصفحة بعد تشغيل المهمة أو انتظر الكرون.');
    }

    public function applySceneImprovement(ProductionProject $project, ProductionScene $scene)
    {
        $this->ensureEnabled();
        abort_unless($scene->production_project_id === $project->id, 404);

        $previews = session($this->sceneImprovementSessionKey($project), []);
        $preview = $previews[$scene->id] ?? $this->latestStructuredAiPreview($project, 'scene_improvement', $scene);
        $data = data_get($preview, 'data');

        if (! is_array($data)) {
            return back()->withErrors(['scene_improvement' => 'لا توجد معاينة تحسين صالحة للتطبيق.']);
        }

        $scene->update([
            'visual_direction' => $data['visual_direction'] ?? $scene->visual_direction,
            'child_action_pose' => $data['child_action_pose'] ?? $scene->child_action_pose,
            'environment' => $data['environment'] ?? $scene->environment,
            'mood_lighting' => $data['mood_lighting'] ?? $scene->mood_lighting,
            'supporting_characters' => $data['supporting_characters'] ?? $scene->supporting_characters,
            'key_objects' => $data['key_objects'] ?? $scene->key_objects,
            'continuity_notes' => $data['continuity_notes'] ?? $scene->continuity_notes,
            'text_safe_area_notes' => $data['safe_text_area_notes'] ?? $scene->text_safe_area_notes,
            'ai_sync_status' => 'scenes_need_review',
        ]);

        unset($previews[$scene->id]);
        session()->put($this->sceneImprovementSessionKey($project), $previews);
        $this->markStructuredAiPreviewApplied($preview);

        ProductionStudio::log($project, 'scene.ai_improvement_applied', 'تم تطبيق تحسين التوجيه البصري على المشهد.', [
            'scene_id' => $scene->id,
        ], auth()->user());

        return back()->with('success', 'تم تطبيق تحسين المشهد.');
    }

    public function updateQa(Request $request, ProductionProject $project, ProductionQaCheck $qaCheck)
    {
        $this->ensureEnabled();
        abort_unless($qaCheck->production_project_id === $project->id, 404);

        $validated = $request->validate([
            'result' => 'required|in:not_reviewed,pass,fail,not_applicable',
            'note' => 'nullable|string|max:2000',
            'override_allowed' => 'nullable|boolean',
            'override_reason' => 'nullable|string|max:1000',
        ]);

        $qaCheck->update([
            'result' => $validated['result'],
            'note' => $validated['note'] ?? null,
            'override_allowed' => (bool) ($validated['override_allowed'] ?? false),
            'override_reason' => $validated['override_reason'] ?? null,
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ProductionStudio::log($project, 'qa.updated', 'تم تحديث بند مراجعة الجودة.', [
            'item_key' => $qaCheck->item_key,
            'result' => $validated['result'],
        ], auth()->user());

        return back()->with('success', 'تم تحديث مراجعة الجودة.');
    }

    private function sceneValidation(Request $request): array
    {
        return $request->validate([
            'scene_number' => 'required|integer|min:1|max:100',
            'title' => 'nullable|string|max:255',
            'story_text' => 'nullable|string|max:10000',
            'educational_value' => 'nullable|string|max:2000',
            'visual_direction' => 'nullable|string|max:5000',
            'child_action_pose' => 'nullable|string|max:3000',
            'environment' => 'nullable|string|max:3000',
            'mood_lighting' => 'nullable|string|max:3000',
            'supporting_characters' => 'nullable|string|max:3000',
            'key_objects' => 'nullable|string|max:3000',
            'continuity_notes' => 'nullable|string|max:3000',
            'text_safe_area_notes' => 'nullable|string|max:3000',
            'ai_sync_status' => 'nullable|string|max:100',
            'status' => 'nullable|string|max:100',
            'review_notes' => 'nullable|string|max:3000',
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(ProductionStudio::enabled(), 404);
    }

    private function generationValidation(string $assetType): array
    {
        $rules = [
            'model_code' => ['required', 'string', 'exists:ai_models,code'],
            'style_preset' => ['nullable', Rule::in(array_keys(config('production_studio.ai.style_presets', [])))],
            'reference_photo_indices' => ['nullable', 'array', 'max:4'],
            'reference_photo_indices.*' => ['integer', 'min:0'],
            'prompt_notes' => ['nullable', 'string', 'max:3000'],
            'negative_prompt' => ['nullable', 'string', 'max:2000'],
        ];

        if (in_array($assetType, ['scene_image', 'cover_image'], true)) {
            $rules['character_sheet_id'] = ['nullable', 'integer', 'exists:production_project_assets,id'];
        }

        if ($assetType === 'cover_image') {
            $rules['confirm_primary_face_cover_fallback'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    private function activeModelsForDriverCapability(string $driver, string $capability)
    {
        $provider = AiProvider::query()
            ->where('driver', $driver)
            ->with('models')
            ->first();

        if (! $this->providerUsableForStudio($provider)) {
            return collect();
        }

        $registry = app(SupportedProviderRegistry::class);

        return $provider->models
            ->filter(fn (AiModel $model): bool => $model->is_active
                && $registry->modelSupportsCapability($driver, $model->code, $capability)
                && $model->supportsCapability($capability))
            ->sortBy([['sort_order', 'asc'], ['display_name', 'asc']])
            ->values();
    }

    private function defaultModelCodesForDriverCapabilities(?AiProvider $provider, array $capabilities): array
    {
        if (! $this->providerUsableForStudio($provider)) {
            return [];
        }

        $registry = app(SupportedProviderRegistry::class);

        return collect($capabilities)
            ->mapWithKeys(function (string $capability) use ($provider, $registry): array {
                $code = data_get($provider->settings_json, "default_models.{$capability}");
                $model = $code
                    ? $provider->models->firstWhere('code', $code)
                    : null;

                if (! $model?->is_active || ! $model->supportsCapability($capability) || ! $registry->modelSupportsCapability($provider->driver, $model->code, $capability)) {
                    return [$capability => null];
                }

                return [$capability => $model->code];
            })
            ->filter()
            ->all();
    }

    private function providerUsableForStudio(?AiProvider $provider): bool
    {
        return (bool) config('production_studio.enabled', true)
            && $provider?->is_active
            && $provider->last_health_check_status !== 'failed'
            && app(AiProviderCredentialService::class)->hasCredential($provider);
    }

    private function resolveTextModel(?string $modelCode, string $capability, AiProviderAvailability $availability): AiModel
    {
        $provider = AiProvider::query()
            ->where('driver', 'openai')
            ->where('is_active', true)
            ->with('models')
            ->first();
        $defaultCode = $provider ? data_get($provider->settings_json, "default_models.{$capability}") : null;
        $defaultModel = $provider && $defaultCode
            ? $provider->models()
                ->where('code', $defaultCode)
                ->where('is_active', true)
                ->first()
            : null;

        if (! $defaultModel || ! $defaultModel->supportsCapability($capability)) {
            throw new \RuntimeException('OpenAI default model is not configured for this action.');
        }

        if (! $modelCode) {
            return $defaultModel->load('provider');
        }

        $model = AiModel::query()
            ->with('provider')
            ->where('code', $modelCode)
            ->whereHas('provider', fn ($query) => $query->where('driver', 'openai'))
            ->where('is_active', true)
            ->firstOrFail();

        if (! $model->provider?->is_active || ! $model->is_active || ! $model->supportsCapability($capability)) {
            throw new \RuntimeException('OpenAI model is not available for this action.');
        }

        return $model;
    }

    private function approvedOrExistingPhotoIndices(ProductionProject $project, array $indices): array
    {
        $photos = $project->order?->uploaded_photos ?? [];
        $approved = array_values(array_unique(array_map('intval', $project->characterProfile?->approved_reference_photos ?? [])));
        $indices = array_values(array_unique(array_map('intval', $indices)));

        foreach ($indices as $index) {
            if (! isset($photos[$index])) {
                throw new \RuntimeException('Selected child photo does not exist.');
            }

            if ($approved !== [] && ! in_array($index, $approved, true)) {
                throw new \RuntimeException('Selected child photo is not approved as a Studio reference.');
            }
        }

        return $indices;
    }

    private function createQueuedStructuredAiJob(ProductionProject $project, AiModel $model, string $jobType, string $mode, array $inputAssets = [], ?ProductionScene $scene = null): SceneGenerationJob
    {
        $job = $project->generationJobs()->create([
            'production_scene_id' => $scene?->id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'job_type' => $jobType,
            'generation_mode' => $mode,
            'input_assets_json' => $inputAssets,
            'provider_request_json' => [
                'provider_driver' => $model->provider->driver,
                'provider_display_name' => $model->provider->public_name,
                'model_code' => $model->code,
                'model_display_name' => $model->display_name,
                'capability' => $mode,
            ],
            'estimated_cost' => $model->estimatedCost(),
            'cost_source' => 'estimated',
            'status' => 'queued',
            'initiated_by_user_id' => auth()->id(),
        ]);

        ProductionStudio::log($project, 'ai_text_vision.queued', 'تمت إضافة مهمة نص/رؤية إلى قائمة المعالجة.', [
            'job_id' => $job->id,
            'job_type' => $jobType,
            'generation_mode' => $mode,
            'model' => $model->code,
        ], auth()->user());

        return $job;
    }

    private function dispatchStructuredAiJob(SceneGenerationJob $job): void
    {
        $dispatch = ProcessStructuredAiJob::dispatch($job->id);

        if (! app()->environment('testing')) {
            $dispatch->onConnection('database');
        }
    }

    private function recordStructuredAiJob(ProductionProject $project, AiModel $model, string $jobType, string $mode, StructuredAiResult $result, array $inputAssets = [], ?ProductionScene $scene = null): SceneGenerationJob
    {
        $job = $project->generationJobs()->create([
            'production_scene_id' => $scene?->id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'job_type' => $jobType,
            'generation_mode' => $mode,
            'prompt_snapshot' => $result->prompt,
            'input_assets_json' => $inputAssets,
            'provider_request_json' => [
                'provider_driver' => $model->provider->driver,
                'provider_display_name' => $model->provider->public_name,
                'model_code' => $model->code,
                'model_display_name' => $model->display_name,
                'capability' => $mode,
            ],
            'provider_response_json' => [
                'usage' => $result->usage,
                'structured_result' => $result->data,
                'metadata' => $result->metadata,
            ],
            'estimated_cost' => $model->estimatedCost(),
            'actual_cost' => $result->actualCost,
            'cost_source' => $result->costSource,
            'status' => 'completed',
            'initiated_by_user_id' => auth()->id(),
            'submitted_at' => now(),
            'completed_at' => now(),
        ]);

        ProductionStudio::log($project, 'ai_text_vision.completed', 'تم تنفيذ مهمة نص/رؤية بالذكاء الاصطناعي.', [
            'job_id' => $job->id,
            'job_type' => $jobType,
            'generation_mode' => $mode,
            'model' => $model->code,
        ], auth()->user());

        return $job;
    }

    private function latestStructuredAiPreview(ProductionProject $project, string $jobType, ?ProductionScene $scene = null): ?array
    {
        $job = $project->generationJobs()
            ->where('job_type', $jobType)
            ->where('status', 'completed')
            ->when($scene, fn ($query) => $query->where('production_scene_id', $scene->id))
            ->where(function ($query) {
                $query->whereNull('output_metadata_json')
                    ->orWhere('output_metadata_json->applied_at', null);
            })
            ->latest()
            ->first();

        $data = data_get($job?->provider_response_json, 'structured_result');

        if (! is_array($data)) {
            return null;
        }

        return [
            'source' => data_get($job->provider_request_json, 'provider_driver', 'openai'),
            'job_id' => $job->id,
            'data' => $data,
        ];
    }

    private function sceneImprovementPreviews(ProductionProject $project): array
    {
        $previews = session($this->sceneImprovementSessionKey($project), []);

        foreach ($project->scenes as $scene) {
            if (isset($previews[$scene->id])) {
                continue;
            }

            $preview = $this->latestStructuredAiPreview($project, 'scene_improvement', $scene);

            if ($preview) {
                $previews[$scene->id] = $preview;
            }
        }

        return $previews;
    }

    private function markStructuredAiPreviewApplied(?array $preview): void
    {
        $jobId = data_get($preview, 'job_id');

        if (! $jobId) {
            return;
        }

        $job = SceneGenerationJob::find($jobId);

        if (! $job) {
            return;
        }

        $metadata = $job->output_metadata_json ?? [];
        $metadata['applied_at'] = now()->toIso8601String();
        $metadata['applied_by_user_id'] = auth()->id();

        $job->update(['output_metadata_json' => $metadata]);
    }

    private function deterministicSceneExtraction(ProductionProject $project, ?string $storyText): ?array
    {
        $text = trim((string) $storyText);

        if ($text === '') {
            return null;
        }

        preg_match_all('/(?:^|\R)\s*(?:Scene|مشهد)\s*([0-9٠-٩]+)\s*[:：\\-–]?\s*(.*?)(?=(?:\R\s*(?:Scene|مشهد)\s*[0-9٠-٩]+\s*[:：\\-–]?)|\z)/su', $text, $matches, PREG_SET_ORDER);

        if (count($matches) !== 13) {
            return null;
        }

        $scenes = collect($matches)->map(function (array $match, int $index): array {
            $body = trim($match[2] ?? '');
            $lines = collect(preg_split('/\R/u', $body) ?: [])
                ->map(fn (string $line): string => trim($line))
                ->filter()
                ->values();
            $title = $lines->first() ?: 'Scene '.($index + 1);
            $written = $lines->slice(1)->implode("\n") ?: $body;

            return [
                'scene_number' => $index + 1,
                'scene_title' => $title,
                'written_text' => $written,
                'visual_direction' => 'Needs visual direction review for: '.$title,
                'child_action_pose' => 'Needs child action / pose review.',
                'environment' => 'Needs environment review.',
                'mood_lighting' => 'Warm premium children book lighting.',
                'supporting_characters' => 'Original supporting characters only if needed.',
                'key_objects' => 'Key objects should follow the written scene.',
                'continuity_notes' => 'Maintain continuity from previous scene.',
                'safe_text_area_notes' => 'Reserve a calm low-detail area for Arabic text overlay.',
                'educational_value' => 'Review educational value.',
            ];
        })->all();

        return [
            'story_title' => $project->order?->story?->title ?? 'Not available',
            'story_summary' => $project->order?->story?->short_desc ?? 'Not available',
            'target_age_range' => $project->order?->story?->age_range ?? 'Not available',
            'educational_values' => array_values(array_filter([$project->order?->lesson, $project->order?->story?->lesson_value])),
            'scenes' => $scenes,
        ];
    }

    private function characterAnalysisSessionKey(ProductionProject $project): string
    {
        return 'production_studio.character_analysis.'.$project->id;
    }

    private function sceneExtractionSessionKey(ProductionProject $project): string
    {
        return 'production_studio.scene_extraction.'.$project->id;
    }

    private function sceneImprovementSessionKey(ProductionProject $project): string
    {
        return 'production_studio.scene_improvement.'.$project->id;
    }

    private function safeAiError(\Throwable $exception): string
    {
        return preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $exception->getMessage()) ?: 'AI generation failed.';
    }

    private function studioJsonSuccess(string $message, ProductionProject $project, SceneGenerationJob $job): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'message' => $message,
            'job' => $this->jobPayload($job->fresh(['model.provider'])),
            'status_url' => route('admin.production-studio.ai.jobs.status', [$project, $job]),
        ], 201);
    }

    private function studioJsonError(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
        ], $status);
    }

    private function jobPayload(SceneGenerationJob $job): array
    {
        return [
            'id' => $job->id,
            'job_type' => $job->job_type,
            'generation_mode' => $job->generation_mode,
            'status' => $job->status,
            'model' => $job->model?->display_name,
            'provider' => $job->model?->provider?->public_name,
            'estimated_cost' => $job->estimated_cost,
            'actual_cost' => $job->actual_cost,
            'error_message' => $job->error_message,
            'created_at' => $job->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $job->updated_at?->format('Y-m-d H:i:s'),
            'completed_at' => $job->completed_at?->format('Y-m-d H:i:s'),
            'failed_at' => $job->failed_at?->format('Y-m-d H:i:s'),
            'asset_id' => data_get($job->output_metadata_json, 'asset_id'),
        ];
    }

    private function assetPayload(?ProductionProjectAsset $asset): ?array
    {
        if (! $asset) {
            return null;
        }

        return [
            'id' => $asset->id,
            'asset_type' => $asset->asset_type,
            'status' => $asset->status,
            'is_primary' => $asset->is_primary,
            'is_final' => $asset->is_final,
            'review_notes' => $asset->review_notes,
            'rejection_reason' => $asset->rejection_reason,
        ];
    }
}
