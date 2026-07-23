<?php

namespace App\Jobs;

use App\DTOs\Ai\GptImageRequest;
use App\Exceptions\GptImageException;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\GptImageClient;
use App\Services\ChildIdentity\ChildIdentityAggregateService;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateChildIdentityAttemptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 420;

    public function __construct(public readonly int $attemptId) {}

    public function handle(
        GptImageClient $client,
        AiImagePricingService $pricing,
        ChildIdentityAggregateService $aggregates,
        ChildIdentityEventLogger $events,
    ): void {
        $attempt = DB::transaction(function () use ($aggregates, $events): ?ChildIdentityGenerationAttempt {
            $attempt = ChildIdentityGenerationAttempt::query()->lockForUpdate()->find($this->attemptId);

            if (! $attempt || $attempt->status !== 'pending') {
                return null;
            }

            $identity = ChildIdentityRequest::withTrashed()->lockForUpdate()->find($attempt->child_identity_request_id);

            if (! $identity) {
                return null;
            }

            if ($identity->trashed()) {
                $fromStatus = $identity->status;
                $targetStatus = $identity->statusDuringGeneration('generation_failed');
                $attempt->forceFill([
                    'status' => 'cancelled',
                    'completed_at' => now(),
                    'duration_ms' => 0,
                    'cost_usd' => 0,
                    'cost_calculation_method' => 'calculated',
                    'billing_status' => 'not_billable',
                    'error_code' => 'request_deleted',
                    'safe_error_message' => 'أُلغيت المحاولة لأن طلب الهوية نُقل إلى سلة المحذوفات قبل بدء التوليد.',
                ])->save();
                $identity->forceFill(['status' => $targetStatus])->save();
                $aggregates->recalculate($identity);
                $events->record(
                    $identity,
                    'generation.cancelled',
                    $attempt->safe_error_message,
                    ['attempt_number' => $attempt->attempt_number],
                    $attempt,
                    actorType: 'system',
                    source: 'queue',
                    fromStatus: $fromStatus,
                    toStatus: $targetStatus,
                );

                return null;
            }

            $attempt->forceFill(['status' => 'processing', 'started_at' => now()])->save();
            $identity->forceFill(['status' => $identity->statusDuringGeneration('processing')])->save();

            return $attempt;
        });

        if (! $attempt) {
            return;
        }

        $identity = $attempt->identityRequest()->withTrashed()->firstOrFail();
        $started = hrtime(true);

        try {
            $providerId = data_get($attempt->request_metadata, 'provider_id');
            $modelId = data_get($attempt->request_metadata, 'model_id');
            $provider = AiProvider::query()->findOrFail($providerId);
            $model = AiModel::query()->findOrFail($modelId);
            $inputs = $attempt->photos()
                ->orderByPivot('sort_order')
                ->get()
                ->map(function ($photo): string {
                    $contents = Storage::disk($photo->pivot->disk)->get($photo->pivot->path);

                    return 'data:'.$photo->mime_type.';base64,'.base64_encode($contents);
                })
                ->all();
            $result = $client->generate(new GptImageRequest(
                provider: $provider,
                model: $model,
                prompt: $attempt->prompt_snapshot,
                inputImages: $inputs,
                size: $attempt->image_size,
                quality: $attempt->image_quality,
                clientRequestId: 'child-identity-'.$identity->uuid.'-'.$attempt->attempt_number,
            ));
            $outputPath = 'child-identities/'.$identity->uuid.'/attempts/'.$attempt->attempt_number.'/output.'.$result->extension;
            Storage::disk('local')->put($outputPath, $result->contents);
            $cost = $pricing->calculate($model, $attempt->image_size, $attempt->image_quality, $result->usage);
            $duration = (int) round((hrtime(true) - $started) / 1_000_000);

            DB::transaction(function () use ($attempt, $identity, $result, $outputPath, $cost, $duration, $aggregates, $events): void {
                $locked = ChildIdentityGenerationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $locked->forceFill([
                    'status' => 'succeeded',
                    'api_request_id' => $result->providerRequestId,
                    'completed_at' => now(),
                    'duration_ms' => $duration,
                    'output_disk' => 'local',
                    'output_storage_path' => $outputPath,
                    'output_checksum' => hash('sha256', $result->contents),
                    'cost_usd' => $cost['cost_usd'],
                    'usd_to_egp_rate' => null,
                    'cost_egp' => null,
                    'cost_calculation_method' => $cost['method'],
                    'billing_status' => $cost['billing_status'],
                    'response_metadata' => array_merge($result->metadata, [
                        'output_mime_type' => $result->mimeType,
                        'output_extension' => $result->extension,
                        'usage' => $result->usage,
                        'pricing_rule' => $cost['rule'],
                    ]),
                ])->save();
                $request = $identity->fresh();
                $fromStatus = $request->status;
                $autoApprove = $locked->initiated_by === 'customer' && ! $request->approved_attempt_id;
                $targetStatus = $request->statusDuringGeneration($autoApprove ? 'approved' : 'generated');
                $request->forceFill([
                    'status' => $targetStatus,
                    'approved_attempt_id' => $autoApprove ? $locked->id : $request->approved_attempt_id,
                ])->save();
                $aggregates->recalculate($request);
                $events->record(
                    $request,
                    'generation.succeeded',
                    'تم إنشاء هوية طفل قابلة للاستخدام.',
                    [
                        'attempt_number' => $locked->attempt_number,
                        'billing_status' => $locked->billing_status,
                        'auto_approved' => $autoApprove,
                    ],
                    $locked,
                    actorType: $locked->initiated_by === 'admin' ? 'admin' : 'customer',
                    source: 'queue',
                    fromStatus: $fromStatus,
                    toStatus: $targetStatus,
                );
            });
        } catch (\Throwable $exception) {
            $duration = (int) round((hrtime(true) - $started) / 1_000_000);
            $safe = $exception instanceof GptImageException
                ? $exception->getMessage()
                : 'تعذر إنشاء الهوية حاليًا. يمكنك إعادة المحاولة.';
            $code = $exception instanceof GptImageException ? $exception->errorCode : 'generation_failed';
            $mayBeBilled = $exception instanceof GptImageException && $exception->providerMayHaveBilled;

            DB::transaction(function () use ($attempt, $identity, $exception, $safe, $code, $mayBeBilled, $duration, $aggregates, $events): void {
                $locked = ChildIdentityGenerationAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
                $locked->forceFill([
                    'status' => 'failed',
                    'api_request_id' => $exception instanceof GptImageException ? $exception->providerRequestId : null,
                    'completed_at' => now(),
                    'duration_ms' => $duration,
                    'cost_usd' => $mayBeBilled ? null : 0,
                    'cost_calculation_method' => $mayBeBilled ? 'unknown' : 'calculated',
                    'billing_status' => $mayBeBilled ? 'unknown' : 'not_billable',
                    'error_code' => Str::limit((string) $code, 100, ''),
                    'safe_error_message' => $safe,
                    'technical_error' => $this->safeTechnicalError($exception),
                ])->save();
                $request = $identity->fresh();
                $fromStatus = $request->status;
                $targetStatus = $request->statusDuringGeneration('generation_failed');
                $request->forceFill(['status' => $targetStatus])->save();
                $aggregates->recalculate($request);
                $events->record(
                    $request,
                    'generation.failed',
                    $safe,
                    ['attempt_number' => $locked->attempt_number, 'error_code' => $locked->error_code],
                    $locked,
                    actorType: $locked->initiated_by === 'admin' ? 'admin' : 'customer',
                    source: 'queue',
                    fromStatus: $fromStatus,
                    toStatus: $targetStatus,
                );
            });

            if (! $exception instanceof GptImageException || $exception->providerMayHaveBilled) {
                throw $exception;
            }
        }
    }

    private function safeTechnicalError(\Throwable $exception): string
    {
        $message = $exception::class.': '.$exception->getMessage();
        $message = str_replace(
            array_filter([base_path(), storage_path(), config('filesystems.disks.local.root')]),
            '[private-path]',
            $message,
        );
        $message = preg_replace(
            [
                '#child-identities/[A-Za-z0-9_./-]+#',
                '/Bearer\s+[A-Za-z0-9._-]+/i',
                '/sk-[A-Za-z0-9_-]+/i',
            ],
            ['[private-media]', 'Bearer [redacted]', '[redacted-api-key]'],
            $message,
        ) ?: $exception::class;

        return Str::limit($message, 4000, '');
    }
}
