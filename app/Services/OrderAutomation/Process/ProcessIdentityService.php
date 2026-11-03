<?php

namespace App\Services\OrderAutomation\Process;

use App\DTOs\Ai\GptImageRequest;
use App\Exceptions\GptImageException;
use App\Jobs\GenerateProcessIdentity;
use App\Models\AiModel;
use App\Models\ChildIdentityRequest;
use App\Models\OrderAutomationRun;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProcessRun;
use App\Models\OrderProcessStep;
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\AiProviderAvailability;
use App\Services\Ai\GptImageClient;
use App\Services\ChildIdentity\ChildIdentityAggregateService;
use App\Services\ChildIdentity\ChildIdentityApprovalService;
use App\Services\ChildIdentity\ChildIdentitySettings;
use App\Services\OrderAutomation\WorkflowInputs;
use App\Services\Orders\AutomationActivity;
use App\Services\Orders\OrderIdentityMedia;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ProcessIdentityService
{
    // Existing production status key; keep its spelling to preserve saved references.
    public const APPROVED_ORDER_STATUS = 'costomer_confirmed';

    public function prepare(OrderProcessRun $run, OrderProcessStep $step, array $config): void
    {
        if (! config('order_process.identity_enabled')) {
            throw new RuntimeException('identity_disabled');
        }
        $orders = app(ProcessOrderContext::class)->orders($run->checkout_key, true)->filter(fn ($o) => $o->story_id);
        $selected = $run->revision->definition['conditions']['story_ids'];
        if ($orders->isEmpty() || ($selected && $orders->contains(fn ($o) => ! in_array($o->story_id, $selected, true)))) {
            // A checkout-level message must not claim to preview an omitted sibling's identity.
            throw new RuntimeException('identity_scope_incomplete');
        }
        $settings = app(ChildIdentitySettings::class);
        [$provider, $model] = $settings->providerAndModel();
        if (! $settings->enabled() || ! $model || ! $provider || ! app(AiProviderAvailability::class)->modelAvailable($model, 'character_sheet')) {
            throw new RuntimeException('identity_provider_unavailable');
        }
        $size = $settings->size();
        $quality = $settings->quality();
        $estimate = app(AiImagePricingService::class)->calculate($model, $size, $quality, []);
        $budget = min((float) $config['budget_usd'], (float) config('order_process.identity_budget_usd', 0));
        if (strtoupper((string) ($model->estimated_cost_currency ?: 'USD')) !== 'USD' || ! $estimate['cost_usd'] || (float) $estimate['cost_usd'] <= 0 || $orders->count() * (float) $estimate['cost_usd'] > $budget) {
            throw new RuntimeException('identity_budget_required');
        }
        foreach ($orders as $order) {
            if (OrderProcessIdentity::where('order_id', $order->id)->exists() || OrderAutomationRun::where('live_key', 'story-identity:'.$order->id)->exists()) {
                throw new RuntimeException('identity_already_claimed');
            }
            $inputs = app(WorkflowInputs::class)->capture($order, $this->inputDefinition());
            $identity = $order->childIdentityRequest;
            if ($identity) {
                $identity = ChildIdentityRequest::withTrashed()->lockForUpdate()->findOrFail($identity->id);
                if ($identity->trashed() || $identity->approved_attempt_id || $identity->orders()->whereKeyNot($order->id)->exists()
                    || $identity->attempts()->where(fn ($q) => $q->whereIn('status', ['pending', 'processing'])->orWhereNotNull('output_storage_path'))->exists()) {
                    throw new RuntimeException('identity_existing_review');
                }
            } else {
                $identity = ChildIdentityRequest::create([
                    'uuid' => (string) Str::uuid(), 'user_id' => $order->user_id,
                    'resume_token_hash' => hash('sha256', Str::random(80)),
                    'parent_name' => $order->parent_name ?: 'عميل HeroKid', 'parent_phone' => $run->context['phone'],
                    'child_name' => $order->child_name, 'child_age' => is_numeric($order->child_age) ? (int) $order->child_age : null,
                    'age_range' => (string) ($order->child_age ?: $order->story->age_range ?: 'غير محدد'),
                    'gender' => in_array($order->child_gender, ['boy', 'girl'], true) ? $order->child_gender : null,
                    'status' => 'converted', 'selected_story_id' => $order->story_id, 'converted_order_id' => $order->id,
                    // Do not invent a new customer consent event.
                    'converted_at' => now(), 'last_activity_at' => now(),
                ]);
                $order->update(['child_identity_request_id' => $identity->id]);
            }
            $attempt = $identity->attempts()->create([
                'attempt_number' => ((int) $identity->attempts()->max('attempt_number')) + 1,
                'idempotency_key' => (string) Str::uuid(), 'initiated_by' => 'automation',
                'initiated_by_user_id' => $run->context['actor_id'], 'status' => 'pending',
                'provider' => $provider->driver, 'model' => $model->code, 'prompt_version' => 'order-process-identity-v1',
                'prompt_snapshot' => $inputs['prompt'], 'prompt_hash' => hash('sha256', $inputs['prompt']),
                'input_photos_count' => count($inputs['photos']), 'image_size' => $size, 'image_quality' => $quality,
                'request_metadata' => ['source' => 'order_process', 'run_id' => $run->id, 'order_id' => $order->id,
                    'provider_id' => $provider->id, 'model_id' => $model->id, 'estimated_cost_usd' => $estimate['cost_usd']],
            ]);
            $task = OrderProcessIdentity::create(['run_id' => $run->id, 'step_id' => $step->id, 'order_id' => $order->id, 'attempt_id' => $attempt->id,
                'context' => $inputs + ['provider_id' => $provider->id, 'model_id' => $model->id, 'model_code' => $model->code,
                    'model_pricing' => hash('sha256', json_encode([$model->configuration_json, $model->estimatedCost(), $model->estimated_cost_currency])),
                    'estimate' => $estimate['cost_usd']]]);
            app(ChildIdentityAggregateService::class)->recalculate($identity);
            GenerateProcessIdentity::dispatch($task->id)->afterCommit();
        }
        $run->context = app(ProcessOrderContext::class)->snapshot(app(ProcessOrderContext::class)->orders($run->checkout_key, true)) + ['actor_id' => $run->context['actor_id']];
        $run->update(['status' => 'waiting_identity', 'next_at' => now()->addMinute()]);
        $step->update(['status' => 'waiting', 'started_at' => now()]);
    }

    public function generate(int $id): void
    {
        $task = DB::transaction(function () use ($id) {
            $candidate = OrderProcessIdentity::findOrFail($id);
            // Always lock run before task: runtime/response/worker lock ordering agrees.
            $run = OrderProcessRun::lockForUpdate()->findOrFail($candidate->run_id);
            $task = OrderProcessIdentity::lockForUpdate()->findOrFail($id);
            if ($task->status !== 'queued') {
                return null;
            }
            try {
                if (! config('order_process.identity_enabled') || ! app(ProcessLivePolicy::class)->enabled() || ! $run->automation->enabled || $run->status !== 'waiting_identity') {
                    throw new RuntimeException('identity_paused');
                }
                app(ProcessRuntime::class)->guard($run);
                $fresh = app(WorkflowInputs::class)->capture($task->order, $this->inputDefinition());
                if (! hash_equals($task->context['fingerprint'], $fresh['fingerprint'])) {
                    throw new RuntimeException('inputs_changed');
                }
                $this->model($task);
                $task->update(['status' => 'processing', 'lease_until' => now()->addSeconds(270)]);
                $task->attempt->update(['status' => 'processing', 'started_at' => now()]);

                return $task;
            } catch (Throwable) {
                $task->update(['status' => 'blocked', 'error_code' => 'identity_preflight_failed']);
                $task->attempt->update(['status' => 'cancelled', 'completed_at' => now(), 'billing_status' => 'not_billable', 'cost_usd' => 0]);

                return null;
            }
        });
        if (! $task) {
            return;
        }
        $submitted = false;
        try {
            $model = $this->model($task);
            $provider = clone $model->provider;
            $provider->default_timeout_seconds = 180;
            $provider->default_max_retries = 1;
            $attempt = $task->attempt;
            $inputs = app(WorkflowInputs::class)->images($task->context);
            $submitted = true;
            $result = app(GptImageClient::class)->generate(new GptImageRequest($provider, $model, $attempt->prompt_snapshot, $inputs,
                $attempt->image_size, $attempt->image_quality, 'order-process-identity-'.$task->id, maxAttempts: 1));
            $image = @getimagesizefromstring($result->contents);
            if (strlen($result->contents) > 15 * 1024 * 1024 || ! $image || $image[0] * $image[1] > 25000000 || ! in_array($image['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
                throw new RuntimeException('invalid_identity_output');
            }
            $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$image['mime']];
            $disk = config('media.private_disk', 'local');
            $path = 'child-identities/'.$attempt->identityRequest->uuid.'/attempts/'.$attempt->attempt_number.'/output.'.$extension;
            if (! Storage::disk($disk)->put($path, $result->contents)) {
                throw new RuntimeException('identity_storage_failed');
            }
            $cost = app(AiImagePricingService::class)->calculate($model, $attempt->image_size, $attempt->image_quality, $result->usage);
            DB::transaction(function () use ($task, $result, $disk, $path, $image, $cost) {
                $run = OrderProcessRun::lockForUpdate()->findOrFail($task->run_id);
                $task = OrderProcessIdentity::lockForUpdate()->findOrFail($task->id);
                $task->attempt->update(['status' => 'succeeded', 'completed_at' => now(), 'api_request_id' => $result->providerRequestId,
                    'output_disk' => $disk, 'output_storage_path' => $path, 'output_checksum' => hash('sha256', $result->contents),
                    'cost_usd' => $cost['cost_usd'], 'billing_status' => $cost['billing_status'], 'cost_calculation_method' => $cost['method'],
                    'response_metadata' => ['output_mime_type' => $image['mime'], 'usage' => $result->usage]]);
                $valid = $task->status === 'processing' && $run->status === 'waiting_identity';
                try {
                    app(ProcessRuntime::class)->guard($run);
                    $current = app(WorkflowInputs::class)->capture($task->order, $this->inputDefinition());
                    $valid = $valid && hash_equals($task->context['fingerprint'], $current['fingerprint']);
                } catch (Throwable) {
                    $valid = false;
                }
                // Preserve late output/cost evidence, but never silently resume an uncertain run.
                $task->update(['status' => $valid ? 'succeeded' : 'uncertain', 'lease_until' => null]);
                app(ChildIdentityAggregateService::class)->recalculate($task->attempt->identityRequest);
            });
            try {
                app(OrderIdentityMedia::class)->attach($task->order, $task->attempt->fresh());
            } catch (Throwable) {
                AutomationActivity::record($task->order, 'identity_attachment_review', 'الهوية محفوظة في سجلها؛ تعذر إنشاء نسخة المرفق وتحتاج مراجعة.', ['attempt_id' => $task->attempt_id]);
            }
        } catch (Throwable $error) {
            // Preserve diagnostics without storing provider bodies, prompts or customer data.
            // A diagnostic receipt does not establish billing or permit an automatic retry.
            $diagnostics = $this->failureDiagnostics($error);
            DB::transaction(function () use ($task, $submitted, $diagnostics) {
                OrderProcessRun::lockForUpdate()->findOrFail($task->run_id);
                $task = OrderProcessIdentity::lockForUpdate()->findOrFail($task->id);
                $task->update(['status' => $submitted ? 'uncertain' : 'blocked', 'error_code' => $submitted ? 'identity_result_uncertain' : 'identity_inputs_failed']);
                $task->attempt->update(['status' => 'failed', 'completed_at' => now(), 'billing_status' => $submitted ? 'unknown' : 'not_billable',
                    'cost_usd' => $submitted ? null : 0, 'cost_calculation_method' => $submitted ? 'unknown' : 'calculated', 'error_code' => $task->error_code,
                    'api_request_id' => $diagnostics['provider_request_id'],
                    'response_metadata' => array_merge($task->attempt->response_metadata ?? [], ['failure_diagnostics' => $diagnostics])]);
            });
        }
    }

    private function failureDiagnostics(Throwable $error): array
    {
        $safeIdentifier = static fn (?string $value): ?string => $value !== null
            && preg_match('/\A[A-Za-z0-9_.:-]{1,120}\z/D', $value) === 1 ? $value : null;
        $providerError = $error instanceof GptImageException ? $error : null;
        $previous = $providerError?->getPrevious();
        $httpStatus = $previous instanceof RequestException ? $previous->response->status() : null;

        return [
            'provider_error_code' => $safeIdentifier($providerError?->errorCode),
            'provider_request_id' => $safeIdentifier($providerError?->providerRequestId),
            'provider_http_status' => $httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599 ? $httpStatus : null,
        ];
    }

    public function ready(OrderProcessRun $run): bool
    {
        $tasks = OrderProcessIdentity::where('run_id', $run->id)->get();
        foreach ($tasks as $task) {
            if ($task->status === 'queued' && $task->created_at->lt(now()->subHour())) {
                $task->update(['status' => 'blocked', 'error_code' => 'identity_worker_missing']);
            }
            if ($task->status === 'processing' && $task->lease_until?->isPast()) {
                $task->update(['status' => 'uncertain', 'error_code' => 'identity_receipt_missing']);
                $task->attempt->update(['billing_status' => 'unknown', 'cost_usd' => null]);
            }
        }
        if ($tasks->contains(fn ($t) => in_array($t->status, ['blocked', 'uncertain'], true))) {
            throw new RuntimeException('identity_requires_review');
        }
        $ready = $tasks->isNotEmpty() && $tasks->every(fn ($t) => $t->status === 'succeeded');
        if ($ready) {
            foreach ($tasks as $task) {
                try {
                    $current = app(WorkflowInputs::class)->capture($task->order, $this->inputDefinition());
                    if (! hash_equals($task->context['fingerprint'], $current['fingerprint'])) {
                        throw new RuntimeException('inputs_changed');
                    }
                    $attempt = $task->attempt;
                    $disk = Storage::disk($attempt->output_disk);
                    if ($attempt->status !== 'succeeded' || ! $disk->exists($attempt->output_storage_path)
                        || ! hash_equals($attempt->output_checksum, hash('sha256', $disk->get($attempt->output_storage_path)))) {
                        throw new RuntimeException('output_changed');
                    }
                } catch (Throwable) {
                    throw new RuntimeException('identity_requires_review');
                }
            }
        }

        return $ready;
    }

    public function approve(OrderProcessRun $run, array $config, string $approvalText = 'تأكيد الهوية'): void
    {
        $response = $run->steps()->where('node_id', $config['response_node'])->first();
        if (! $response) {
            throw new RuntimeException('identity_approval_missing');
        }
        $decision = DB::table('order_process_responses')->where('run_id', $run->id)->where('step_id', $response->id)->first();
        $wait = collect($run->revision->definition['nodes'])->firstWhere('id', $config['response_node']);
        $approval = collect($wait['config']['cases'])->firstWhere('text', $approvalText);
        if (! $decision || ! $approval || $decision->decision !== $approval['key'] || ! $this->ready($run)) {
            throw new RuntimeException('identity_approval_missing');
        }
        foreach (OrderProcessIdentity::where('run_id', $run->id)->get() as $task) {
            $identity = ChildIdentityRequest::lockForUpdate()->findOrFail($task->attempt->child_identity_request_id);
            if ($identity->orders()->whereKeyNot($task->order_id)->exists() || ($identity->approved_attempt_id && $identity->approved_attempt_id !== $task->attempt_id)) {
                throw new RuntimeException('identity_existing_review');
            }
            $attempt = $task->attempt;
            $disk = Storage::disk($attempt->output_disk);
            if (! $disk->exists($attempt->output_storage_path) || ! hash_equals($attempt->output_checksum, hash('sha256', $disk->get($attempt->output_storage_path)))) {
                throw new RuntimeException('identity_requires_review');
            }
            app(ChildIdentityApprovalService::class)->approve($identity, $task->attempt, source: 'robodesk_process');
            $order = $task->order;
            if ($order->status !== self::APPROVED_ORDER_STATUS) {
                $order->update(['status' => self::APPROVED_ORDER_STATUS]);
                AutomationActivity::record($order, 'identity_approved', 'اعتماد الهوية بناءً على تأكيد العميل وتغيير الحالة إلى هوية مؤكدة وجاهزة للتوليد', ['source_id' => $task->attempt_id, 'stage' => 'اعتماد هوية الطفل', 'story_title' => $order->story?->title, 'child_name' => $order->child_name]);
                $order->statusLogs()->create(['status_type' => 'order', 'status' => self::APPROVED_ORDER_STATUS,
                    'notes' => 'تأكيد هوية العميل عبر مسار العمليات #'.$run->id]);
            }
        }
        $run->context = app(ProcessOrderContext::class)->snapshot(app(ProcessOrderContext::class)->orders($run->checkout_key, true)) + ['actor_id' => $run->context['actor_id']];
    }

    public function previewUrl(OrderProcessRun $run): string
    {
        if (! $this->ready($run)) {
            throw new RuntimeException('identity_requires_review');
        }

        return URL::temporarySignedRoute('order-process.identity-preview', now()->addDays(35), ['run' => $run->id]);
    }

    private function model(OrderProcessIdentity $task): AiModel
    {
        $model = AiModel::with('provider')->find($task->context['model_id']);
        if (! $model || $model->code !== $task->context['model_code'] || $model->ai_provider_id !== $task->context['provider_id']
            || $model->provider?->driver !== 'openai' || ! app(AiProviderAvailability::class)->modelAvailable($model, 'character_sheet')
            || hash('sha256', json_encode([$model->configuration_json, $model->estimatedCost(), $model->estimated_cost_currency])) !== $task->context['model_pricing']) {
            throw new RuntimeException('identity_provider_changed');
        }

        return $model;
    }

    private function inputDefinition(): array
    {
        return ['story_ids' => [], 'photo_limit' => 3, 'prompt' => '{{identity_prompt}}'];
    }
}
