<?php

namespace App\Services\RemoteStudio;

use App\DTOs\Ai\GptImageRequest;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\StudioCoverGeneration;
use App\Models\StudioSettingsRevision;
use App\Models\User;
use App\Services\AgentApi\AgentStudioOrderService;
use App\Services\Ai\GptImageClient;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class StudioCoverService
{
    public function unit(User $user, string $orderNumber, string $unitKey, string $kind): array
    {
        $context = app(AgentStudioOrderService::class)->find($orderNumber, $user);
        $unit = $context['production_units']->firstWhere('unit_key', $unitKey);
        abort_unless($unit && (($kind === 'story' && $unit['type'] === 'story') || ($kind === 'coloring_book' && $unit['type'] === 'product')), 403, 'Cover unit is not permitted.');

        return $unit;
    }

    public function authorizeGeneration(StudioCoverGeneration $generation, User $user): array
    {
        abort_unless((int) $generation->user_id === (int) $user->id, 404);
        $orderNumber = Order::findOrFail($generation->order_id)->order_number;

        return $this->unit($user, $orderNumber, $generation->unit_key, $generation->kind);
    }

    public function run(StudioCoverGeneration $generation): void
    {
        abort_unless((int) config('queue.connections.database.retry_after') >= 360, 503);
        $user = User::findOrFail($generation->user_id);
        $token = PersonalAccessToken::findOrFail($generation->personal_access_token_id);
        $user->withAccessToken($token);
        app(StudioSessionService::class)->validate($user, $token);
        abort_unless(($user->agent_api_enabled || in_array(StudioSessionService::EMPLOYEE_PERMISSIONS, $token->abilities, true))
            && $user->hasAllPermissions(['studio.covers.generate', 'orders.view', 'orders.photos.view']) && $user->tokenCan('agent:orders.read'), 403);
        $unit = $this->authorizeGeneration($generation, $user);
        $settings = StudioSettingsRevision::findOrFail($generation->revision_id)->settings;
        $cover = $settings['openai_cover'];
        // Both the queued immutable revision and current kill switch must allow generation.
        abort_unless($cover['enabled'] && data_get(app(StudioSettingsService::class)->current()?->settings, 'openai_cover.enabled'), 409, 'Cover generation disabled.');
        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        $model = $provider->models()->where('code', $cover['model'])->where('is_active', true)->firstOrFail();
        abort_unless($model->supportsCapability('cover_generation'), 409, 'Model is not approved for cover generation.');
        $context = $generation->output['context'];
        $values = [
            'story_title' => $context['title'] ?? $unit['title'], 'story_theme' => $context['theme'] ?? $unit['title'],
            'child_name' => $context['child_name'] ?? data_get($unit, 'child.name', ''),
            'child_age' => $context['child_age'] ?? data_get($unit, 'child.age', ''),
            'child_gender' => $context['child_gender'] ?? data_get($unit, 'child.gender', ''),
            'language' => $unit['language'] ?? 'ar', 'dedication' => $context['dedication'] ?? data_get($unit, 'notes.dedication', ''),
            'story_template_title' => $unit['title'], 'hero_kid_brand' => 'HeroKid', 'cover_style_notes' => $cover['cover_style_notes'],
        ];
        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
        }
        $prompt = strtr($cover[$generation->kind === 'story' ? 'prompt_template' : 'coloring_book_prompt_template'], $replacements);
        $images = [];
        if ($cover['use_identity_image']) {
            $bytes = Storage::disk('studio_private')->get($generation->reference_path);
            $mime = getimagesizefromstring($bytes)['mime'];
            $images[] = 'data:'.$mime.';base64,'.base64_encode($bytes);
        }
        $result = app(GptImageClient::class)->generate(new GptImageRequest(
            $provider, $model, $prompt, $images, $cover['size'], $cover['quality'], 'studio-cover-'.$generation->id,
            allowTextOnly: ! $cover['use_identity_image'], maxAttempts: 1,
        ));
        $path = 'covers/'.$generation->id.'/output.'.$result->extension;
        Storage::disk('studio_private')->put($path, $result->contents);
        $size = getimagesizefromstring($result->contents);
        $generation->forceFill(['status' => 'completed', 'output_path' => $path, 'output' => [
            'mime_type' => $result->mimeType, 'byte_size' => strlen($result->contents), 'sha256' => hash('sha256', $result->contents),
            'width' => $size[0], 'height' => $size[1], 'used_identity_image' => $cover['use_identity_image'],
            'logo_composited' => false, 'settings_revision' => (string) $generation->revision_id,
        ]])->save();
    }
}
