<?php

namespace App\Services\Ai;

use App\Contracts\AiImageProvider;
use App\DTOs\Ai\GeneratedAssetResult;
use App\DTOs\Ai\GenerationRequest;
use App\DTOs\Ai\GenerationStatusResult;
use App\DTOs\Ai\GenerationSubmissionResult;
use App\DTOs\Ai\MoneyValue;
use App\Models\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FalImageProvider implements AiImageProvider
{
    public function __construct(
        private readonly AiProviderCredentialService $credentials,
        private readonly AiProviderAvailability $availability,
    ) {}

    public function isAvailable(): bool
    {
        $provider = AiProvider::query()->where('driver', 'fal')->first();

        return $provider ? $this->availability->providerAvailable($provider) : false;
    }

    public function listSupportedModels(): array
    {
        return AiProvider::query()
            ->where('driver', 'fal')
            ->first()
            ?->models()
            ->where('is_active', true)
            ->pluck('display_name', 'code')
            ->all() ?? [];
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, ['character_sheet', 'character_scene', 'cover_generation', 'scene_edit'], true);
    }

    public function estimateCost(GenerationRequest $request): MoneyValue
    {
        $amount = $request->model->estimatedCost();

        return new MoneyValue($amount, $request->model->estimated_cost_currency ?? 'USD', $request->model->estimated_cost_type ?? 'estimate');
    }

    public function submitGeneration(GenerationRequest $request): GenerationSubmissionResult
    {
        $provider = $request->model->provider;

        if (! $provider || ! $this->availability->providerAvailable($provider)) {
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

        $response = $this->client($provider)
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
        $provider = AiProvider::query()->where('driver', 'fal')->first();

        if (! $provider || ! $this->availability->providerAvailable($provider)) {
            throw new RuntimeException('AI generation is not configured yet.');
        }

        if (! $statusUrl && ! $responseUrl) {
            throw new RuntimeException('Missing fal status URL.');
        }

        $statusResponse = $statusUrl
            ? $this->client($provider)->get($statusUrl)->throw()->json()
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
            ? $this->client($provider)->get($responseUrl)->throw()->json()
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

        $provider = AiProvider::query()->where('driver', 'fal')->first();

        $response = Http::timeout($this->timeout($provider))
            ->retry($this->maxRetries($provider), 500)
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

    public function testConnection(AiProvider $provider, bool $allowBillable = false): array
    {
        if (! $allowBillable) {
            return [
                'status' => 'warning',
                'message' => 'A billable validation request requires confirmation.',
            ];
        }

        try {
            $this->client($provider)->get('https://queue.fal.run/')->throw();

            return [
                'status' => 'passed',
                'message' => 'Connection verified successfully.',
            ];
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            return [
                'status' => in_array($status, [401, 403], true) ? 'failed' : 'warning',
                'message' => in_array($status, [401, 403], true)
                    ? 'Authentication failed. Please verify the API key.'
                    : 'Provider is temporarily unavailable.',
            ];
        } catch (\Throwable) {
            return [
                'status' => 'failed',
                'message' => 'Connection test could not be completed.',
            ];
        }
    }

    private function client(AiProvider $provider): PendingRequest
    {
        $secret = $this->credentials->secret($provider);

        if (! $secret) {
            throw new RuntimeException('AI generation is not configured yet.');
        }

        return Http::timeout($this->timeout($provider))
            ->retry($this->maxRetries($provider), 500)
            ->withHeaders([
                'Authorization' => 'Key '.$secret,
                'Accept' => 'application/json',
            ]);
    }

    private function timeout(?AiProvider $provider): int
    {
        return (int) ($provider?->default_timeout_seconds ?: config('production_studio.ai.fal.request_timeout', 180));
    }

    private function maxRetries(?AiProvider $provider): int
    {
        return (int) ($provider?->default_max_retries ?? config('production_studio.ai.fal.max_retries', 2));
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
