<?php

namespace App\Services\Ai;

use App\Contracts\AiTextVisionProvider;
use App\DTOs\Ai\StructuredAiResult;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\ProductionProject;
use App\Models\ProductionScene;
use App\Models\ProductionStoryVersion;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAiTextVisionProvider implements AiTextVisionProvider
{
    public function __construct(
        private readonly AiProviderCredentialService $credentials,
        private readonly AiProviderAvailability $availability,
    ) {}

    public function isAvailable(): bool
    {
        $provider = AiProvider::query()->where('driver', 'openai')->first();

        return $provider ? $this->availability->providerAvailable($provider) : false;
    }

    public function analyzeImagesToJson(ProductionProject $project, AiModel $model, array $photoIndices): StructuredAiResult
    {
        $project->loadMissing(['order', 'characterProfile']);
        $photoInputs = $this->childPhotoInputs($project, $photoIndices);

        if ($photoInputs === []) {
            throw new RuntimeException('اختر صورة واحدة على الأقل لتحليل الهوية.');
        }

        $prompt = implode("\n", [
            'Analyze only the selected child photos for HeroKid production identity preparation.',
            'Return objective, child-safe observations for an illustrator. Do not identify the child or infer sensitive traits.',
            'Prioritize stable visual identity: face shape, eyes, hair, skin tone, expression, apparent age, and proportions.',
            'Write Arabic production notes. Do not include private file paths.',
        ]);

        return $this->requestJson(
            model: $model,
            prompt: $prompt,
            schemaName: 'herokid_character_identity_analysis',
            schema: $this->characterAnalysisSchema(),
            content: array_merge([
                ['type' => 'input_text', 'text' => $prompt],
            ], $photoInputs),
            validator: fn (array $data): bool => $this->hasKeys($data, [
                'appearance_summary',
                'hair_details',
                'skin_tone',
                'eyes_and_visible_traits',
                'usual_expression',
                'face_shape_notes',
                'body_proportion_notes',
                'identity_rules',
                'negative_instructions',
                'confidence_notes',
                'reference_photo_recommendations',
                'warnings',
            ]),
        );
    }

    public function extractScenesToJson(ProductionProject $project, AiModel $model, ProductionStoryVersion|string|null $source): StructuredAiResult
    {
        $project->loadMissing(['order.story']);
        $storyText = $source instanceof ProductionStoryVersion
            ? $source->full_story_content
            : (string) $source;

        if (blank($storyText)) {
            $storyText = (string) ($project->order->story?->full_story ?? $project->order->story?->full_desc ?? $project->order->story?->short_desc);
        }

        if (blank($storyText)) {
            throw new RuntimeException('لا يوجد نص قصة كافٍ لبناء المشاهد.');
        }

        $prompt = implode("\n", [
            'Extract exactly 13 HeroKid production scenes from the story draft.',
            'Return strict JSON only according to the schema.',
            'Each scene must include written_text, visual_direction, child_action_pose, environment, mood_lighting, supporting_characters, key_objects, continuity_notes, safe_text_area_notes, and educational_value.',
            'Do not create copyrighted characters or brand references.',
            'Story draft:',
            $storyText,
        ]);

        return $this->requestJson(
            model: $model,
            prompt: $prompt,
            schemaName: 'herokid_scene_extraction',
            schema: $this->sceneExtractionSchema(),
            content: [['type' => 'input_text', 'text' => $prompt]],
            validator: fn (array $data): bool => $this->validSceneExtraction($data),
        );
    }

    public function improveSceneToJson(ProductionProject $project, ProductionScene $scene, AiModel $model): StructuredAiResult
    {
        $project->loadMissing(['order.story']);

        if (blank($scene->story_text)) {
            throw new RuntimeException('لا يمكن تحسين التوجيه البصري بدون نص المشهد.');
        }

        $prompt = implode("\n", [
            'Improve a single HeroKid scene for later fal.ai image generation.',
            'Return strict JSON only according to the schema. Do not include final image prompt text.',
            'No Arabic text should be requested inside the image. Reserve calm safe text space only.',
            'Story title: '.($project->order->story?->title ?? 'Not available'),
            'Story summary: '.($project->order->story?->short_desc ?? $project->order->story?->full_desc ?? 'Not available'),
            'Scene number: '.$scene->scene_number,
            'Scene title: '.($scene->title ?? 'Not available'),
            'Scene written text: '.$scene->story_text,
            'Current visual direction: '.($scene->visual_direction ?? 'Not available'),
            'Current child action / pose: '.($scene->child_action_pose ?? 'Not available'),
            'Current safe text notes: '.($scene->text_safe_area_notes ?? 'Not available'),
        ]);

        return $this->requestJson(
            model: $model,
            prompt: $prompt,
            schemaName: 'herokid_scene_improvement',
            schema: $this->sceneImprovementSchema(),
            content: [['type' => 'input_text', 'text' => $prompt]],
            validator: fn (array $data): bool => $this->hasKeys($data, [
                'visual_direction',
                'child_action_pose',
                'environment',
                'mood_lighting',
                'supporting_characters',
                'key_objects',
                'continuity_notes',
                'safe_text_area_notes',
            ]),
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

        $modelCode = $provider->models()->where('is_active', true)->orderBy('sort_order')->value('code');

        if (! $modelCode) {
            return ['status' => 'failed', 'message' => 'No active OpenAI model is configured.'];
        }

        try {
            $this->client($provider)
                ->post('https://api.openai.com/v1/responses', [
                    'model' => $modelCode,
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

    private function requestJson(AiModel $model, string $prompt, string $schemaName, array $schema, array $content, callable $validator): StructuredAiResult
    {
        $provider = $model->provider;

        if (! $provider || $provider->driver !== 'openai' || ! $provider->is_active || ! $model->is_active || ! $this->credentials->hasCredential($provider)) {
            throw new RuntimeException('OpenAI text/vision provider is not configured yet.');
        }

        $payload = [
            'model' => $model->code,
            'input' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];

        $response = $this->client($provider)
            ->post('https://api.openai.com/v1/responses', $payload)
            ->throw()
            ->json();

        $jsonText = $this->extractText($response);
        $data = json_decode($jsonText, true);

        if (! is_array($data) || ! $validator($data)) {
            throw new RuntimeException('OpenAI returned invalid structured JSON. Existing fields were not changed.');
        }

        return new StructuredAiResult(
            data: $data,
            raw: $this->redact($response),
            usage: [
                'input_tokens' => data_get($response, 'usage.input_tokens'),
                'output_tokens' => data_get($response, 'usage.output_tokens'),
                'total_tokens' => data_get($response, 'usage.total_tokens'),
            ],
            prompt: $prompt,
            actualCost: $model->estimatedCost(),
            costSource: 'estimated',
        );
    }

    private function childPhotoInputs(ProductionProject $project, array $photoIndices): array
    {
        $photos = $project->order?->uploaded_photos ?? [];
        $indices = array_values(array_unique(array_map('intval', $photoIndices)));

        return collect($indices)
            ->map(function (int $index) use ($photos): ?array {
                $path = $photos[$index] ?? null;

                if (! is_string($path) || str_contains($path, '..')) {
                    return null;
                }

                $dataUrl = $this->photoDataUrl($path);

                return $dataUrl ? ['type' => 'input_image', 'image_url' => $dataUrl] : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function photoDataUrl(string $path): ?string
    {
        foreach (['local', 'public'] as $diskName) {
            $disk = Storage::disk($diskName);

            if ($disk->exists($path)) {
                $contents = $disk->get($path);
                $mime = $disk->mimeType($path) ?: 'image/jpeg';

                return 'data:'.$mime.';base64,'.base64_encode($contents);
            }
        }

        $legacyPath = storage_path('app/'.ltrim($path, '/'));

        if (is_file($legacyPath)) {
            $mime = mime_content_type($legacyPath) ?: 'image/jpeg';

            return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($legacyPath));
        }

        return null;
    }

    private function extractText(array $response): string
    {
        $text = data_get($response, 'output_text');

        if (is_string($text) && filled($text)) {
            return $text;
        }

        foreach (data_get($response, 'output', []) as $output) {
            foreach (data_get($output, 'content', []) as $content) {
                $text = data_get($content, 'text');

                if (is_string($text) && filled($text)) {
                    return $text;
                }
            }
        }

        throw new RuntimeException('OpenAI response did not include JSON text.');
    }

    private function client(AiProvider $provider): PendingRequest
    {
        $secret = $this->credentials->secret($provider);

        if (! $secret) {
            throw new RuntimeException('OpenAI provider is not configured yet.');
        }

        return Http::timeout((int) ($provider->default_timeout_seconds ?: 60))
            ->retry((int) ($provider->default_max_retries ?? 1), 500)
            ->withToken($secret)
            ->acceptJson();
    }

    private function characterAnalysisSchema(): array
    {
        return $this->objectSchema([
            'appearance_summary' => ['type' => 'string'],
            'hair_details' => ['type' => 'string'],
            'skin_tone' => ['type' => 'string'],
            'eyes_and_visible_traits' => ['type' => 'string'],
            'usual_expression' => ['type' => 'string'],
            'face_shape_notes' => ['type' => 'string'],
            'body_proportion_notes' => ['type' => 'string'],
            'identity_rules' => ['type' => 'string'],
            'negative_instructions' => ['type' => 'string'],
            'confidence_notes' => ['type' => 'string'],
            'reference_photo_recommendations' => ['type' => 'string'],
            'warnings' => ['type' => 'string'],
        ]);
    }

    private function sceneExtractionSchema(): array
    {
        return $this->objectSchema([
            'story_title' => ['type' => 'string'],
            'story_summary' => ['type' => 'string'],
            'target_age_range' => ['type' => 'string'],
            'educational_values' => ['type' => 'array', 'items' => ['type' => 'string']],
            'scenes' => [
                'type' => 'array',
                'minItems' => 13,
                'maxItems' => 13,
                'items' => $this->objectSchema([
                    'scene_number' => ['type' => 'integer'],
                    'scene_title' => ['type' => 'string'],
                    'written_text' => ['type' => 'string'],
                    'visual_direction' => ['type' => 'string'],
                    'child_action_pose' => ['type' => 'string'],
                    'environment' => ['type' => 'string'],
                    'mood_lighting' => ['type' => 'string'],
                    'supporting_characters' => ['type' => 'string'],
                    'key_objects' => ['type' => 'string'],
                    'continuity_notes' => ['type' => 'string'],
                    'safe_text_area_notes' => ['type' => 'string'],
                    'educational_value' => ['type' => 'string'],
                ]),
            ],
        ]);
    }

    private function sceneImprovementSchema(): array
    {
        return $this->objectSchema([
            'visual_direction' => ['type' => 'string'],
            'child_action_pose' => ['type' => 'string'],
            'environment' => ['type' => 'string'],
            'mood_lighting' => ['type' => 'string'],
            'supporting_characters' => ['type' => 'string'],
            'key_objects' => ['type' => 'string'],
            'continuity_notes' => ['type' => 'string'],
            'safe_text_area_notes' => ['type' => 'string'],
        ]);
    }

    private function objectSchema(array $properties): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    private function hasKeys(array $data, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                return false;
            }
        }

        return true;
    }

    private function validSceneExtraction(array $data): bool
    {
        if (! $this->hasKeys($data, ['story_title', 'story_summary', 'target_age_range', 'educational_values', 'scenes'])) {
            return false;
        }

        if (! is_array($data['scenes']) || count($data['scenes']) !== 13) {
            return false;
        }

        foreach ($data['scenes'] as $scene) {
            if (! is_array($scene) || ! $this->hasKeys($scene, [
                'scene_number',
                'scene_title',
                'written_text',
                'visual_direction',
                'child_action_pose',
                'environment',
                'mood_lighting',
                'supporting_characters',
                'key_objects',
                'continuity_notes',
                'safe_text_area_notes',
                'educational_value',
            ])) {
                return false;
            }
        }

        return true;
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
