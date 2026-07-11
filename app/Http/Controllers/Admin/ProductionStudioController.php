<?php

namespace App\Http\Controllers\Admin;

use App\Actions\ProductionStudio\ApproveGeneratedAssetAction;
use App\Actions\ProductionStudio\CreateGenerationJobAction;
use App\Actions\ProductionStudio\DeleteGeneratedAssetAction;
use App\Actions\ProductionStudio\RejectGeneratedAssetAction;
use App\DTOs\Ai\StructuredAiResult;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateProductionLayoutJob;
use App\Jobs\ProcessStructuredAiJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\ProductionPrintLayout;
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
use App\Services\ProductionStudio\ProductionLayoutBuilder;
use App\Services\ProductionStudio\ScenePersonalizationService;
use App\Support\AdminActivityLogger;
use App\Support\Ai\SupportedProviderRegistry;
use App\Support\ProductionStudio;
use App\Support\StoryProductionPrompt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function show(ProductionProject $project, AiProviderAvailability $availability, ScenePersonalizationService $personalizer, ProductionLayoutBuilder $layoutBuilder)
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
            'generationJobs.scene',
            'generationJobs.assets',
            'generationJobs.initiator',
            'activityLogs.actor',
            'printLayouts.generatedBy',
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

        $sceneExtractionPreview = session($this->sceneExtractionSessionKey($project))
            ?: $this->latestStructuredAiPreview($project, 'scene_extraction');
        $sceneExtractionPreview = $personalizer->decoratePreview($project, $sceneExtractionPreview);
        $printLayout = $project->printLayouts->sortByDesc('version_number')->first();
        $layoutSettings = $layoutBuilder->normalizedSettings($project, $printLayout?->settings_json ?? []);

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
            'defaultModelsByCapability' => $this->defaultImageModelCodes($providers, $imageCapabilities, $availability),
            'defaultTextModelsByCapability' => $this->defaultModelCodesForDriverCapabilities($openAiProvider, $textCapabilities),
            'stylePresets' => config('production_studio.ai.style_presets', []),
            'aiCostSummary' => $project->aiCostSummary(),
            'characterAnalysisPreview' => session($this->characterAnalysisSessionKey($project))
                ?: $this->latestStructuredAiPreview($project, 'character_analysis'),
            'sceneExtractionPreview' => $sceneExtractionPreview,
            'sceneImprovementPreviews' => $this->sceneImprovementPreviews($project),
            'existingProductionPrompt' => auth()->user()->hasPermission('orders.production_prompt.manage')
                ? StoryProductionPrompt::forOrder($project->order)
                : null,
            'printLayout' => $printLayout,
            'layoutSettings' => $layoutSettings,
            'layoutReadiness' => $layoutBuilder->readiness($project, $layoutSettings),
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
            return $this->privateCachedFileResponse($disk->path($photoPath));
        }

        $publicDisk = Storage::disk('public');

        if ($publicDisk->exists($photoPath)) {
            return $this->privateCachedFileResponse($publicDisk->path($photoPath));
        }

        $legacyPath = storage_path('app/'.ltrim($photoPath, '/'));

        if (file_exists($legacyPath) && is_file($legacyPath)) {
            return $this->privateCachedFileResponse($legacyPath);
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

        return $this->privateCachedFileResponse($disk->path($asset->file_path));
    }

    public function uploadLayoutAsset(Request $request, ProductionProject $project)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'asset_type' => ['required', Rule::in(['cover_image', 'back_cover_image'])],
            'image' => ['required', 'file', 'max:15360'],
        ]);
        $file = $request->file('image');
        $contents = file_get_contents($file->getRealPath());
        $imageInfo = $contents === false ? false : @getimagesizefromstring($contents);
        $mime = $imageInfo['mime'] ?? null;
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

        if (! $imageInfo || ! isset($extensions[$mime])) {
            return back()->withErrors(['image' => 'ارفع صورة صالحة بصيغة JPG أو PNG أو WebP.']);
        }

        $type = $validated['asset_type'];
        $path = "production-studio/projects/{$project->id}/layout/manual/".Str::uuid().'.'.$extensions[$mime];
        Storage::disk('local')->put($path, $contents);

        if ($type === 'cover_image') {
            $project->assets()->where('asset_type', 'cover_image')->update(['is_final' => false]);
        }

        $asset = $project->assets()->create([
            'asset_type' => $type,
            'label' => $type === 'cover_image' ? 'Manual Front Cover' : 'Manual Back Cover',
            'file_path' => $path,
            'status' => 'approved',
            'is_final' => $type === 'cover_image',
            'metadata_json' => [
                'source' => 'manual_layout_upload',
                'mime_type' => $mime,
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'size_bytes' => strlen($contents),
            ],
            'uploaded_by_user_id' => auth()->id(),
            'reviewed_by_user_id' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ProductionStudio::log($project, 'layout.asset_uploaded', 'تم رفع أصل يدوي للإخراج والطباعة.', [
            'asset_id' => $asset->id,
            'asset_type' => $type,
        ], auth()->user());

        return back()->with('success', 'تم رفع صورة الإخراج وحفظها بصورة خاصة.');
    }

    public function saveLayout(Request $request, ProductionProject $project, ProductionLayoutBuilder $builder)
    {
        $this->ensureEnabled();
        $settings = $builder->normalizedSettings($project, $this->validatedLayoutSettings($request, $project));
        $layout = $this->editablePrintLayout($project, $settings);
        $layout->update(['settings_json' => $settings]);

        ProductionStudio::log($project, 'layout.settings_saved', 'تم حفظ إعدادات الإخراج والطباعة.', [
            'layout_id' => $layout->id,
            'version' => $layout->version_number,
        ], auth()->user());

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'تم حفظ إعدادات الإخراج.', 'layout' => $this->layoutPayload($layout)]);
        }

        return back()->with('success', 'تم حفظ إعدادات الإخراج والطباعة.');
    }

    public function generateLayout(Request $request, ProductionProject $project, ProductionLayoutBuilder $builder)
    {
        $this->ensureEnabled();
        $settings = $builder->normalizedSettings($project, $this->validatedLayoutSettings($request, $project));
        $readiness = $builder->readiness($project, $settings);

        if (! $readiness['ready']) {
            $message = implode(' ', $readiness['errors']);

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $message], 422)
                : back()->withErrors(['layout' => $message])->withInput();
        }

        $layout = $this->editablePrintLayout($project, $settings);
        $layout->update([
            'settings_json' => $settings,
            'status' => 'queued',
            'error_message' => null,
            'generated_by_user_id' => auth()->id(),
        ]);
        GenerateProductionLayoutJob::dispatch($layout->id);

        ProductionStudio::log($project, 'layout.queued', 'تم إرسال ملفات الإخراج والطباعة إلى قائمة المعالجة.', [
            'layout_id' => $layout->id,
            'version' => $layout->version_number,
        ], auth()->user());

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'تم إرسال ملفات الإخراج إلى قائمة المعالجة.',
                'layout' => $this->layoutPayload($layout),
                'status_url' => route('admin.production-studio.layout.status', [$project, $layout]),
            ], 201);
        }

        return back()->with('success', 'تم إرسال ملفات الإخراج إلى قائمة المعالجة.');
    }

    public function layoutStatus(ProductionProject $project, ProductionPrintLayout $layout): JsonResponse
    {
        $this->ensureEnabled();
        abort_unless($layout->production_project_id === $project->id, 404);

        return response()->json(['ok' => true, 'layout' => $this->layoutPayload($layout->fresh())]);
    }

    public function previewLayout(ProductionProject $project, ProductionLayoutBuilder $builder)
    {
        $this->ensureEnabled();
        $project->load(['order.story', 'scenes.approvedFinalImage', 'assets', 'printLayouts']);
        $layout = $project->printLayouts->sortByDesc('version_number')->first();
        $settings = $builder->normalizedSettings($project, $layout?->settings_json ?? []);

        return view('admin.production-studio.layout-preview', [
            'project' => $project,
            'layout' => $layout,
            'settings' => $settings,
            'coverAsset' => $project->assets->firstWhere('id', (int) ($settings['cover_asset_id'] ?? 0)),
            'backCoverAsset' => $project->assets->firstWhere('id', (int) ($settings['back_cover_asset_id'] ?? 0)),
        ]);
    }

    public function downloadLayoutFile(ProductionProject $project, ProductionPrintLayout $layout, string $file)
    {
        $this->ensureEnabled();
        abort_unless($layout->production_project_id === $project->id && $layout->isReady(), 404);
        $paths = [
            'reader' => [$layout->reader_pdf_path, 'reader-order.pdf'],
            'print' => [$layout->print_pdf_path, 'print-ready-a3-booklet.pdf'],
            'manifest' => [$layout->manifest_path, 'print-manifest.csv'],
            'proof' => [$layout->proof_checklist_path, 'proof-print-checklist.pdf'],
        ];
        abort_unless(isset($paths[$file]), 404);
        [$path, $name] = $paths[$file];
        abort_unless(is_string($path) && ! str_contains($path, '..') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, $project->order?->order_number.'-'.$name, [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function privateCachedFileResponse(string $path)
    {
        $lastModified = filemtime($path) ?: time();
        $etag = sha1($path.'|'.$lastModified.'|'.filesize($path));

        $response = response()->file($path, [
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setEtag($etag);
        $response->setLastModified(new \DateTimeImmutable('@'.$lastModified));
        $response->setPrivate();
        $response->setMaxAge(604800);

        return $response->isNotModified(request()) ? $response : $response;
    }

    public function generateCharacterSheet(Request $request, ProductionProject $project, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();

        $validated = $request->validate($this->generationValidation('character_sheet'));
        $validated['job_type'] = 'character_sheet';
        $validated['generation_mode'] = 'character_sheet';

        try {
            $jobs = $this->createImageGenerationCopies($action, $project, $validated);
            $job = $jobs[0];
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء '.count($jobs).' مهمة توليد للصورة المرجعية وهي الآن في قائمة الانتظار.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء '.count($jobs).' مهمة توليد للصورة المرجعية وهي الآن في قائمة الانتظار.');
    }

    public function generateSceneImage(Request $request, ProductionProject $project, ProductionScene $scene, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();
        abort_unless($scene->production_project_id === $project->id, 404);

        $validated = $request->validate($this->generationValidation('scene_image'));
        $validated['job_type'] = 'scene_image';
        $validated['generation_mode'] = 'character_scene';

        try {
            $jobs = $this->createImageGenerationCopies($action, $project, $validated, $scene);
            $job = $jobs[0];
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء '.count($jobs).' مهمة توليد للمشهد وهي الآن في قائمة الانتظار.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء '.count($jobs).' مهمة توليد للمشهد وهي الآن في قائمة الانتظار.');
    }

    public function generateAllSceneImages(Request $request, ProductionProject $project, CreateGenerationJobAction $action): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate($this->generationValidation('scene_image') + [
            'confirm_bulk_generation' => ['required', 'accepted'],
        ]);
        $validated['job_type'] = 'scene_image';
        $validated['generation_mode'] = 'character_scene';
        $validated['output_count'] = 1;

        try {
            $jobs = DB::transaction(function () use ($project, $validated, $action): array {
                ProductionProject::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

                $project->load(['order.story', 'characterProfile', 'assets', 'generationJobs', 'scenes']);
                $candidateScenes = $this->bulkSceneGenerationCandidates($project);

                if ($candidateScenes->isEmpty()) {
                    return [];
                }

                $blocked = $candidateScenes
                    ->reject(fn (ProductionScene $scene): bool => $scene->hasImagePromptContext() && $scene->isPersonalizedForImageGeneration())
                    ->map(fn (ProductionScene $scene): string => 'مشهد '.$scene->scene_number.' — '.($scene->title ?: 'بدون عنوان'))
                    ->values();

                if ($blocked->isNotEmpty()) {
                    throw new \RuntimeException('لا يمكن بدء التوليد الجماعي. أكمل النص والتوجيه البصري ووضع الطفل والتخصيص في: '.$blocked->implode('، '));
                }

                return $candidateScenes
                    ->map(fn (ProductionScene $scene): SceneGenerationJob => $action->execute($project, $validated, $scene))
                    ->all();
            });
        } catch (\Throwable $exception) {
            return $this->studioJsonError($this->safeAiError($exception));
        }

        if ($jobs === []) {
            return response()->json([
                'ok' => true,
                'message' => 'لا توجد مشاهد ناقصة تحتاج إلى توليد. المشاهد التي لها صورة معتمدة أو بانتظار المراجعة أو مهمة جارية تم تجاوزها.',
                'jobs' => [],
            ]);
        }

        $jobIds = collect($jobs)->pluck('id')->all();

        return response()->json([
            'ok' => true,
            'message' => 'تم إرسال '.count($jobs).' مشهد إلى قائمة التوليد. تم تجاوز الصور المعتمدة والمخرجات المنتظرة والمهام الجارية.',
            'jobs' => collect($jobs)->map(fn (SceneGenerationJob $job): array => $this->jobPayload($job->fresh(['model.provider', 'scene', 'assets'])))->all(),
            'status_url' => route('admin.production-studio.ai.jobs.bulk-status', ['project' => $project, 'job_ids' => $jobIds]),
            'estimated_total_cost' => number_format((float) collect($jobs)->sum(fn (SceneGenerationJob $job) => (float) $job->estimated_cost), 4, '.', ''),
        ], 201);
    }

    public function generateCoverImage(Request $request, ProductionProject $project, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();

        $validated = $request->validate($this->generationValidation('cover_image'));
        $validated['job_type'] = 'cover_image';
        $validated['generation_mode'] = 'cover_generation';

        try {
            $jobs = $this->createImageGenerationCopies($action, $project, $validated);
            $job = $jobs[0];
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['ai_generation' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء '.count($jobs).' مهمة توليد للغلاف وهي الآن في قائمة الانتظار.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء '.count($jobs).' مهمة توليد للغلاف وهي الآن في قائمة الانتظار.');
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
            'identity_lock' => data_get($generationJob->provider_request_json, 'identity_lock', true),
            'generation_quality' => data_get($generationJob->provider_request_json, 'generation_quality', 'medium'),
            'output_count' => 1,
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

    public function correctAssetIdentity(Request $request, ProductionProject $project, ProductionProjectAsset $asset, CreateGenerationJobAction $action)
    {
        $this->ensureEnabled();
        abort_unless($asset->production_project_id === $project->id && $asset->asset_type === 'scene_image', 404);

        $validated = $request->validate([
            'model_code' => ['required', 'string', 'exists:ai_models,code'],
            'generation_quality' => ['required', Rule::in(['medium', 'high'])],
            'prompt_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $job = $action->executeIdentityCorrection($project, $asset, $validated);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['identity_correction' => $this->safeAiError($exception)])->withInput();
        }

        if ($request->expectsJson()) {
            return $this->studioJsonSuccess('تم إنشاء مهمة تصحيح الهوية مع الحفاظ على المشهد.', $project, $job);
        }

        return back()->with('success', 'تم إنشاء مهمة تصحيح الهوية مع الحفاظ على المشهد.');
    }

    public function approveAsset(Request $request, ProductionProject $project, ProductionProjectAsset $asset, ApproveGeneratedAssetAction $action)
    {
        $this->ensureEnabled();
        abort_unless($asset->production_project_id === $project->id, 404);

        $validated = $request->validate(['review_notes' => 'nullable|string|max:2000']);
        try {
            $action->execute($asset, $validated['review_notes'] ?? null);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['asset_approval' => $this->safeAiError($exception)]);
        }

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

    public function deleteAsset(Request $request, ProductionProject $project, ProductionProjectAsset $asset, DeleteGeneratedAssetAction $action)
    {
        $this->ensureEnabled();
        abort_unless($asset->production_project_id === $project->id, 404);

        try {
            $deleted = $action->execute($asset);
        } catch (\Throwable $exception) {
            if ($request->expectsJson()) {
                return $this->studioJsonError($this->safeAiError($exception));
            }

            return back()->withErrors(['generated_asset_delete' => $this->safeAiError($exception)]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'تم حذف الصورة المولدة وملفها نهائيًا.',
                'deleted_asset_id' => $deleted['asset_id'],
                'bytes_freed' => $deleted['bytes_freed'],
            ]);
        }

        return back()->with('success', 'تم حذف الصورة المولدة وملفها نهائيًا.');
    }

    public function generationJobStatus(ProductionProject $project, SceneGenerationJob $generationJob): JsonResponse
    {
        $this->ensureEnabled();
        abort_unless($generationJob->production_project_id === $project->id, 404);

        return response()->json([
            'ok' => true,
            'job' => $this->jobPayload($generationJob->fresh(['model.provider', 'scene', 'assets'])),
        ]);
    }

    public function generationJobsStatus(Request $request, ProductionProject $project): JsonResponse
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'job_ids' => ['required', 'array', 'min:1', 'max:20'],
            'job_ids.*' => ['required', 'integer'],
        ]);

        $jobs = $project->generationJobs()
            ->whereIn('id', array_values(array_unique($validated['job_ids'])))
            ->with(['model.provider', 'scene', 'assets'])
            ->get();

        abort_unless($jobs->count() === count(array_unique($validated['job_ids'])), 404);

        return response()->json([
            'ok' => true,
            'jobs' => $jobs->map(fn (SceneGenerationJob $job): array => $this->jobPayload($job))->values(),
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

    public function extractScenes(Request $request, ProductionProject $project, AiProviderAvailability $availability, ScenePersonalizationService $personalizer)
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

            if ($extracted && $personalizer->analyze($project, $extracted)['requires_openai']) {
                $extracted = null;
            }

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

        $preview = $personalizer->decoratePreview($project, [
            'source' => $source,
            'job_id' => $job?->id,
            'data' => $extracted,
        ]);
        session()->put($this->sceneExtractionSessionKey($project), $preview);

        ProductionStudio::log($project, 'story_scenes.previewed', 'تم تجهيز معاينة المشاهد قبل حفظها.', [
            'source' => $source,
            'job_id' => $job?->id,
        ], auth()->user());

        return back()->with('success', 'تم بناء معاينة المشاهد. راجعها ثم أكد الحفظ.');
    }

    public function applyExtractedScenes(Request $request, ProductionProject $project, ScenePersonalizationService $personalizer)
    {
        $this->ensureEnabled();

        $validated = $request->validate([
            'detected_hero_name' => ['nullable', 'string', 'max:120'],
            'personalization_action' => ['nullable', Rule::in(['confirm', 'skip'])],
            'confirm_personalization' => ['nullable', 'boolean'],
        ]);

        $preview = session($this->sceneExtractionSessionKey($project))
            ?: $this->latestStructuredAiPreview($project, 'scene_extraction');
        $skip = ($validated['personalization_action'] ?? 'confirm') === 'skip';
        $preview = $personalizer->decoratePreview(
            $project,
            $preview,
            $validated['detected_hero_name'] ?? null,
            $skip,
        );
        $personalization = data_get($preview, 'personalization', []);
        $scenes = data_get($preview, 'personalized_data.scenes', []);
        $originalScenes = data_get($preview, 'data.scenes', []);

        if (! is_array($scenes) || count($scenes) !== 13) {
            return back()->withErrors(['scene_extraction' => 'لا توجد معاينة مشاهد صالحة للحفظ.']);
        }

        if (! $skip && in_array(data_get($personalization, 'confidence'), ['low', 'unknown'], true) && ! (bool) ($validated['confirm_personalization'] ?? false)) {
            return back()->withErrors(['scene_extraction' => 'راجع اسم بطل القالب ثم أكّد التخصيص قبل استبدال المشاهد.']);
        }

        if (! $skip && data_get($personalization, 'requires_openai')) {
            return back()->withErrors(['scene_extraction' => 'التخصيص يحتاج إعادة بناء عبر OpenAI لضبط اسم البطل وصياغة الجنس قبل الحفظ.']);
        }

        $project->scenes()->delete();

        foreach ($scenes as $index => $scene) {
            $createdScene = $project->scenes()->create([
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
                'original_template_data_json' => $originalScenes[$index] ?? null,
                'template_hero_name' => data_get($personalization, 'template_hero_name'),
                'personalized_hero_name' => data_get($personalization, 'child_hero_name'),
                'personalization_status' => $skip ? 'skipped' : data_get($personalization, 'status', 'needs_review'),
                'personalization_warnings' => data_get($personalization, 'warnings', []),
            ]);

            if (! $skip) {
                $personalizer->refreshSceneStatus($createdScene);
            }
        }

        $projectPersonalizationStatus = $skip
            ? 'skipped'
            : ($project->scenes()->where('personalization_status', '!=', 'personalized')->exists() ? 'needs_review' : 'personalized');

        $project->update([
            'template_hero_name' => data_get($personalization, 'template_hero_name'),
            'template_hero_gender' => data_get($personalization, 'template_hero_gender'),
            'personalized_hero_name' => data_get($personalization, 'child_hero_name'),
            'child_story_role' => data_get($personalization, 'child_story_role'),
            'personalization_status' => $projectPersonalizationStatus,
            'personalization_warnings' => data_get($personalization, 'warnings', []),
        ]);

        session()->forget($this->sceneExtractionSessionKey($project));
        $this->markStructuredAiPreviewApplied($preview);

        ProductionStudio::log($project, 'story_scenes.applied', 'تم حفظ المشاهد المستخرجة بعد المراجعة.', [
            'scene_count' => count($scenes),
            'source' => data_get($preview, 'source'),
            'template_hero_name' => data_get($personalization, 'template_hero_name'),
            'personalized_hero_name' => data_get($personalization, 'child_hero_name'),
            'personalization_status' => $projectPersonalizationStatus,
        ], auth()->user());

        return back()->with('success', $skip
            ? 'تم حفظ المشاهد بدون تخصيص. ستظل صور المشاهد محجوبة حتى مراجعة تعارض أسماء الأبطال.'
            : 'تم تخصيص المشاهد باسم الطفل وحفظها. راجعها قبل توليد الصور.');
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

    public function updateScene(Request $request, ProductionProject $project, ProductionScene $scene, ScenePersonalizationService $personalizer)
    {
        $this->ensureEnabled();
        abort_unless($scene->production_project_id === $project->id, 404);

        $validated = $this->sceneValidation($request);
        $scene->update($validated);
        $personalizer->refreshSceneStatus($scene->fresh());

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

    public function applySceneImprovement(ProductionProject $project, ProductionScene $scene, ScenePersonalizationService $personalizer)
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
        $personalizer->refreshSceneStatus($scene->fresh());

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
            'identity_lock' => ['nullable', 'boolean'],
            'generation_quality' => ['nullable', Rule::in(['medium', 'high'])],
            'output_count' => ['nullable', 'integer', 'min:1', 'max:2'],
        ];

        if (in_array($assetType, ['scene_image', 'cover_image'], true)) {
            $rules['character_sheet_id'] = ['nullable', 'integer', 'exists:production_project_assets,id'];
        }

        if ($assetType === 'cover_image') {
            $rules['confirm_primary_face_cover_fallback'] = ['nullable', 'boolean'];
        }

        return $rules;
    }

    private function bulkSceneGenerationCandidates(ProductionProject $project)
    {
        $scenesWithReviewableImages = $project->assets
            ->where('asset_type', 'scene_image')
            ->whereIn('status', ['under_review', 'approved'])
            ->pluck('production_scene_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();

        $scenesWithActiveJobs = $project->generationJobs
            ->where('job_type', 'scene_image')
            ->whereIn('status', ['queued', 'processing'])
            ->pluck('production_scene_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique();

        $excludedSceneIds = $scenesWithReviewableImages
            ->merge($scenesWithActiveJobs)
            ->unique();

        return $project->scenes
            ->reject(fn (ProductionScene $scene): bool => $excludedSceneIds->contains((int) $scene->id))
            ->sortBy('scene_number')
            ->values();
    }

    private function createImageGenerationCopies(CreateGenerationJobAction $action, ProductionProject $project, array $data, ?ProductionScene $scene = null): array
    {
        $count = max(1, min(2, (int) ($data['output_count'] ?? 1)));
        $jobs = [];

        for ($copy = 0; $copy < $count; $copy++) {
            $jobs[] = $action->execute($project, $data, $scene);
        }

        return $jobs;
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

    private function defaultImageModelCodes($providers, array $capabilities, AiProviderAvailability $availability): array
    {
        $defaults = [];

        foreach ($providers as $provider) {
            foreach ($capabilities as $capability) {
                if (isset($defaults[$capability])) {
                    continue;
                }

                $code = $availability->defaultModelFor($provider, $capability)?->code;

                if ($code) {
                    $defaults[$capability] = $code;
                }
            }
        }

        return $defaults;
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
                'visual_direction' => 'Create one connected A3 landscape two-page reader spread for this scene: '.$title.'. The artwork must continue naturally across both facing A4 pages and support the written scene without showing any text.',
                'child_action_pose' => 'Define the child as the active hero in this scene. Review and refine the exact pose/action before image generation.',
                'environment' => 'Define one cohesive environment across the full A3 landscape spread. Review and refine before image generation.',
                'mood_lighting' => 'Warm premium children book lighting.',
                'supporting_characters' => 'Original supporting characters only if needed.',
                'key_objects' => 'Key objects should follow the written scene.',
                'continuity_notes' => 'Maintain continuity from previous scene.',
                'safe_text_area_notes' => 'Reserve a calm low-detail blank area within the same connected A3 spread for later Arabic text overlay. Do not generate any visible writing.',
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
        $job->loadMissing(['project', 'scene', 'assets']);
        $asset = $job->assets->firstWhere('id', (int) data_get($job->output_metadata_json, 'asset_id'))
            ?? $job->assets->sortByDesc('version_number')->first();

        return [
            'id' => $job->id,
            'job_type' => $job->job_type,
            'generation_mode' => $job->generation_mode,
            'production_scene_id' => $job->production_scene_id,
            'scene_number' => $job->scene?->scene_number,
            'scene_title' => $job->scene?->title,
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
            'asset_url' => $asset ? route('admin.production-studio.assets.show', [$job->project, $asset]) : null,
            'asset_status' => $asset?->status,
            'asset_label' => $asset?->label,
            'asset_version' => $asset?->version_number,
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

    private function validatedLayoutSettings(Request $request, ProductionProject $project): array
    {
        $validated = $request->validate([
            'book_title' => ['required', 'string', 'max:255'],
            'cover_subtitle' => ['nullable', 'string', 'max:255'],
            'cover_title_position' => ['required', Rule::in(['top', 'bottom'])],
            'back_cover_text' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'string', 'max:255'],
            'binding_direction' => ['required', Rule::in(['rtl', 'ltr'])],
            'duplex_flip' => ['required', Rule::in(['short_edge', 'long_edge'])],
            'font_size' => ['required', 'integer', 'min:14', 'max:30'],
            'text_panel_opacity' => ['required', 'integer', 'min:70', 'max:100'],
            'cover_asset_id' => ['required', 'integer'],
            'back_cover_asset_id' => ['nullable', 'integer'],
            'scenes' => ['required', 'array', 'size:13'],
            'scenes.*.text_content' => ['required', 'string', 'max:10000'],
            'scenes.*.text_side' => ['required', Rule::in(['left', 'right'])],
            'scenes.*.text_position' => ['required', Rule::in(['top', 'center', 'bottom'])],
        ]);

        $sceneIds = $project->scenes()->pluck('id')->map(fn ($id): string => (string) $id)->sort()->values()->all();
        $submittedSceneIds = collect(array_keys($validated['scenes']))->map(fn ($id): string => (string) $id)->sort()->values()->all();

        if ($sceneIds !== $submittedSceneIds) {
            throw ValidationException::withMessages(['scenes' => 'إعدادات المشاهد لا تطابق مشاهد هذا المشروع.']);
        }

        $cover = $project->assets()->whereKey($validated['cover_asset_id'])->where('asset_type', 'cover_image')->where('status', 'approved')->first();
        if (! $cover) {
            throw ValidationException::withMessages(['cover_asset_id' => 'الغلاف المحدد غير معتمد أو لا ينتمي إلى هذا المشروع.']);
        }

        if (! empty($validated['back_cover_asset_id'])) {
            $backCover = $project->assets()->whereKey($validated['back_cover_asset_id'])->where('asset_type', 'back_cover_image')->where('status', 'approved')->first();
            if (! $backCover) {
                throw ValidationException::withMessages(['back_cover_asset_id' => 'الغلاف الخلفي المحدد غير معتمد أو لا ينتمي إلى هذا المشروع.']);
            }
        }

        return $validated;
    }

    private function editablePrintLayout(ProductionProject $project, array $settings): ProductionPrintLayout
    {
        return DB::transaction(function () use ($project, $settings): ProductionPrintLayout {
            $latest = $project->printLayouts()->lockForUpdate()->orderByDesc('version_number')->first();

            if ($latest && in_array($latest->status, ['draft', 'failed'], true)) {
                return $latest;
            }

            return $project->printLayouts()->create([
                'version_number' => ($latest?->version_number ?? 0) + 1,
                'status' => 'draft',
                'settings_json' => $settings,
                'generated_by_user_id' => auth()->id(),
            ]);
        });
    }

    private function layoutPayload(ProductionPrintLayout $layout): array
    {
        $project = $layout->project ?: $layout->project()->firstOrFail();

        return [
            'id' => $layout->id,
            'version' => $layout->version_number,
            'status' => $layout->status,
            'error_message' => $layout->error_message,
            'generated_at' => $layout->generated_at?->format('Y-m-d H:i:s'),
            'downloads' => $layout->isReady() ? [
                'reader' => route('admin.production-studio.layout.download', [$project, $layout, 'reader']),
                'print' => route('admin.production-studio.layout.download', [$project, $layout, 'print']),
                'manifest' => route('admin.production-studio.layout.download', [$project, $layout, 'manifest']),
                'proof' => route('admin.production-studio.layout.download', [$project, $layout, 'proof']),
            ] : [],
        ];
    }
}
