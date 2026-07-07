<?php

namespace App\Services\Ai;

use App\Contracts\AiImageProvider;
use App\DTOs\Ai\GeneratedAssetResult;
use App\DTOs\Ai\GenerationRequest;
use App\DTOs\Ai\GenerationStatusResult;
use App\DTOs\Ai\GenerationSubmissionResult;
use App\DTOs\Ai\MoneyValue;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FalImageProvider implements AiImageProvider
{
    public function isAvailable(): bool
    {
        return (bool) config('production_studio.enabled')
            && (bool) config('production_studio.ai.fal.enabled')
            && filled(config('production_studio.ai.fal.key'));
    }

    public function listSupportedModels(): array
    {
        return [
            config('production_studio.ai.fal.default_model') => 'FLUX Kontext Dev',
            config('production_studio.ai.fal.default_premium_model') => 'FLUX Kontext Pro',
        ];
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, ['character_sheet', 'character_scene', 'cover_generation', 'scene_edit'], true);
    }

    public function estimateCost(GenerationRequest $request): MoneyValue
    {
        $amount = (string) config('production_studio.ai.costs.'.$request->model->code, $request->model->estimated_cost_per_output ?? '0.0000');

        return new MoneyValue($amount, 'USD', 'estimate');
    }

    public function submitGeneration(GenerationRequest $request): GenerationSubmissionResult
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('AI generation is not configured yet.');
        }

        $payload = [
            'prompt' => $request->prompt,
            'negative_prompt' => $request->negativePrompt,
            'num_images' => 1,
            'output_format' => 'png',
        ];

        if ($request->inputAssets !== []) {
            $payload['image_urls'] = array_values($request->inputAssets);
            $payload['image_url'] = $payload['image_urls'][0];
        }

        $response = $this->client()
            ->post('https://queue.fal.run/'.ltrim($request->model->code, '/'), $payload)
            ->throw()
            ->json();

        return new GenerationSubmissionResult(
            externalRequestId: (string) data_get($response, 'request_id'),
            status: (string) data_get($response, 'status', 'IN_QUEUE'),
            statusUrl: data_get($response, 'status_url'),
            responseUrl: data_get($response, 'response_url'),
            raw: $this->redact($response),
        );
    }

    public function pollGeneration(string $externalRequestId, ?string $statusUrl = null, ?string $responseUrl = null): GenerationStatusResult
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException('AI generation is not configured yet.');
        }

        if (! $statusUrl && ! $responseUrl) {
            throw new RuntimeException('Missing fal status URL.');
        }

        $statusResponse = $statusUrl
            ? $this->client()->get($statusUrl)->throw()->json()
            : ['status' => 'COMPLETED'];

        $status = (string) data_get($statusResponse, 'status', 'UNKNOWN');

        if (! in_array($status, ['COMPLETED', 'completed'], true)) {
            return new GenerationStatusResult(
                status: $status,
                raw: $this->redact($statusResponse),
                errorMessage: data_get($statusResponse, 'error.message') ?? data_get($statusResponse, 'detail'),
            );
        }

        $resultResponse = $responseUrl
            ? $this->client()->get($responseUrl)->throw()->json()
            : $statusResponse;

        $imageUrl = data_get($resultResponse, 'images.0.url')
            ?? data_get($resultResponse, 'image.url')
            ?? data_get($resultResponse, 'output.0.url')
            ?? data_get($resultResponse, 'url');

        return new GenerationStatusResult(
            status: 'COMPLETED',
            raw: $this->redact($resultResponse),
            imageUrl: $imageUrl,
            actualCost: data_get($resultResponse, 'metrics.cost') ? (string) data_get($resultResponse, 'metrics.cost') : null,
        );
    }

    public function downloadOutput(GenerationStatusResult $result): GeneratedAssetResult
    {
        if (! $result->imageUrl) {
            throw new RuntimeException('Completed fal response did not include an image URL.');
        }

        $response = Http::timeout((int) config('production_studio.ai.fal.request_timeout', 180))
            ->retry((int) config('production_studio.ai.fal.max_retries', 2), 500)
            ->get($result->imageUrl)
            ->throw();

        $contentType = $response->header('Content-Type', 'image/png');
        $extension = Str::contains($contentType, 'jpeg') || Str::contains($contentType, 'jpg') ? 'jpg' : 'png';

        return new GeneratedAssetResult(
            contents: $response->body(),
            extension: $extension,
            mimeType: $contentType,
            metadata: ['source_url_host' => parse_url($result->imageUrl, PHP_URL_HOST)],
        );
    }

    private function client(): PendingRequest
    {
        return Http::timeout((int) config('production_studio.ai.fal.request_timeout', 180))
            ->retry((int) config('production_studio.ai.fal.max_retries', 2), 500)
            ->withHeaders([
                'Authorization' => 'Key '.config('production_studio.ai.fal.key'),
                'Accept' => 'application/json',
            ]);
    }

    private function redact(array $payload): array
    {
        array_walk_recursive($payload, function (&$value, $key): void {
            if (is_string($key) && Str::contains(Str::lower($key), ['key', 'token', 'secret', 'authorization'])) {
                $value = '[redacted]';
            }
        });

        return $payload;
    }
}
