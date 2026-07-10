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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiImageProvider implements AiImageProvider
{
    private const LOCAL_RESPONSE_PREFIX = 'local://';

    public function __construct(
        private readonly AiProviderCredentialService $credentials,
        private readonly AiProviderAvailability $availability,
        private readonly OpenAiImageInputNormalizer $inputNormalizer,
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
        return new MoneyValue(
            $request->model->estimatedCost(),
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

        $imageInputs = $this->imageInputsForModel($request);
        try {
            $response = $this->postImageRequest($provider, $request, $imageInputs);
            $json = $response->throw()->json();
        } catch (RequestException $exception) {
            throw new RuntimeException($this->safeImageError($exception), previous: $exception);
        }
        $output = $this->extractOutputImage($provider, $json);
        $localPath = $this->storeTemporaryOutput($request, $output['contents'], $output['extension']);
        $requestId = (string) (data_get($json, 'id') ?: 'openai-image-'.Str::uuid());

        return new GenerationSubmissionResult(
            externalRequestId: $requestId,
            status: 'COMPLETED',
            statusUrl: null,
            responseUrl: self::LOCAL_RESPONSE_PREFIX.$localPath,
            raw: $this->safeResponseMetadata($json, $output),
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
        $extension = Str::contains($mimeType, 'jpeg') || Str::contains($mimeType, 'jpg') ? 'jpg' : 'png';

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

    private function postImageRequest(AiProvider $provider, GenerationRequest $request, array $imageInputs)
    {
        $client = $this->client($provider)->asMultipart();

        foreach ($imageInputs as $index => $input) {
            $client = $client->attach(
                'image[]',
                $input['contents'],
                'reference-'.($index + 1).'.png',
                ['Content-Type' => 'image/png']
            );
        }

        $payload = [
            'model' => $request->model->code,
            'prompt' => $request->prompt,
            'size' => $this->sizeFor($request),
            'quality' => data_get($request->model->configuration_json, 'quality', 'medium'),
            'n' => 1,
        ];

        if ((bool) data_get($request->model->configuration_json, 'supports_high_input_fidelity', false)) {
            $payload['input_fidelity'] = 'high';
        }

        return $client->post('https://api.openai.com/v1/images/edits', $payload);
    }

    private function imageInputsForModel(GenerationRequest $request): array
    {
        $assets = $request->model->supportsMultipleReferences()
            ? $request->inputAssets
            : array_slice($request->inputAssets, 0, 1);

        return collect($assets)
            ->map(fn (string $asset): array => $this->inputNormalizer->normalizeDataUri($asset))
            ->values()
            ->all();
    }

    private function extractOutputImage(AiProvider $provider, array $response): array
    {
        $b64 = data_get($response, 'data.0.b64_json');

        if (is_string($b64) && filled($b64)) {
            $contents = base64_decode($b64, true);

            if ($contents !== false && $contents !== '') {
                return ['contents' => $contents, 'extension' => 'png', 'source' => 'b64_json'];
            }
        }

        $url = data_get($response, 'data.0.url');

        if (is_string($url) && filled($url)) {
            $download = Http::timeout($this->timeout($provider))->get($url)->throw();
            $contentType = $download->header('Content-Type', 'image/png');
            $extension = Str::contains($contentType, 'jpeg') || Str::contains($contentType, 'jpg') ? 'jpg' : 'png';

            return ['contents' => $download->body(), 'extension' => $extension, 'source' => 'temporary_url'];
        }

        throw new RuntimeException('OpenAI image response did not include a generated image.');
    }

    private function storeTemporaryOutput(GenerationRequest $request, string $contents, string $extension): string
    {
        $path = 'production-studio/projects/'.$request->project->id.'/openai-temp/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    private function sizeFor(GenerationRequest $request): string
    {
        if ($request->generationMode === 'character_scene') {
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
            ->retry((int) ($provider->default_max_retries ?? 1), 500)
            ->withToken($secret)
            ->acceptJson();
    }

    private function timeout(?AiProvider $provider): int
    {
        return max(180, (int) ($provider?->default_timeout_seconds ?: 60));
    }

    private function safeResponseMetadata(array $response, array $output): array
    {
        return [
            'id' => data_get($response, 'id'),
            'created' => data_get($response, 'created'),
            'provider' => 'openai',
            'output_source' => $output['source'] ?? 'unknown',
            'usage' => data_get($response, 'usage'),
        ];
    }

    private function safeImageError(RequestException $exception): string
    {
        $status = $exception->response?->status();
        $providerMessage = (string) data_get($exception->response?->json(), 'error.message', '');

        if ($status === 400 && preg_match('/invalid image file|image.*mode|unsupported image/i', $providerMessage)) {
            return 'رفض OpenAI الصورة المرجعية بعد تجهيزها. تأكد أن الصورة الأصلية PNG أو JPEG أو WebP سليمة ثم أعد المحاولة.';
        }

        if ($status === 400 && preg_match('/unknown parameter|unsupported parameter|unrecognized (request )?argument/i', $providerMessage)) {
            $parameter = $this->safeParameterName($providerMessage);

            return 'رفض OpenAI إعدادًا غير مدعوم في الطلب'.($parameter ? ': '.$parameter.'.' : '.');
        }

        if (in_array($status, [400, 404], true) && preg_match('/model.*(not found|does not exist|not supported|access)|access.*model/i', $providerMessage)) {
            return 'موديل OpenAI المحدد غير متاح لهذا الحساب أو لهذا الـ endpoint. فعّل الموديل من حساب OpenAI أو استخدم GPT Image 1 مؤقتًا.';
        }

        if (in_array($status, [401, 403], true)) {
            return 'تعذر اعتماد بيانات OpenAI. راجع مفتاح API وصلاحيات النموذج من إعدادات المزود.';
        }

        if ($status === 429) {
            return 'تم تجاوز حد استخدام OpenAI مؤقتًا. انتظر قليلًا ثم أعد المحاولة.';
        }

        return 'تعذر تنفيذ توليد الصورة عبر OpenAI'.($status ? ' (HTTP '.$status.').' : '.');
    }

    private function safeParameterName(string $message): ?string
    {
        if (preg_match('/[`\'\"]([a-zA-Z0-9_.-]{1,80})[`\'\"]/', $message, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
