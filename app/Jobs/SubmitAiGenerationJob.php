<?php

namespace App\Jobs;

use App\DTOs\Ai\GenerationRequest;
use App\Models\ProductionProjectAsset;
use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\GenerationInputAssetResolver;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitAiGenerationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $generationJobId) {}

    public function handle(AiProviderManager $providers, GenerationInputAssetResolver $inputAssets): void
    {
        $job = SceneGenerationJob::with(['project.order', 'scene', 'model.provider'])->findOrFail($this->generationJobId);

        try {
            $job->update(['status' => 'processing', 'submitted_at' => now()]);

            $characterSheet = data_get($job->input_assets_json, 'character_sheet_id')
                ? ProductionProjectAsset::find((int) data_get($job->input_assets_json, 'character_sheet_id'))
                : null;

            $request = new GenerationRequest(
                project: $job->project,
                scene: $job->scene,
                model: $job->model,
                jobType: $job->job_type,
                generationMode: $job->generation_mode,
                prompt: $job->prompt_snapshot,
                negativePrompt: $job->negative_prompt_snapshot ?? '',
                inputAssets: $inputAssets->resolve($job->project, data_get($job->input_assets_json, 'reference_photo_indices', []), $characterSheet),
            );

            $result = $providers
                ->imageProvider($job->model->provider->driver)
                ->submitGeneration($request);

            $job->update([
                'external_request_id' => $result->externalRequestId,
                'external_status_url' => $result->statusUrl,
                'external_response_url' => $result->responseUrl,
                'provider_response_json' => $result->raw,
                'status' => 'processing',
            ]);

            ProductionStudio::log($job->project, 'ai_generation.submitted', 'تم إرسال مهمة التوليد إلى مزود الذكاء الاصطناعي.', [
                'job_id' => $job->id,
                'external_request_id' => $result->externalRequestId,
            ]);

            PollAiGenerationJob::dispatch($job->id)->delay(now()->addSeconds(15));
        } catch (Throwable $exception) {
            $this->failJob($job, $exception);
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
    }

    private function safeMessage(Throwable $exception): string
    {
        return str_replace((string) config('production_studio.ai.fal.key'), '[redacted]', $exception->getMessage());
    }
}
