<?php

namespace App\Http\Controllers\Api\Studio;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateStudioCover;
use App\Models\StudioCoverGeneration;
use App\Models\User;
use App\Services\RemoteStudio\StudioCoverService;
use App\Services\RemoteStudio\StudioSettingsSchema;
use App\Services\RemoteStudio\StudioSettingsService;
use App\Support\AdminActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudioCoverController extends Controller
{
    public function store(Request $request, StudioCoverService $covers, StudioSettingsService $settings): mixed
    {
        $data = $request->validate([
            'request_id' => 'required|uuid', 'settings_revision' => 'required|integer|min:1', 'order_number' => 'required|string|max:100',
            'production_unit_key' => 'required|string|max:200', 'kind' => 'required|in:story,coloring_book', 'confirmed_billable' => 'accepted',
            'reference_image' => 'nullable|image|mimes:png,jpg,jpeg,webp|max:15360|dimensions:max_width=6000,max_height=6000',
            'context' => 'sometimes|array:title,theme,child_name,child_age,child_gender,dedication',
            'context.title' => 'sometimes|string|max:200', 'context.theme' => 'sometimes|string|max:2000', 'context.child_name' => 'sometimes|string|max:200',
            'context.child_age' => 'sometimes|string|max:40', 'context.child_gender' => 'sometimes|string|max:40', 'context.dedication' => 'sometimes|string|max:2000',
        ]);
        app(StudioSettingsSchema::class)->safeStrings($data['context'] ?? []);
        $unit = $covers->unit($request->user(), $data['order_number'], $data['production_unit_key'], $data['kind']);
        $image = $request->file('reference_image');
        if ($image) {
            $info = getimagesize($image->getRealPath());
            abort_if($info[0] * $info[1] > 25_000_000, 422, 'Reference image exceeds 25 million pixels.');
        }
        $hash = $settings->checksum([(int) $data['settings_revision'], (int) $unit['order_id'], $data['production_unit_key'], $data['kind'], $data['context'] ?? [], $image ? hash_file('sha256', $image->getRealPath()) : null], []);
        $generation = DB::transaction(function () use ($request, $data, $unit, $hash, $image, $settings): StudioCoverGeneration {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $existing = StudioCoverGeneration::where('user_id', $request->user()->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Request ID was already used with different inputs.');

                return $existing;
            }
            $revision = $settings->current();
            abort_unless($revision && $revision->id === (int) $data['settings_revision'], 409, 'Reload current Studio settings.');
            abort_unless(data_get($revision->settings, 'openai_cover.enabled'), 409, 'Cover generation is disabled.');
            abort_unless((int) config('queue.connections.database.retry_after') >= 360
                && (config('queue.connections.database.connection') ?: config('database.default')) === config('database.default'), 503, 'Studio database queue configuration needs review.');
            abort_if(data_get($revision->settings, 'openai_cover.use_identity_image') && ! $image, 422, 'A child reference image is required by the published settings.');
            abort_if(! data_get($revision->settings, 'openai_cover.use_identity_image') && $image, 422, 'Reference images are disabled in this revision; do not upload a child image.');
            abort_if(StudioCoverGeneration::where('user_id', $request->user()->id)->whereIn('status', ['queued', 'running'])->exists(), 409, 'Wait for your current cover generation.');
            $key = 'studio-cover:'.$request->user()->id;
            abort_if(RateLimiter::tooManyAttempts($key, 6), 429, 'Hourly generation limit reached.');
            RateLimiter::hit($key, 3600);
            $id = (string) Str::uuid();
            // Validated private bytes only; no external URL or client filesystem path is accepted.
            $path = $image ? 'covers/'.$id.'/reference' : null;
            if ($path) {
                Storage::disk('studio_private')->put($path, file_get_contents($image->getRealPath()));
            }
            try {
                $generation = StudioCoverGeneration::create(['id' => $id, 'user_id' => $request->user()->id,
                    'personal_access_token_id' => $request->user()->currentAccessToken()->id, 'request_id' => $data['request_id'], 'request_hash' => $hash,
                    'revision_id' => $revision->id, 'order_id' => $unit['order_id'], 'unit_key' => $data['production_unit_key'], 'kind' => $data['kind'],
                    'reference_path' => $path, 'status' => 'queued', 'output' => ['context' => $data['context'] ?? []]]);
                AdminActivityLogger::log('studio.cover.requested', properties: ['generation_id' => $id, 'revision' => $revision->id]);
                // The job insert shares this transaction, so neither a job without
                // its record nor a record without its job can become visible.
                GenerateStudioCover::dispatch($id)->onConnection('database')->onQueue('default')->beforeCommit();

                return $generation;
            } catch (\Throwable $e) {
                if ($path) {
                    Storage::disk('studio_private')->delete($path);
                } throw $e;
            }
        });

        return $this->response($generation, 202);
    }

    public function show(Request $request, StudioCoverGeneration $generation, StudioCoverService $covers): mixed
    {
        $covers->authorizeGeneration($generation, $request->user());

        return $this->response($generation);
    }

    public function image(Request $request, StudioCoverGeneration $generation, StudioCoverService $covers): mixed
    {
        $covers->authorizeGeneration($generation, $request->user());
        abort_unless($generation->status === 'completed' && $generation->output_path, 409);
        abort_if($generation->created_at->addHours((int) config('remote_studio.cover_result_hours'))->isPast(), 410, 'Cover download expired.');

        return Storage::disk('studio_private')->download($generation->output_path, $generation->id.'.'.pathinfo($generation->output_path, PATHINFO_EXTENSION), [
            'Content-Type' => $generation->output['mime_type'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'X-Checksum-SHA256' => $generation->output['sha256'],
        ]);
    }

    private function response(StudioCoverGeneration $generation, int $status = 200): mixed
    {
        return response()->json(['data' => ['id' => $generation->id, 'status' => $generation->status, 'error_code' => $generation->error_code,
            'message' => $generation->status === 'failed' ? 'تعذر توليد الغلاف. لا يُعاد الإرسال تلقائيًا لاحتمال احتساب التكلفة؛ راجع الحالة قبل محاولة جديدة.' : null,
            'settings_revision' => (string) $generation->revision_id,
            'result' => $generation->status === 'completed' ? [...$generation->output, 'download_url' => '/api/studio/covers/'.$generation->id.'/image'] : null]])->header('Cache-Control', 'no-store')->setStatusCode($status);
    }
}
