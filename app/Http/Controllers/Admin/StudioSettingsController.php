<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\StudioAsset;
use App\Models\StudioSession;
use App\Models\StudioSettingsRevision;
use App\Models\User;
use App\Services\RemoteStudio\StudioSessionService;
use App\Services\RemoteStudio\StudioSettingsSchema;
use App\Services\RemoteStudio\StudioSettingsService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StudioSettingsController extends Controller
{
    public function index(StudioSettingsService $service): mixed
    {
        $current = $service->current();
        $configuration = $current?->settings ?? StudioSettingsSchema::defaults();
        $referenced = app(StudioSettingsSchema::class)->assetIds($configuration);
        $assets = StudioAsset::whereIn('id', $referenced)->orWhereIn('id', StudioAsset::latest('created_at')->limit(500)->pluck('id'))->latest('created_at')->get();
        $editorOptions = [
            'schema' => app(StudioSettingsSchema::class)->schema(),
            'models' => AiModel::where('is_active', true)->whereHas('provider', fn ($q) => $q->where('driver', 'openai')->where('is_active', true))->get()->filter(fn ($model) => $model->supportsCapability('cover_generation'))->map(fn ($model) => ['code' => $model->code, 'name' => $model->display_name ?: $model->code])->values(),
            'assets' => $assets->map(fn ($asset) => ['id' => $asset->id, 'width' => $asset->width, 'height' => $asset->height, 'bytes' => $asset->bytes, 'preview_url' => route('admin.studio-settings.assets.show', $asset->id)])->values(),
        ];

        return view('admin.studio-settings.index', [
            'revision' => $current, 'configuration' => $configuration,
            'editorOptions' => $editorOptions,
            'history' => StudioSettingsRevision::latest('id')->limit(30)->get(),
            'assets' => $assets,
            'employees' => User::where('role', 'admin')->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sessions' => StudioSession::whereNull('revoked_at')->where('expires_at', '>', now())->latest('id')->limit(100)->get(),
            'grants' => DB::table('studio_employee_grants')->get()->keyBy('user_id'),
        ]);
    }

    public function publish(Request $request, StudioSettingsService $service): mixed
    {
        $data = $request->validate(['settings_json' => 'required|string|max:2000000', 'base_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:500', 'confirmed' => 'accepted']);
        try {
            $settings = json_decode($data['settings_json'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['settings_json' => 'إعدادات JSON غير صالحة.']);
        }
        if (! is_array($settings)) {
            throw ValidationException::withMessages(['settings_json' => 'الإعدادات يجب أن تكون كائن JSON.']);
        }
        // Friendly controls override the corresponding advanced configuration, not other fields.
        if ($request->has('font_id')) {
            $controls = $request->validate(['font_id' => 'required|string|max:100', 'font_weight' => 'required|integer', 'page_side' => 'required|in:left,right', 'export_mode' => 'required|in:reading-order,booklet',
                'cover_model' => 'required|string|max:100', 'cover_quality' => 'required|string|max:20', 'cover_size' => 'required|string|max:20', 'cover_prompt' => 'required|string|max:20000', 'coloring_prompt' => 'required|string|max:20000', 'cover_notes' => 'nullable|string|max:2000']);
            $settings['production_typography'] = ['default_font_id' => $controls['font_id'], 'default_font_weight' => (int) $controls['font_weight'], 'default_text_box_page_side' => $controls['page_side']];
            $settings['export_preferences'] = ['default_mode' => $controls['export_mode'], 'allow_warnings' => $request->boolean('allow_warnings')];
            $settings['ui_preferences'] = ['show_safe_area' => $request->boolean('show_safe_area'), 'show_bleed' => $request->boolean('show_bleed'), 'show_gutter' => $request->boolean('show_gutter')];
            $settings['openai_cover'] = ['enabled' => $request->boolean('cover_enabled'), 'model' => $controls['cover_model'], 'quality' => $controls['cover_quality'], 'size' => $controls['cover_size'], 'prompt_template' => $controls['cover_prompt'], 'coloring_book_prompt_template' => $controls['coloring_prompt'], 'use_identity_image' => $request->boolean('use_identity_image'), 'cover_style_notes' => $controls['cover_notes'] ?? ''];
            foreach (['default_back_cover', 'brand_logo'] as $key) {
                if ($request->has($key.'_asset_id')) {
                    $id = $request->input($key.'_asset_id');
                    $settings[$key] = $id ? array_merge($settings[$key] ?? ($key === 'default_back_cover'
                        ? ['crop' => ['focalX' => 0.5, 'focalY' => 0.5, 'zoom' => 1]]
                        : ['placement' => ['position' => 'top-center', 'x' => 0.34, 'y' => 0.04, 'widthPercent' => 0.32, 'opacity' => 1]]), ['asset_id' => $id]) : null;
                }
            }
        }
        $service->publish($settings, (int) $data['base_revision'], $request->user(), $data['reason']);

        return back()->with('success', 'تم نشر نسخة إعدادات Studio كاملة.');
    }

    public function upload(Request $request, StudioSettingsService $service): mixed
    {
        $request->validate(['file' => 'required|file']);
        $asset = $service->upload($request->file('file'), $request->user());

        if ($request->expectsJson()) {
            return response()->json(['data' => ['id' => $asset->id, 'width' => $asset->width, 'height' => $asset->height,
                'bytes' => $asset->bytes, 'preview_url' => route('admin.studio-settings.assets.show', $asset->id)]], 201);
        }

        return back()->with('success', 'تم رفع الأصل بأمان. اختاره في الإعدادات ثم انشر النسخة. معرّفه: '.$asset->id);
    }

    public function preview(string $assetId, StudioSettingsService $service): mixed
    {
        $asset = StudioAsset::findOrFail($assetId);
        abort_unless($service->intact($asset), 503);

        return Storage::disk('studio_private')->response($asset->path, $asset->id.'.png', ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function rollback(Request $request, StudioSettingsRevision $revision, StudioSettingsService $service): mixed
    {
        $data = $request->validate(['base_revision' => 'required|integer|min:0', 'reason' => 'required|string|max:500', 'confirmed' => 'accepted']);
        $service->publish($revision->settings, (int) $data['base_revision'], $request->user(), 'Rollback #'.$revision->id.': '.$data['reason']);

        return back()->with('success', 'تم نشر نسخة جديدة من الإعدادات السابقة.');
    }

    public function grant(Request $request, User $employee, StudioSessionService $sessions): mixed
    {
        $data = $request->validate(['agent_token_id' => 'present|nullable|integer|min:1', 'confirmed' => 'accepted']);
        abort_unless($employee->isAdmin(), 422);
        if (! empty($data['agent_token_id'])) {
            abort_unless($sessions->source($employee, $data['agent_token_id']), 422, 'Select an active non-wildcard Agent token owned by this employee.');
        }
        DB::transaction(function () use ($employee, $data, $sessions): void {
            User::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $old = DB::table('studio_employee_grants')->where('user_id', $employee->id)->first();
            DB::table('studio_employee_grants')->updateOrInsert(['user_id' => $employee->id], ['agent_token_id' => $data['agent_token_id'] ?: null, 'version' => ($old?->version ?? 0) + 1, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            foreach (StudioSession::where('user_id', $employee->id)->whereNull('revoked_at')->get() as $session) {
                $sessions->revoke($session);
            }
        });
        AdminActivityLogger::log('studio.employee.policy.updated', subject: $employee, properties: ['production_enabled' => ! empty($data['agent_token_id'])]);

        return back()->with('success', 'تم تحديث الربط وإلغاء جلسات الموظف القديمة؛ لم تتغير صلاحياته أو توكن Agent.');
    }

    public function revoke(StudioSession $session, StudioSessionService $sessions): mixed
    {
        $sessions->revoke($session);
        AdminActivityLogger::log('studio.session.revoked', subject: $session, properties: ['employee_id' => $session->user_id]);

        return back()->with('success', 'تم إلغاء الجلسة.');
    }
}
