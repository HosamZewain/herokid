<?php

namespace App\Services\Ai;

use App\DTOs\Ai\GptImageRequest;
use App\DTOs\Ai\GptImageResult;
use App\Exceptions\GptImageException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GptImageClient
{
    public function __construct(
        private readonly AiProviderCredentialService $credentials,
        private readonly OpenAiImageInputNormalizer $inputNormalizer,
    ) {}

    public function isAvailable(GptImageRequest $request): bool
    {
        return $request->provider->driver === 'openai'
            && $request->provider->is_active
            && $request->provider->is_configured
            && $request->model->is_active
            && $request->model->ai_provider_id === $request->provider->id
            && $this->credentials->hasCredential($request->provider)
            && ! in_array($request->provider->last_health_check_status, ['failed', 'disabled'], true);
    }

    public function generate(GptImageRequest $request): GptImageResult
    {
        if (! $this->isAvailable($request)) {
            throw new GptImageException('خدمة إنشاء الصورة غير متاحة حاليًا. يرجى المحاولة لاحقًا.', 'provider_unavailable');
        }

        if ($request->inputImages === []) {
            throw new GptImageException('يجب إرفاق صور مرجعية قبل إنشاء الهوية.', 'missing_input_photos');
        }

        try {
            $client = $this->client($request)
                ->withHeaders(['X-Client-Request-Id' => $this->safeId($request->clientRequestId)])
                ->asMultipart();

            foreach ($this->normalizeInputs($request) as $index => $input) {
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
                'size' => $request->size,
                'quality' => $request->quality,
                'n' => 1,
            ];

            if ($request->model->code !== 'gpt-image-2'
                && (bool) data_get($request->model->configuration_json, 'supports_high_input_fidelity', false)) {
                $payload['input_fidelity'] = 'high';
            }

            $response = $client->post('https://api.openai.com/v1/images/edits', $payload);
            $json = $response->throw()->json();
            $output = $this->extractOutput($request, $json);
            $requestId = $this->safeId(
                (string) ($response->header('x-request-id') ?: data_get($json, 'id') ?: 'openai-image-'.Str::uuid())
            );

            return new GptImageResult(
                contents: $output['contents'],
                extension: $output['extension'],
                mimeType: $output['mime_type'],
                providerRequestId: $requestId,
                usage: is_array(data_get($json, 'usage')) ? data_get($json, 'usage') : [],
                metadata: [
                    'provider' => 'openai',
                    'created' => data_get($json, 'created'),
                    'output_source' => $output['source'],
                    'response_id' => $this->safeId((string) data_get($json, 'id', '')),
                ],
            );
        } catch (GptImageException $exception) {
            throw $exception;
        } catch (RequestException $exception) {
            $providerRequestId = $this->safeId((string) $exception->response?->header('x-request-id'));
            $errorCode = $this->safeId((string) data_get($exception->response?->json(), 'error.code'));
            $errorType = $this->safeId((string) data_get($exception->response?->json(), 'error.type'));
            $errorParam = $this->safeId((string) data_get($exception->response?->json(), 'error.param'));
            $safeMessage = $this->safeErrorMessage(
                $exception,
                $request,
                $errorCode,
                $errorType,
                $errorParam,
                $providerRequestId,
            );

            Log::warning('OpenAI image request failed.', [
                'status' => $exception->response?->status(),
                'error_code' => $errorCode,
                'request_id' => $providerRequestId,
                'client_request_id' => $this->safeId($request->clientRequestId),
                'model' => $request->model->code,
            ]);

            throw new GptImageException(
                $safeMessage,
                $errorCode ?: 'provider_request_failed',
                $providerRequestId ?: null,
                true,
                $exception,
            );
        } catch (RuntimeException $exception) {
            throw new GptImageException(
                $exception->getMessage(),
                'input_processing_failed',
                providerMayHaveBilled: false,
                previous: $exception,
            );
        } catch (\Throwable $exception) {
            Log::error('OpenAI image request encountered an unexpected error.', [
                'client_request_id' => $this->safeId($request->clientRequestId),
                'model' => $request->model->code,
                'exception' => $exception::class,
            ]);

            throw new GptImageException(
                'تعذر إنشاء الصورة حاليًا. يرجى إعادة المحاولة لاحقًا.',
                'unexpected_provider_error',
                providerMayHaveBilled: true,
                previous: $exception,
            );
        }
    }

    private function client(GptImageRequest $request): PendingRequest
    {
        $secret = $this->credentials->secret($request->provider);

        if (! $secret) {
            throw new GptImageException('مفتاح OpenAI غير مهيأ.', 'missing_credential');
        }

        $client = Http::timeout(max(180, (int) ($request->provider->default_timeout_seconds ?: 60)))
            ->withToken($secret)
            ->acceptJson();

        $organization = trim((string) (
            data_get($request->provider->settings_json, 'organization')
            ?: data_get($request->provider->settings_json, 'organization_id')
        ));
        $project = trim((string) (
            data_get($request->provider->settings_json, 'project')
            ?: data_get($request->provider->settings_json, 'project_id')
        ));

        if ($organization !== '') {
            $client = $client->withHeaders(['OpenAI-Organization' => $organization]);
        }

        if ($project !== '') {
            $client = $client->withHeaders(['OpenAI-Project' => $project]);
        }

        return $client->retry((int) ($request->provider->default_max_retries ?? 1), 500);
    }

    private function normalizeInputs(GptImageRequest $request): array
    {
        $images = $request->model->supportsMultipleReferences()
            ? $request->inputImages
            : array_slice($request->inputImages, 0, 1);

        return collect($images)
            ->map(fn (string $image): array => $this->inputNormalizer->normalizeDataUri($image))
            ->values()
            ->all();
    }

    private function extractOutput(GptImageRequest $request, array $response): array
    {
        $encoded = data_get($response, 'data.0.b64_json');

        if (is_string($encoded) && $encoded !== '') {
            $contents = base64_decode($encoded, true);

            if ($contents !== false && $contents !== '') {
                return $this->validatedOutput($contents, 'b64_json');
            }
        }

        $url = data_get($response, 'data.0.url');

        if (is_string($url) && $url !== '') {
            if (filter_var($url, FILTER_VALIDATE_URL) === false || parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new GptImageException(
                    'أعاد OpenAI رابط صورة غير آمن.',
                    'unsafe_output_url',
                    providerMayHaveBilled: true,
                );
            }

            $download = Http::timeout(max(180, (int) ($request->provider->default_timeout_seconds ?: 60)))
                ->get($url)
                ->throw();

            return $this->validatedOutput($download->body(), 'temporary_url');
        }

        throw new GptImageException(
            'استجاب OpenAI دون صورة قابلة للاستخدام.',
            'missing_output',
            providerMayHaveBilled: true,
        );
    }

    private function validatedOutput(string $contents, string $source): array
    {
        $image = @getimagesizefromstring($contents);
        $mime = strtolower((string) ($image['mime'] ?? ''));
        $extension = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => null,
        };

        if (! $extension) {
            throw new GptImageException(
                'استجاب OpenAI بملف غير صالح كصورة.',
                'invalid_output_image',
                providerMayHaveBilled: true,
            );
        }

        return [
            'contents' => $contents,
            'extension' => $extension,
            'mime_type' => $mime,
            'source' => $source,
        ];
    }

    private function safeErrorMessage(
        RequestException $exception,
        GptImageRequest $request,
        string $errorCode,
        string $errorType,
        string $errorParam,
        string $providerRequestId,
    ): string {
        $status = $exception->response?->status();
        $providerMessage = (string) data_get($exception->response?->json(), 'error.message', '');
        $diagnostic = strtolower(implode(' ', [$providerMessage, $errorCode, $errorType]));
        $tracking = $this->trackingSuffix($request, $errorCode, $errorType, $errorParam, $providerRequestId);

        $message = match (true) {
            $status === 400 && preg_match('/invalid image file|image.*mode|unsupported image/i', $providerMessage) === 1 => 'رفض OpenAI الصورة المرجعية بعد تجهيزها. تأكد أن الصورة الأصلية PNG أو JPEG أو WebP سليمة ثم أعد المحاولة.',
            $status === 400 && preg_match('/safety|content.?policy|moderation|guardrail/i', $diagnostic) === 1 => 'رفض نظام أمان OpenAI هذا المشهد. راجع النص والبرومبت ثم أعد المحاولة بعد إزالة أي وصف قد يُفهم كمحتوى غير مناسب.',
            in_array($status, [400, 404], true) && preg_match('/model.*(not found|does not exist|not supported|access)|access.*model/i', $providerMessage) === 1 => 'موديل OpenAI المحدد غير متاح لهذا الحساب أو لهذا الـ endpoint.',
            str_contains($diagnostic, 'billing'), str_contains($diagnostic, 'quota') => 'تعذر تنفيذ الصورة بسبب الرصيد أو حد الاستخدام في حساب OpenAI.',
            in_array($status, [401, 403], true) => 'تعذر اعتماد بيانات OpenAI.',
            $status === 429 => 'خدمة إنشاء الصور مشغولة حاليًا. حاول مرة أخرى بعد قليل.',
            $status !== null && $status >= 500 => 'مزود إنشاء الصور غير متاح مؤقتًا.',
            default => 'تعذر إنشاء الصورة عبر مزود الصور.',
        };

        return $message.$tracking;
    }

    private function trackingSuffix(
        GptImageRequest $request,
        string $code,
        string $type,
        string $param,
        string $providerRequestId,
    ): string {
        $details = array_filter([
            $code !== '' ? 'code='.$code : null,
            $type !== '' ? 'type='.$type : null,
            $param !== '' ? 'param='.$param : null,
            $providerRequestId !== '' ? 'request='.$providerRequestId : null,
            'client='.$this->safeId($request->clientRequestId),
        ]);

        return $details === [] ? '' : ' ['.implode('، ', $details).']';
    }

    private function safeId(string $value): string
    {
        return Str::limit((string) preg_replace('/[^A-Za-z0-9_.:-]/', '-', $value), 120, '');
    }
}
