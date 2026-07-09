<?php

namespace App\Jobs;

use App\Models\ProductionStoryVersion;
use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderManager;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessStructuredAiJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $generationJobId) {}

    public function handle(AiProviderManager $providers): void
    {
        $job = SceneGenerationJob::with([
            'project.order.story',
            'project.storyVersions',
            'scene',
            'model.provider',
            'initiator',
        ])->findOrFail($this->generationJobId);

        try {
            $job->update(['status' => 'processing', 'submitted_at' => now()]);

            $provider = $providers->textVisionProvider($job->model->provider->driver);

            $result = match ($job->job_type) {
                'character_analysis' => $provider->analyzeImagesToJson(
                    $job->project,
                    $job->model,
                    data_get($job->input_assets_json, 'reference_photo_indices', []),
                ),
                'scene_extraction' => $provider->extractScenesToJson(
                    $job->project,
                    $job->model,
                    $this->sceneExtractionSource($job),
                ),
                'scene_improvement' => $provider->improveSceneToJson(
                    $job->project,
                    $job->scene,
                    $job->model,
                ),
                default => throw new \RuntimeException('Unsupported structured AI job type.'),
            };

            $job->update([
                'prompt_snapshot' => $result->prompt,
                'provider_response_json' => [
                    'usage' => $result->usage,
                    'structured_result' => $result->data,
                    'metadata' => $result->metadata,
                ],
                'actual_cost' => $result->actualCost,
                'cost_source' => $result->costSource,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            ProductionStudio::log($job->project, 'ai_text_vision.completed', 'تم تنفيذ مهمة نص/رؤية بالذكاء الاصطناعي.', [
                'job_id' => $job->id,
                'job_type' => $job->job_type,
                'generation_mode' => $job->generation_mode,
                'model' => $job->model->code,
            ], $job->initiator);
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);
        }
    }

    private function sceneExtractionSource(SceneGenerationJob $job): ProductionStoryVersion|string|null
    {
        $sourceVersionId = data_get($job->input_assets_json, 'source_version_id');

        if ($sourceVersionId) {
            return $job->project->storyVersions->firstWhere('id', (int) $sourceVersionId);
        }

        return null;
    }

    private function failJob(SceneGenerationJob $job, Throwable $exception): void
    {
        $message = $this->safeMessage($exception);

        $job->update([
            'status' => 'failed',
            'error_message' => $message,
            'failed_at' => now(),
        ]);

        ProductionStudio::log($job->project, 'ai_text_vision.failed', 'فشلت مهمة نص/رؤية بالذكاء الاصطناعي.', [
            'job_id' => $job->id,
            'job_type' => $job->job_type,
            'error' => $message,
        ], $job->initiator);
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = preg_replace('/Bearer\s+[A-Za-z0-9_\-:.]+/', 'Bearer [redacted]', $exception->getMessage());
        $message = preg_replace('/Key\s+[A-Za-z0-9_\-:.]+/', 'Key [redacted]', $message ?: '');

        return preg_replace('/[A-Za-z0-9_\-:.]*secret[A-Za-z0-9_\-:.]*/i', '[redacted]', $message ?: '') ?: 'AI text/vision job failed.';
    }
}
