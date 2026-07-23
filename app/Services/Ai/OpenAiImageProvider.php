<?php

namespace App\Services\Ai;

use App\Contracts\AiImageProvider;
use App\DTOs\Ai\GeneratedAssetResult;
use App\DTOs\Ai\GenerationRequest;
use App\DTOs\Ai\GenerationStatusResult;
use App\DTOs\Ai\GenerationSubmissionResult;
use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\MoneyValue;
use App\Models\AiProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiImageProvider implements AiImageProvider
{
    private const LOCAL_RESPONSE_PREFIX = 'local://';

    public function __construct(
        private readonly AiProviderCredentialService $credentials,
        private readonly AiProviderAvailability $availability,
        private readonly GptImageClient $gptImageClient,
    ) {}

    public function isAvailable(): bool
    {
        $provider = AiProvider::query()->where('driver', 'openai')->first();

        return $provider ? $this->availability->providerAvailable($provider) : false;
    }

    public function listSupportedModels(): array
    {
        return AiProvider::query()
            ->where('driver', 'openai')
            ->first()
            ?->models()
            ->where('is_active', true)
            ->whereJsonContains('generation_capabilities_json', 'scene_generation')
            ->pluck('display_name', 'code')
            ->all() ?? [];
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, ['character_sheet', 'character_scene', 'cover_generation', 'scene_edit'], true);
    }

    public function estimateCost(GenerationRequest $request): MoneyValue
    {
        $quality = (string) ($request->options['quality'] ?? data_get($request->model->configuration_json, 'quality', 'medium'));
        $qualityCost = data_get($request->model->configuration_json, "quality_costs.{$quality}");

        return new MoneyValue(
            is_numeric($qualityCost) ? (string) $qualityCost : $request->model->estimatedCost(),
            $request->model->estimated_cost_currency ?? 'USD',
            $request->model->estimated_cost_type ?? 'estimate',
        );
    }

    public function submitGeneration(GenerationRequest $request): GenerationSubmissionResult
    {
        $provider = $request->model->provider;

        if (! $provider || ! $this->availability->providerAvailable($provider)) {
            throw new RuntimeException('OpenAI image generation is not configured yet.');
        }

        if ($request->model->requiresImageUrl() && $request->inputAssets === []) {
            throw new RuntimeException('هذا الموديل يحتاج صورة مرجعية. اختر صورة مرجعية أو صورة شخصية معتمدة أولًا.');
        }

        $result = $this->gptImageClient->generate(new GptImageRequest(
            provider: $provider,
            model: $request->model,
            prompt: $request->prompt,
            inputImages: $request->model->supportsMultipleReferences()
                ? $request->inputAssets
                : array_slice($request->inputAssets, 0, 1),
            size: $this->sizeFor($request),
            quality: (string) ($request->options['quality'] ?? data_get($request->model->configuration_json, 'quality', 'medium')),
            clientRequestId: $this->clientRequestId($request),
        ));
        $localPath = $this->storeTemporaryOutput($request, $result->contents, $result->extension);

        return new GenerationSubmissionResult(
            externalRequestId: $result->providerRequestId,
            status: 'COMPLETED',
            statusUrl: null,
            responseUrl: self::LOCAL_RESPONSE_PREFIX.$localPath,
            raw: array_merge($result->metadata, ['usage' => $result->usage]),
        );
    }

    public function pollGeneration(string $externalRequestId, ?string $statusUrl = null, ?string $responseUrl = null): GenerationStatusResult
    {
        if (! $responseUrl || ! Str::startsWith($responseUrl, self::LOCAL_RESPONSE_PREFIX)) {
            throw new RuntimeException('OpenAI image response was not available in private storage.');
        }

        $path = Str::after($responseUrl, self::LOCAL_RESPONSE_PREFIX);

        if (! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('OpenAI generated image was not found in private storage.');
        }

        return new GenerationStatusResult(
            status: 'COMPLETED',
            raw: [
                'provider' => 'openai',
                'external_request_id' => $externalRequestId,
                'output_location' => 'private_local_storage',
            ],
            imageUrl: $responseUrl,
        );
    }

    public function downloadOutput(GenerationStatusResult $result): GeneratedAssetResult
    {
        if (! $result->imageUrl || ! Str::startsWith($result->imageUrl, self::LOCAL_RESPONSE_PREFIX)) {
            throw new RuntimeException('Completed OpenAI response did not include a private image reference.');
        }

        $path = Str::after($result->imageUrl, self::LOCAL_RESPONSE_PREFIX);

        if (str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            throw new RuntimeException('OpenAI generated image was not found in private storage.');
        }

        $contents = Storage::disk('local')->get($path);
        $mimeType = Storage::disk('local')->mimeType($path) ?: 'image/png';
        $extension = match (true) {
            Str::contains($mimeType, ['jpeg', 'jpg']) => 'jpg',
            Str::contains($mimeType, 'webp') => 'webp',
            default => 'png',
        };

        Storage::disk('local')->delete($path);

        return new GeneratedAssetResult(
            contents: $contents,
            extension: $extension,
            mimeType: $mimeType,
            metadata: [
                'source' => 'openai_private_image_output',
                'temporary_output_deleted' => true,
            ],
        );
    }

    public function testConnection(AiProvider $provider, bool $allowBillable = false): array
    {
        if (! $allowBillable) {
            return [
                'status' => 'warning',
                'message' => 'A lightweight validation request requires confirmation.',
            ];
        }

        try {
            $this->client($provider)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $provider->models()->where('is_active', true)->orderBy('sort_order')->value('code') ?: 'gpt-4.1-mini',
                    'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Return the word ok.']]]],
                    'max_output_tokens' => 8,
                ])
                ->throw();

            return ['status' => 'passed', 'message' => 'Connection verified successfully.'];
        } catch (RequestException $exception) {
            $status = $exception->response?->status();

            return [
                'status' => in_array($status, [401, 403], true) ? 'failed' : 'warning',
                'message' => in_array($status, [401, 403], true)
                    ? 'Authentication failed. Please verify the API key.'
                    : 'Provider is temporarily unavailable.',
            ];
        } catch (\Throwable) {
            return ['status' => 'failed', 'message' => 'Connection test could not be completed.'];
        }
    }

    private function storeTemporaryOutput(GenerationRequest $request, string $contents, string $extension): string
    {
        $path = 'production-studio/projects/'.$request->project->id.'/openai-temp/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    private function sizeFor(GenerationRequest $request): string
    {
        if ($request->jobType === 'scene_image') {
            return (string) data_get($request->model->configuration_json, 'scene_size', '1536x1024');
        }

        return (string) data_get($request->model->configuration_json, 'portrait_size', '1024x1536');
    }

    private function client(AiProvider $provider): PendingRequest
    {
        $secret = $this->credentials->secret($provider);

        if (! $secret) {
            throw new RuntimeException('OpenAI provider is not configured yet.');
        }

        return Http::timeout($this->timeout($provider))
            ->withToken($secret)
            ->acceptJson()
            ->retry((int) ($provider->default_max_retries ?? 1), 500);
    }

    private function timeout(?AiProvider $provider): int
    {
        return max(180, (int) ($provider?->default_timeout_seconds ?: 60));
    }

    private function clientRequestId(GenerationRequest $request): string
    {
        $value = (string) data_get(
            $request->options,
            'client_request_id',
            'hero-kid-project-'.$request->project->id.'-'.$request->generationMode
        );

        return preg_replace('/[^A-Za-z0-9_.:-]/', '-', $value) ?: 'hero-kid-image-request';
    }
}
