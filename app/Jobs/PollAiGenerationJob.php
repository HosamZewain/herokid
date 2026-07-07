<?php

namespace App\Jobs;

use App\Models\SceneGenerationJob;
use App\Services\Ai\AiProviderManager;
use App\Support\ProductionStudio;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PollAiGenerationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $generationJobId) {}

    public function handle(AiProviderManager $providers): void
    {
        $job = SceneGenerationJob::with(['project', 'scene', 'model.provider'])->findOrFail($this->generationJobId);

        if (! in_array($job->status, ['processing', 'queued'], true)) {
            return;
        }

        try {
            $provider = $providers->imageProvider($job->model->provider->driver);
            $status = $provider->pollGeneration($job->external_request_id, $job->external_status_url, $job->external_response_url);

            $job->provider_response_json = $status->raw;

            if ($status->isFailed()) {
                $job->status = 'failed';
                $job->error_message = $this->safeMessage($status->errorMessage ?: 'AI provider reported a failed generation.');
                $job->failed_at = now();
                $job->save();

                return;
            }

            if (! $status->isCompleted()) {
                $job->status = 'processing';
                $job->save();
                self::dispatch($job->id)->delay(now()->addSeconds(20));

                return;
            }

            $asset = $provider->downloadOutput($status);
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
                'label' => $this->labelFor($job->job_type, $version),
                'status' => 'under_review',
                'file_path' => $path,
                'metadata_json' => $asset->metadata + [
                    'provider' => $job->model->provider->driver,
                    'model' => $job->model->code,
                    'mime_type' => $asset->mimeType,
                ],
                'uploaded_by_user_id' => $job->initiated_by_user_id,
            ]);

            $job->update([
                'status' => 'completed',
                'output_asset_path' => $path,
                'output_metadata_json' => ['asset_id' => $createdAsset->id] + $asset->metadata,
                'actual_cost' => $status->actualCost ?: $job->estimated_cost,
                'cost_source' => $status->actualCost ? 'provider_actual' : 'estimate_fallback',
                'completed_at' => now(),
            ]);

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
        }
    }

    private function labelFor(string $type, int $version): string
    {
        return match ($type) {
            'character_sheet' => 'Character Sheet v'.$version,
            'cover_image' => 'Cover Image v'.$version,
            default => 'Scene Image v'.$version,
        };
    }

    private function safeMessage(string $message): string
    {
        return str_replace((string) config('production_studio.ai.fal.key'), '[redacted]', $message);
    }
}
