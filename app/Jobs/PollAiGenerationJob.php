<?php

namespace App\Jobs;

use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderManager;
use App\Services\ProductionStudio\IdentityReviewDispatcher;
use App\Services\ProductionStudio\ProductionAutomationLateResultGuard;
use App\Services\ProductionStudio\ProductionAutomationProviderReconciler;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PollAiGenerationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $generationJobId) {}

    public function handle(
        AiProviderManager $providers,
        ?IdentityReviewDispatcher $identityReviews = null,
        ?ProductionAutomationLateResultGuard $lateResults = null,
        ?ProductionAutomationProviderReconciler $automation = null,
    ): void {
        $automation ??= app(ProductionAutomationProviderReconciler::class);
        $job = SceneGenerationJob::with(['project', 'scene', 'model.provider', 'automationRun', 'automationStep', 'automationAttempt'])->findOrFail($this->generationJobId);

        if (! in_array($job->status, ['processing', 'queued'], true)) {
            return;
        }

        try {
            $provider = $providers->imageProvider($job->model->provider->driver);
            $status = $provider->pollGeneration($job->external_request_id, $job->external_status_url, $job->external_response_url);

            $job->provider_response_json = $status->raw;
            $job->heartbeat_at = now();

            if ($status->isFailed()) {
                $job->status = 'failed';
                $job->error_message = $this->safeMessage($status->errorMessage ?: 'AI provider reported a failed generation.');
                $job->failed_at = now();
                $job->save();
                $automation->markFailed($job, 'provider_reported_failed_generation', $job->error_message, unknownExposure: true);

                return;
            }

            if (! $status->isCompleted()) {
                $job->status = 'processing';
                $job->save();
                self::dispatch($job->id)->delay(now()->addSeconds(20));

                return;
            }

            if ($lateResults && ! $lateResults->canApplyResult($job)) {
                $job->update([
                    'status' => 'completed_late',
                    'provider_response_json' => $status->raw,
                    'actual_cost' => $status->actualCost ?: $job->estimated_cost,
                    'cost_source' => $status->actualCost ? 'provider_actual' : 'estimate_fallback',
                    'safe_failure_code' => 'late_provider_result_not_applied',
                    'safe_failure_summary' => 'Provider result arrived after the automation run or attempt was no longer current.',
                    'completed_at' => now(),
                    'heartbeat_at' => now(),
                ]);

                ProductionStudio::log($job->project, 'ai_generation.late_result_recorded', 'وصلت نتيجة مزود متأخرة وتم حفظها للتدقيق بدون اعتمادها.', [
                    'job_id' => $job->id,
                    'external_request_id' => $job->external_request_id,
                ]);
                $automation->markCompleted($job->fresh(['automationAttempt']), $job->actual_cost, $job->provider_request_id, late: true);

                return;
            }

            $asset = $provider->downloadOutput($status);
            $dimensions = @getimagesizefromstring($asset->contents) ?: null;

            if ($job->job_type === 'scene_image' && $dimensions && $dimensions[0] <= $dimensions[1]) {
                throw new \RuntimeException('رفض النظام صورة المشهد لأنها عمودية. يجب أن تكون صورة المشهد أفقية عريضة وتعرض الحدث والبيئة، وليست صورة شخصية للطفل.');
            }

            $path = 'production-studio/projects/'.$job->production_project_id.'/generated/'.uniqid($job->job_type.'_', true).'.'.$asset->extension;

            Storage::disk('local')->put($path, $asset->contents);

            $version = ((int) $job->project->assets()
                ->where('asset_type', $job->job_type)
                ->when($job->production_scene_id, fn ($query) => $query->where('production_scene_id', $job->production_scene_id))
                ->max('version_number')) + 1;

            $createdAsset = $job->project->assets()->create([
                'production_scene_id' => $job->production_scene_id,
                'scene_generation_job_id' => $job->id,
                'asset_type' => $job->job_type,
                'version_number' => $version,
                'label' => $this->labelFor($job, $version),
                'status' => 'under_review',
                'file_path' => $path,
                'metadata_json' => $asset->metadata + [
                    'provider' => $job->model->provider->driver,
                    'model' => $job->model->code,
                    'mime_type' => $asset->mimeType,
                    'width' => $dimensions[0] ?? null,
                    'height' => $dimensions[1] ?? null,
                ],
                'production_automation_run_id' => $job->production_automation_run_id,
                'production_automation_step_id' => $job->production_automation_step_id,
                'production_automation_attempt_id' => $job->production_automation_attempt_id,
                'input_fingerprint' => $job->input_fingerprint,
                'output_fingerprint' => $job->output_fingerprint ?: $job->input_fingerprint,
                'validation_policy_version' => $job->validation_policy_version,
                'uploaded_by_user_id' => $job->initiated_by_user_id,
            ]);

            $job->update([
                'status' => 'completed',
                'output_asset_path' => $path,
                'output_metadata_json' => ['asset_id' => $createdAsset->id] + $asset->metadata + [
                    'width' => $dimensions[0] ?? null,
                    'height' => $dimensions[1] ?? null,
                ],
                'actual_cost' => $status->actualCost ?: $job->estimated_cost,
                'cost_source' => $status->actualCost ? 'provider_actual' : 'estimate_fallback',
                'completed_at' => now(),
                'heartbeat_at' => now(),
            ]);
            $automation->markCompleted($job->fresh(['automationAttempt']), $job->actual_cost, $job->provider_request_id);

            $identityReviews?->dispatchFor($createdAsset);

            ProductionStudio::log($job->project, 'ai_generation.completed', 'اكتملت مهمة توليد صورة وأصبحت بانتظار المراجعة.', [
                'job_id' => $job->id,
                'asset_id' => $createdAsset->id,
                'asset_type' => $createdAsset->asset_type,
            ]);
        } catch (Throwable $exception) {
            $job->update([
                'status' => 'failed',
                'error_message' => $this->safeMessage($exception->getMessage()),
                'failed_at' => now(),
            ]);
            $automation->markFailed($job, 'image_poll_failed', $this->safeMessage($exception->getMessage()), unknownExposure: true);
        }
    }

    private function labelFor(SceneGenerationJob $job, int $version): string
    {
        return match ($job->job_type) {
            'character_sheet' => 'Approved Child Reference Illustration v'.$version,
            'cover_image' => 'Cover Image v'.$version,
            default => $job->scene
                ? 'Scene '.$job->scene->scene_number.': '.($job->scene->title ?: 'Untitled').' — v'.$version
                : 'Scene Image v'.$version,
        };
    }

    private function safeMessage(string $message): string
    {
        $message = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $message);

        return preg_replace('/[A-Za-z0-9_\-:.]*secret[A-Za-z0-9_\-:.]*/i', '[redacted]', $message ?: '') ?: 'AI generation failed.';
    }
}
