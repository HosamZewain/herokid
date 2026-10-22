<?php

namespace App\Services\OrderAutomation;

use App\DTOs\Ai\GenerationRequest;
use App\DTOs\Ai\GptImageRequest;
use App\Models\AiModel;
use App\Models\OrderAutomationRun;
use App\Models\ProductionProject;
use App\Services\Ai\AiProviderAvailability;
use App\Services\Ai\FalImageProvider;
use App\Services\Ai\GptImageClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class WorkflowImageGateway
{
    public function model(OrderAutomationRun $run): AiModel
    {
        $def = $run->revision->definition;
        $current = AiModel::with('provider')->find($def['model_id']);
        if (! $current || $current->code !== $def['model_snapshot']['code'] || $current->ai_provider_id !== $def['model_snapshot']['ai_provider_id']
            || $current->provider?->driver !== $def['provider_driver']
            || ! app(AiProviderAvailability::class)->modelAvailable($current, 'character_sheet')) {
            throw new RuntimeException('provider_unavailable');
        }
        $model = new AiModel($def['model_snapshot']);
        $model->is_active = true;
        $provider = clone $current->provider;
        $provider->default_max_retries = 1;
        $provider->default_timeout_seconds = 180;
        $model->setRelation('provider', $provider);

        return $model;
    }

    public function submit(OrderAutomationRun $run): array
    {
        $def = $run->revision->definition;
        $model = $this->model($run);
        $inputs = app(WorkflowInputs::class)->images($run->context);
        if ($model->provider->driver === 'openai') {
            $result = app(GptImageClient::class)->generate(new GptImageRequest($model->provider, $model, $run->context['prompt'], $inputs,
                $def['size'], $def['quality'], 'order-automation-'.$run->id, maxAttempts: 1));

            return ['output' => $this->store($run, $result->contents), 'provider_request_id' => $result->providerRequestId];
        }
        // The fal adapter only needs request inputs/model; no production project is created or modified.
        $result = app(FalImageProvider::class)->submitGeneration(new GenerationRequest(new ProductionProject, null, $model, 'character_sheet', 'character_sheet', $run->context['prompt'], '', $inputs, ['disable_retries' => true, 'size' => $def['size']]));
        if (! $result->externalRequestId) {
            throw new RuntimeException('provider_unknown');
        }
        foreach ([$result->statusUrl, $result->responseUrl] as $url) {
            if (! $url || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'queue.fal.run' || parse_url($url, PHP_URL_USER) || parse_url($url, PHP_URL_PORT)) {
                throw new RuntimeException('provider_unknown');
            }
        }

        return ['provider_request_id' => $result->externalRequestId, 'status_url' => $result->statusUrl, 'response_url' => $result->responseUrl];
    }

    public function poll(OrderAutomationRun $run): ?array
    {
        $model = $this->model($run);
        $result = app(FalImageProvider::class)->pollGeneration($run->context['provider_request_id'], $run->context['status_url'], $run->context['response_url'], $model->provider);
        if ($result->isFailed()) {
            throw new RuntimeException('provider_failed');
        }
        if (! $result->isCompleted()) {
            return null;
        }
        // Only provider CDN outputs, never arbitrary URLs or redirects into private services.
        $host = parse_url($result->imageUrl ?? '', PHP_URL_HOST);
        if (parse_url($result->imageUrl ?? '', PHP_URL_SCHEME) !== 'https' || ! is_string($host)
            || parse_url($result->imageUrl ?? '', PHP_URL_USER) || parse_url($result->imageUrl ?? '', PHP_URL_PORT)
            || ! (str_ends_with($host, '.fal.media') || $host === 'fal.media' || str_ends_with($host, '.fal.run'))) {
            throw new RuntimeException('invalid_output_url');
        }
        $response = Http::timeout(60)->withoutRedirecting()->withOptions(['progress' => static function ($total, $received): void {
            if ($total > 15 * 1024 * 1024 || $received > 15 * 1024 * 1024) {
                throw new RuntimeException('invalid_output');
            }
        }])->get($result->imageUrl)->throw();

        return $this->store($run, $response->body());
    }

    protected function store(OrderAutomationRun $run, string $bytes): array
    {
        if (strlen($bytes) > 15 * 1024 * 1024) {
            throw new RuntimeException('invalid_output');
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || ! in_array($size['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || $size[0] * $size[1] > 25000000) {
            throw new RuntimeException('invalid_output');
        }
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$size['mime']];
        $disk = config('media.private_disk', 'local');
        $path = 'order-automation/'.$run->id.'/identity.'.$extension;
        if (! Storage::disk($disk)->put($path, $bytes)) {
            throw new RuntimeException('output_storage_failed');
        }

        return ['disk' => $disk, 'path' => $path, 'mime' => $size['mime'], 'bytes' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'extension' => $extension];
    }
}
