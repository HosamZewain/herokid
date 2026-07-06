<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\ProductionQaCheck;
use App\Models\ProductionScene;
use App\Models\ProductionStoryVersion;
use App\Models\Story;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\ProductionStudio;
use App\Support\StoryProductionPrompt;
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

    public function show(ProductionProject $project)
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
            'generationJobs.provider',
            'generationJobs.model',
            'activityLogs.actor',
        ]);

        return view('admin.production-studio.show', [
            'project' => $project,
            'order' => $project->order,
            'statuses' => config('production_studio.statuses', []),
            'stages' => config('production_studio.stages', []),
            'assignees' => User::where('role', 'admin')->orderBy('name')->get(['id', 'name']),
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
            'approved_reference_photos' => 'nullable|array',
            'approved_reference_photos.*' => 'integer|min:0',
            'reviewer_notes' => 'nullable|string|max:3000',
        ]);

        $project->characterProfile()->updateOrCreate(
            ['production_project_id' => $project->id],
            $validated + ['reference_photo_selection' => $validated['approved_reference_photos'] ?? []]
        );

        ProductionStudio::log($project, 'character_profile.updated', 'تم تحديث ملف الشخصية داخل الاستوديو.', [], auth()->user());

        return back()->with('success', 'تم حفظ ملف الشخصية.');
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
            'text_safe_area_notes' => 'nullable|string|max:3000',
            'status' => 'nullable|string|max:100',
            'review_notes' => 'nullable|string|max:3000',
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(ProductionStudio::enabled(), 404);
    }
}
