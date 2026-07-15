<?php

namespace App\Jobs;

use App\DTOs\Ai\GenerationRequest;
use App\Models\ProductionProjectAsset;
use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\GenerationInputAssetResolver;
use App\Services\Notifications\AdminNotificationDispatcher;
use App\Services\ProductionStudio\ProductionAutomationLateResultGuard;
use App\Services\ProductionStudio\ProductionAutomationProviderReconciler;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitAiGenerationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $generationJobId) {}

    public function handle(
        AiProviderManager $providers,
        GenerationInputAssetResolver $inputAssets,
        ?ProductionAutomationLateResultGuard $lateResults = null,
        ?ProductionAutomationProviderReconciler $automation = null,
    ): void {
        $lateResults ??= app(ProductionAutomationLateResultGuard::class);
        $automation ??= app(ProductionAutomationProviderReconciler::class);
        $job = SceneGenerationJob::with(['project.order', 'scene', 'model.provider', 'automationRun'])->findOrFail($this->generationJobId);

        try {
            if (! $lateResults->canSubmit($job)) {
                $job->update([
                    'status' => 'cancelled',
                    'safe_failure_code' => 'automation_not_active_for_submission',
                    'safe_failure_summary' => 'Automation run was no longer active for this provider submission.',
                    'failed_at' => now(),
                ]);
                $automation->markFailed($job, 'automation_not_active_for_submission', 'Automation run was no longer active for this provider submission.');

                return;
            }

            $job->update(['status' => 'processing', 'submitted_at' => now(), 'heartbeat_at' => now()]);

            $characterSheet = data_get($job->input_assets_json, 'character_sheet_id')
                ? ProductionProjectAsset::find((int) data_get($job->input_assets_json, 'character_sheet_id'))
                : null;
            $sourceAsset = data_get($job->input_assets_json, 'source_asset_id')
                ? ProductionProjectAsset::findOrFail((int) data_get($job->input_assets_json, 'source_asset_id'))
                : null;
            $resolvedInputs = $job->generation_mode === 'identity_correction' && $sourceAsset
                ? $inputAssets->resolveIdentityCorrectionWithMetadata($job->project, $sourceAsset)['assets']
                : $inputAssets->resolve(
                    $job->project,
                    data_get($job->input_assets_json, 'reference_photo_indices', []),
                    $characterSheet,
                    (bool) data_get($job->input_assets_json, 'character_sheet_first', true),
                );

            $request = new GenerationRequest(
                project: $job->project,
                scene: $job->scene,
                model: $job->model,
                jobType: $job->job_type,
                generationMode: $job->generation_mode,
                prompt: $job->prompt_snapshot,
                negativePrompt: $job->negative_prompt_snapshot ?? '',
                inputAssets: $resolvedInputs,
                options: [
                    'client_request_id' => 'hero-kid-generation-'.$job->id,
                    'quality' => data_get($job->provider_request_json, 'generation_quality', 'medium'),
                    'identity_lock' => (bool) data_get($job->provider_request_json, 'identity_lock', false),
                ],
            );

            $result = $providers
                ->imageProvider($job->model->provider->driver)
                ->submitGeneration($request);

            $job->update([
                'external_request_id' => $result->externalRequestId,
                'provider_request_id' => $result->externalRequestId,
                'external_status_url' => $result->statusUrl,
                'external_response_url' => $result->responseUrl,
                'provider_response_json' => $result->raw,
                'status' => 'processing',
                'heartbeat_at' => now(),
            ]);
            $automation->markSubmitted($job->fresh(['automationAttempt']), $result->externalRequestId);

            ProductionStudio::log($job->project, 'ai_generation.submitted', 'تم إرسال مهمة التوليد إلى مزود الذكاء الاصطناعي.', [
                'job_id' => $job->id,
                'external_request_id' => $result->externalRequestId,
            ]);

            PollAiGenerationJob::dispatch($job->id)->delay(now()->addSeconds(15));
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);
            $automation->markFailed($job, 'image_submission_failed', $this->safeMessage($exception), unknownExposure: true);
        }
    }

    private function failJob(SceneGenerationJob $job, Throwable $exception): void
    {
        $job->update([
            'status' => 'failed',
            'error_message' => $this->safeMessage($exception),
            'failed_at' => now(),
        ]);

        ProductionStudio::log($job->project, 'ai_generation.failed', 'فشلت مهمة توليد صورة.', [
            'job_id' => $job->id,
            'error' => $this->safeMessage($exception),
        ]);

        app(AdminNotificationDispatcher::class)->dispatchSafely('ai.generation.failed', $job->fresh(['project.order', 'scene', 'model']), [
            'dedupe_key' => 'ai.generation.failed:'.$job->id,
            'status' => 'failed',
        ], 'error');
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $exception->getMessage());

        return preg_replace('/[A-Za-z0-9_\-:.]*secret[A-Za-z0-9_\-:.]*/i', '[redacted]', $message ?: '') ?: 'AI generation failed.';
    }
}
