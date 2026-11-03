<?php

namespace App\Services\Orders;

use App\DTOs\Ai\GptImageRequest;
use App\Jobs\RegenerateOrderIdentity;
use App\Models\AiModel;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\OrderIdentityRegeneration;
use App\Models\User;
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\AiProviderAvailability;
use App\Services\Ai\GptImageClient;
use App\Services\ChildIdentity\ChildIdentityAggregateService;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Support\AdminActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderIdentityRegenerationService
{
    public function request(Order $order, User $actor, int $previousId, string $prompt): OrderIdentityRegeneration
    {
        app(PreviewRegenerationGuard::class)->authorize($actor);

        return DB::transaction(function () use ($order, $actor, $previousId, $prompt) {
            Order::where(fn ($q) => $q->whereKey($order->id)->orWhere('checkout_group_key', $order->checkoutGroupKey()))->orderBy('id')->lockForUpdate()->get();
            $order = $order->fresh();
            abort_unless($order && $order->story_id && $order->child_identity_request_id, 422);
            $identity = ChildIdentityRequest::lockForUpdate()->findOrFail($order->child_identity_request_id);
            abort_if($identity->orders()->withTrashed()->whereKeyNot($order->id)->exists(), 422, 'الهوية مشتركة مع طلب آخر؛ يلزم فصلها قبل التعديل.');
            $previous = $identity->attempts()->findOrFail($previousId);
            if ($duplicate = OrderIdentityRegeneration::where('order_id', $order->id)->where('previous_attempt_id', $previousId)->first()) {
                return $duplicate;
            }
            app(PreviewRegenerationGuard::class)->check($order);
            abort_unless(config('order_process.identity_enabled') && $identity->attempts()->max('id') === $previous->id
                && (in_array($previous->status, ['succeeded', 'rejected', 'cancelled'], true)
                    || ($previous->status === 'failed' && $previous->billing_status === 'not_billable')), 422, 'المحاولة جارية أو غير مؤكدة، أو تغيّرت منذ فتح الصفحة.');
            $settings = app(ChildIdentitySettings::class);
            [$provider, $model] = $settings->providerAndModel();
            if (! $settings->enabled() || ! $model || ! $provider || ! app(AiProviderAvailability::class)->modelAvailable($model, 'character_sheet')) {
                throw ValidationException::withMessages(['regeneration' => 'مزود توليد الهوية غير متاح.']);
            }
            $size = $settings->size();
            $quality = $settings->quality();
            $estimate = app(AiImagePricingService::class)->calculate($model, $size, $quality, []);
            abort_unless(strtoupper($model->estimated_cost_currency ?: 'USD') === 'USD' && ($estimate['cost_usd'] ?? 0) > 0
                && $estimate['cost_usd'] <= config('order_process.identity_budget_usd', 0), 422, 'تقدير تكلفة الهوية يتجاوز الحد المتاح.');
            $oldApproval = $order->child_identity_approved_attempt_id;
            $identity->update(['approved_attempt_id' => null]);
            $order->update(['child_identity_approved_attempt_id' => null, 'preview_approved_at' => null]);
            $order = $order->fresh();
            $inputs = $this->capture($order, $prompt);
            $attempt = $identity->attempts()->create([
                'attempt_number' => (int) $identity->attempts()->max('attempt_number') + 1,
                'idempotency_key' => (string) Str::uuid(), 'initiated_by' => 'admin', 'initiated_by_user_id' => $actor->id,
                'status' => 'pending', 'provider' => $provider->driver, 'model' => $model->code,
                'prompt_version' => 'order-regeneration-v1', 'prompt_snapshot' => $inputs['prompt'], 'prompt_hash' => hash('sha256', $inputs['prompt']),
                'input_photos_count' => count($inputs['photos']), 'image_size' => $size, 'image_quality' => $quality,
                'request_metadata' => ['source' => 'order_regeneration', 'order_id' => $order->id, 'previous_attempt_id' => $previousId],
            ]);
            $run = OrderIdentityRegeneration::create(['order_id' => $order->id, 'checkout_key' => $order->checkoutGroupKey(),
                'attempt_id' => $attempt->id, 'previous_attempt_id' => $previousId, 'requested_by' => $actor->id,
                'context' => $inputs + ['prompt_override' => $prompt, 'model_id' => $model->id,
                    'model_signature' => $this->modelSignature($model), 'handoff_token' => $this->holdToken($order), 'estimate_usd' => $estimate['cost_usd']],
                'status' => 'queued']);
            $from = $order->status;
            $order->update(['status' => 'pending_team_review']);
            $order->statusLogs()->create(['status_type' => 'order', 'status' => 'pending_team_review', 'notes' => 'إعادة توليد الهوية بطلب الفريق؛ النسخة الجديدة غير معتمدة.']);
            AdminActivityLogger::log('order.identity_regeneration_requested', 'طلب إعادة توليد الهوية ببرومبت المحاولة وصور الطلب الحالية؛ إلغاء الاعتماد السابق.', $order,
                ['attempt_id' => $attempt->id, 'previous_attempt_id' => $previousId, 'previous_approved_attempt_id' => $oldApproval,
                    'prompt_hash' => $attempt->prompt_hash, 'input_photos_count' => count($inputs['photos']), 'from' => $from], $actor);
            RegenerateOrderIdentity::dispatch($run->id)->afterCommit();

            return $run;
        }, 3);
    }

    public function capture(Order $order, string $prompt): array
    {
        $inputs = app(WorkflowInputs::class)->capture($order, ['story_ids' => [], 'photo_limit' => 3, 'prompt' => '{{identity_prompt}}']);
        $prompt = app(OrderChildIdentityPromptService::class)->withCurrentContext($prompt, $order);
        $prompt = preg_replace('/## Secure Original Child Photo References.*?<!-- HERO_KID_IDENTITY_CONTEXT_END -->/s', '<!-- HERO_KID_IDENTITY_CONTEXT_END -->', $prompt);
        abort_if(trim($prompt) === '' || preg_match('/\{\{|signature=|X-Amz-/i', $prompt), 422, 'راجع البرومبت وأزل المتغيرات غير المكتملة وروابط الصور المؤقتة.');
        $inputs['prompt'] = $prompt;

        return $inputs;
    }

    public function previewReady(OrderIdentityRegeneration $run): bool
    {
        try {
            if ($run->status !== 'succeeded' || ! $run->order || $run->attempt->status !== 'succeeded') {
                return false;
            }
            $order = $run->order;
            if ($order->child_identity_request_id !== $run->attempt->child_identity_request_id
                || $order->childIdentityRequest->attempts()->max('id') !== $run->attempt_id) {
                return false;
            }
            // Approval itself is not an input edit; never write to clear it on a GET.
            $copy = clone $order;
            $identity = clone $order->childIdentityRequest;
            $identity->approved_attempt_id = null;
            $copy->child_identity_approved_attempt_id = null;
            $copy->setRelation('childIdentityRequest', $identity);

            return hash_equals($run->context['fingerprint'], $this->capture($copy, $run->context['prompt_override'])['fingerprint']);
        } catch (Throwable) {
            return false;
        }
    }

    public function fail(int $id): void
    {
        DB::transaction(function () use ($id) {
            $run = OrderIdentityRegeneration::lockForUpdate()->find($id);
            if (! $run || ! in_array($run->status, ['queued', 'processing'], true)) {
                return;
            }
            $uncertain = $run->status === 'processing';
            $run->update(['status' => $uncertain ? 'uncertain' : 'blocked', 'error_code' => 'identity_worker_interrupted', 'lease_until' => null]);
            $run->attempt->update(['status' => $uncertain ? 'failed' : 'cancelled', 'billing_status' => $uncertain ? 'unknown' : 'not_billable', 'completed_at' => now()]);
            AutomationActivity::record($run->order, 'identity_regeneration_interrupted', 'توقفت محاولة الهوية؛ يلزم مراجعة النتيجة قبل إعادة التوليد.', ['attempt_id' => $run->attempt_id]);
        });
    }

    private function holdToken(Order $order): string
    {
        $hold = DB::table('order_automation_holds')->where('checkout_key', $order->checkoutGroupKey())->first();

        return hash('sha256', json_encode($hold ? [$hold->user_id, $hold->created_at, $hold->fingerprint] : null));
    }

    private function modelSignature(AiModel $model): string
    {
        return hash('sha256', json_encode([$model->ai_provider_id, $model->code, $model->configuration_json, $model->estimatedCost(), $model->estimated_cost_currency]));
    }

    private function guard(OrderIdentityRegeneration $run): AiModel
    {
        $order = $run->order()->firstOrFail();
        $model = AiModel::with('provider')->findOrFail($run->context['model_id']);
        if (! config('order_process.identity_enabled') || ! app(ChildIdentitySettings::class)->enabled()
            || ! app(AiProviderAvailability::class)->modelAvailable($model, 'character_sheet')
            || ! hash_equals($run->context['model_signature'], $this->modelSignature($model))
            || ! hash_equals($run->context['handoff_token'], $this->holdToken($order))
            || $order->child_identity_request_id !== $run->attempt->child_identity_request_id
            || $order->childIdentityRequest->attempts()->max('id') !== $run->attempt_id
            || ! in_array($order->printing_status, ['not_started', 'ready', 'on_hold', 'not_required'], true)
            || ! in_array($order->shipping_status, ['not_ready', 'ready', 'not_required'], true)
            || ! hash_equals($run->context['fingerprint'], $this->capture($order, $run->context['prompt_override'])['fingerprint'])) {
            throw new RuntimeException('identity_inputs_changed');
        }

        return $model;
    }

    public function generate(int $id): void
    {
        $run = DB::transaction(function () use ($id) {
            $candidate = OrderIdentityRegeneration::findOrFail($id);
            Order::where('checkout_group_key', $candidate->checkout_key)->orderBy('id')->lockForUpdate()->get();
            $run = OrderIdentityRegeneration::lockForUpdate()->findOrFail($id);
            if ($run->status !== 'queued') {
                return null;
            }
            try {
                $this->guard($run);
            } catch (Throwable) {
                $run->update(['status' => 'blocked', 'error_code' => 'identity_inputs_changed']);
                $run->attempt->update(['status' => 'cancelled', 'billing_status' => 'not_billable', 'cost_usd' => 0, 'completed_at' => now()]);
                AutomationActivity::record($run->order, 'identity_regeneration_blocked', 'توقفت إعادة توليد الهوية قبل الإرسال بسبب تغيّر البيانات أو الإعدادات.', ['attempt_id' => $run->attempt_id]);

                return null;
            }
            $run->update(['status' => 'processing', 'lease_until' => now()->addSeconds(270)]);
            $run->attempt->update(['status' => 'processing', 'started_at' => now()]);
            AutomationActivity::record($run->order, 'identity_regeneration_started', 'بدء إعادة توليد الهوية.', ['attempt_id' => $run->attempt_id, 'requested_by' => $run->requested_by]);

            return $run;
        });
        if (! $run) {
            return;
        }
        $submitted = false;
        try {
            $model = $this->guard($run);
            $provider = clone $model->provider;
            $provider->default_timeout_seconds = 180;
            $provider->default_max_retries = 1;
            $images = app(WorkflowInputs::class)->images($run->context);
            $submitted = true;
            $result = app(GptImageClient::class)->generate(new GptImageRequest($provider, $model, $run->attempt->prompt_snapshot, $images,
                $run->attempt->image_size, $run->attempt->image_quality, 'identity-regeneration-'.$run->id, maxAttempts: 1));
            $size = @getimagesizefromstring($result->contents);
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$size['mime'] ?? ''] ?? null;
            if (! $ext || strlen($result->contents) > 15 * 1024 * 1024 || $size[0] * $size[1] > 25000000) {
                throw new RuntimeException('invalid_output');
            }
            $disk = config('media.private_disk', 'local');
            $path = 'child-identities/'.$run->attempt->identityRequest->uuid.'/attempts/'.$run->attempt->attempt_number.'/output.'.$ext;
            $checksum = hash('sha256', $result->contents);
            if (! Storage::disk($disk)->put($path, $result->contents) || ! hash_equals($checksum, hash('sha256', Storage::disk($disk)->get($path)))) {
                throw new RuntimeException('storage_failed');
            }
            $cost = app(AiImagePricingService::class)->calculate($model, $run->attempt->image_size, $run->attempt->image_quality, $result->usage);
            DB::transaction(function () use ($run, $result, $disk, $path, $checksum, $cost) {
                Order::where('checkout_group_key', $run->checkout_key)->orderBy('id')->lockForUpdate()->get();
                $run = OrderIdentityRegeneration::lockForUpdate()->findOrFail($run->id);
                $run->attempt->update(['status' => 'succeeded', 'completed_at' => now(), 'duration_ms' => (int) abs(now()->diffInMilliseconds($run->attempt->started_at)),
                    'output_disk' => $disk, 'output_storage_path' => $path, 'output_checksum' => $checksum, 'api_request_id' => $result->providerRequestId,
                    'cost_usd' => $cost['cost_usd'], 'billing_status' => $cost['billing_status'], 'cost_calculation_method' => $cost['method']]);
                try {
                    $this->guard($run);
                    $status = 'succeeded';
                } catch (Throwable) {
                    $status = 'review';
                }
                $run->update(['status' => $status, 'error_code' => $status === 'review' ? 'identity_inputs_changed' : null, 'lease_until' => null]);
                AutomationActivity::record($run->order, 'identity_regeneration_finished', $status === 'succeeded' ? 'حُفظت الهوية الجديدة في الطلب — غير معتمدة.' : 'حُفظت الهوية للمراجعة بسبب تغيّر البيانات أثناء التنفيذ — غير معتمدة.', ['attempt_id' => $run->attempt_id, 'requested_by' => $run->requested_by]);
            });
            // Persist the paid output before copying it into attachments. A copy failure must not hide it.
            $run->refresh();
            app(OrderIdentityMedia::class)->attach($run->order, $run->attempt->fresh());
            app(ChildIdentityAggregateService::class)->recalculate($run->attempt->identityRequest);
        } catch (Throwable) {
            $run->refresh();
            $saved = (bool) $run->attempt->output_storage_path;
            $run->update(['status' => $saved ? 'review' : ($submitted ? 'uncertain' : 'blocked'), 'error_code' => $saved ? 'identity_attachment_failed' : ($submitted ? 'identity_result_uncertain' : 'identity_inputs_changed'), 'lease_until' => null]);
            if (! $saved) {
                $run->attempt->update(['status' => $submitted ? 'failed' : 'cancelled', 'billing_status' => $submitted ? 'unknown' : 'not_billable', 'completed_at' => now()]);
            }
            AutomationActivity::record($run->order, 'identity_regeneration_review', 'تحتاج محاولة الهوية مراجعة؛ لم تتم إعادة التوليد تلقائيًا.', ['attempt_id' => $run->attempt_id, 'error_code' => $run->error_code]);
        }
    }
}
