<?php

namespace App\Services\ChildIdentity;

use App\Jobs\GenerateChildIdentityAttemptJob;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChildIdentityAttemptService
{
    public function __construct(
        private readonly ChildIdentitySettings $settings,
        private readonly ChildIdentityAggregateService $aggregates,
        private readonly ChildIdentityEventLogger $events,
    ) {}

    public function create(
        ChildIdentityRequest $identity,
        string $idempotencyKey,
        string $initiatedBy = 'customer',
        ?User $actor = null,
    ): ChildIdentityGenerationAttempt {
        $result = DB::transaction(function () use ($identity, $idempotencyKey, $initiatedBy, $actor): array {
            $locked = ChildIdentityRequest::withTrashed()->lockForUpdate()->findOrFail($identity->id);
            $idempotencyKey = Str::isUuid($idempotencyKey) ? $idempotencyKey : (string) Str::uuid();
            $existing = $locked->attempts()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing) {
                return ['attempt' => $existing, 'error' => null];
            }

            [$provider, $model] = $this->settings->providerAndModel();
            $prompt = $this->promptFor($locked);
            $photos = $locked->validPhotos()->get();
            $attempt = $locked->attempts()->create([
                'attempt_number' => ((int) $locked->attempts()->max('attempt_number')) + 1,
                'idempotency_key' => $idempotencyKey,
                'initiated_by' => $initiatedBy,
                'initiated_by_user_id' => $actor?->id,
                'status' => 'pending',
                'provider' => $provider?->driver ?? 'openai',
                'model' => $model?->code ?? 'gpt-image-2',
                'prompt_version' => $this->settings->promptVersion(),
                'prompt_snapshot' => $prompt,
                'prompt_hash' => hash('sha256', $prompt),
                'input_photos_count' => $photos->count(),
                'image_size' => $this->settings->size(),
                'image_quality' => $this->settings->quality(),
                'request_metadata' => [
                    'provider_id' => $provider?->id,
                    'model_id' => $model?->id,
                    'source' => $initiatedBy,
                    'prompt_source' => filled($locked->prompt_override) ? 'request_override' : 'global_template',
                ],
            ]);

            foreach ($photos as $photo) {
                $inputDisk = $photo->ai_input_path ? ($photo->ai_input_disk ?: $photo->disk) : $photo->disk;
                $inputPath = $photo->ai_input_path ?: $photo->path;
                $inputMimeType = $photo->ai_input_path ? $photo->ai_input_mime_type : $photo->mime_type;
                $inputChecksum = $photo->ai_input_path ? $photo->ai_input_checksum : $photo->checksum;

                $attempt->photos()->attach($photo->id, [
                    'disk' => $inputDisk,
                    'path' => $inputPath,
                    'mime_type' => $inputMimeType,
                    'checksum' => $inputChecksum,
                    'sort_order' => $photo->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $validationError = $this->validationError($locked, $attempt, $initiatedBy);

            if ($validationError) {
                $preserveRequestStatus = in_array(
                    $validationError['code'],
                    ['generation_in_progress', 'customer_limit_reached'],
                    true,
                );
                $targetStatus = $preserveRequestStatus
                    ? $locked->status
                    : $locked->statusDuringGeneration('generation_failed');
                $attempt->forceFill([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'duration_ms' => 0,
                    'cost_usd' => 0,
                    'cost_calculation_method' => 'calculated',
                    'billing_status' => 'not_billable',
                    'error_code' => $validationError['code'],
                    'safe_error_message' => $validationError['message'],
                ])->save();
                $locked->forceFill(['status' => $targetStatus])->save();
                $this->aggregates->recalculate($locked);
                $this->events->record(
                    $locked,
                    'generation.validation_failed',
                    $validationError['message'],
                    ['attempt_number' => $attempt->attempt_number],
                    $attempt,
                    actor: $actor,
                    actorType: $initiatedBy === 'admin' ? 'admin' : 'customer',
                    source: $initiatedBy,
                    fromStatus: $identity->status,
                    toStatus: $targetStatus,
                );

                return ['attempt' => $attempt, 'error' => $validationError['message']];
            }

            $fromStatus = $locked->status;
            $queuedStatus = $locked->statusDuringGeneration('queued');
            $locked->forceFill(['status' => $queuedStatus, 'last_activity_at' => now()])->save();
            $this->aggregates->recalculate($locked);
            $this->events->record(
                $locked,
                'generation.queued',
                'تمت إضافة محاولة إنشاء الهوية إلى قائمة الانتظار.',
                ['attempt_number' => $attempt->attempt_number],
                $attempt,
                actor: $actor,
                actorType: $initiatedBy === 'admin' ? 'admin' : 'customer',
                source: $initiatedBy,
                fromStatus: $fromStatus,
                toStatus: $queuedStatus,
            );

            GenerateChildIdentityAttemptJob::dispatch($attempt->id)->afterCommit();

            return ['attempt' => $attempt, 'error' => null];
        });

        if ($result['error']) {
            throw ValidationException::withMessages(['generation' => $result['error']]);
        }

        return $result['attempt'];
    }

    private function validationError(
        ChildIdentityRequest $identity,
        ChildIdentityGenerationAttempt $attempt,
        string $initiatedBy,
    ): ?array {
        if (! $this->settings->enabled()) {
            return ['code' => 'feature_disabled', 'message' => 'خدمة إنشاء هوية الطفل متوقفة مؤقتًا.'];
        }

        if (! data_get($attempt->request_metadata, 'provider_id') || ! data_get($attempt->request_metadata, 'model_id')) {
            return ['code' => 'provider_unavailable', 'message' => 'خدمة إنشاء الهوية غير مهيأة حاليًا.'];
        }

        if ($attempt->input_photos_count < 2 || $attempt->input_photos_count > 5) {
            return ['code' => 'invalid_photo_count', 'message' => 'يجب رفع من صورتين إلى ٥ صور واضحة للطفل.'];
        }

        if ($initiatedBy === 'customer'
            && $identity->attempts()
                ->where('id', '!=', $attempt->id)
                ->whereIn('status', ['pending', 'processing'])
                ->exists()) {
            return ['code' => 'generation_in_progress', 'message' => 'توجد محاولة إنشاء قيد التنفيذ بالفعل. انتظر نتيجتها أولًا.'];
        }

        if ($initiatedBy === 'customer'
            && $identity->attempts()->whereNotNull('output_storage_path')->count() >= $this->settings->customerSuccessfulLimit()) {
            return ['code' => 'customer_limit_reached', 'message' => 'تم استخدام المحاولتين الناجحتين المتاحتين لهذا الطلب.'];
        }

        return null;
    }

    public function promptFor(ChildIdentityRequest $identity): string
    {
        if (filled($identity->prompt_override)) {
            return trim((string) $identity->prompt_override);
        }

        return trim($this->settings->promptTemplate())."\n\n".
            'Child profile: age range '.$identity->age_range.'; gender '.($identity->gender ?: 'not specified').'.';
    }
}
