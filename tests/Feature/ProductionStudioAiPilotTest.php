<?php

namespace Tests\Feature;

use App\Actions\ProductionStudio\ApproveGeneratedAssetAction;
use App\Jobs\PollAiGenerationJob;
use App\Jobs\ProcessStructuredAiJob;
use App\Jobs\SubmitAiGenerationJob;
use App\Models\AiModel;
use App\Models\AiProvider;
use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionProject;
use App\Models\ProductionProjectAsset;
use App\Models\SceneGenerationJob;
use App\Models\Story;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Services\Ai\GenerationInputAssetResolver;
use App\Services\Ai\ProductionPromptCompiler;
use App\Services\ProductionStudio\IdentityReviewDispatcher;
use App\Services\ProductionStudio\ProductionAutomationIdentityValidator;
use App\Services\ProductionStudio\ScenePersonalizationService;
use App\Support\Ai\SupportedProviderRegistry;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionStudioAiPilotTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_ui_is_not_available_when_studio_feature_is_disabled(): void
    {
        Config::set('production_studio.enabled', false);

        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('AI Pilot')
            ->assertDontSee('Generate Character Sheet');
    }

    public function test_openai_provider_appears_in_supported_registry_and_can_store_encrypted_credentials(): void
    {
        app(AiProviderRegistrySyncer::class)->sync();

        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        app(AiProviderCredentialService::class)->save($provider, 'sk-openai-secret-for-tests');

        $this->assertSame('OpenAI', $provider->fresh()->display_name);
        $this->assertContains('vision_to_text', $provider->fresh()->capabilities_json);
        $this->assertContains('text_to_image', $provider->fresh()->capabilities_json);
        $this->assertTrue(app(SupportedProviderRegistry::class)->modelSupportsCapability('openai', 'gpt-4.1-mini', 'scene_extraction'));
        $this->assertTrue(app(SupportedProviderRegistry::class)->modelSupportsCapability('openai', 'gpt-image-2', 'scene_generation'));
        $this->assertTrue(app(SupportedProviderRegistry::class)->modelSupportsCapability('openai', 'gpt-image-2', 'cover_generation'));
        $this->assertNotSame('sk-openai-secret-for-tests', $provider->credential()->first()->getRawOriginal('encrypted_value'));
        $this->assertSame('sk-openai-secret-for-tests', app(AiProviderCredentialService::class)->secret($provider));
    }

    public function test_character_ai_analysis_button_is_disabled_when_openai_is_not_configured(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        $this->actingAs($this->adminUser())
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('تحليل صور الطفل بالذكاء الاصطناعي')
            ->assertSee('OpenAI أو نموذج تحليل الصور غير مهيأ')
            ->assertSee('disabled', false)
            ->assertSee('تعبئة مبدئية يدوية');
    }

    public function test_character_ai_analysis_can_start_from_original_order_photos_before_references_are_approved(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableOpenAi();

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $project->characterProfile->update([
            'approved_reference_photos' => [],
            'reference_photo_selection' => [],
            'primary_face_reference_index' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('OpenAI جاهز')
            ->assertSee('لم يتم اعتماد صور مرجعية بعد؛ سيتم تحليل صور الطلب الأصلية مؤقتًا')
            ->assertSee('name="reference_photo_indices[]" value="0"', false)
            ->assertSee('تحليل صور الطفل بالذكاء الاصطناعي')
            ->assertDontSee('disabled class="rounded-xl bg-purple-600', false);
    }

    public function test_character_ai_analysis_sends_selected_images_and_applies_structured_fields(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableOpenAi();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiJsonResponse([
                'appearance_summary' => 'طفلة مصرية بملامح هادئة وابتسامة طبيعية.',
                'hair_details' => 'شعر بني داكن مموج وطبيعي.',
                'skin_tone' => 'بشرة قمحية فاتحة.',
                'eyes_and_visible_traits' => 'عينان بنيتان وخدان ناعمان.',
                'usual_expression' => 'ابتسامة هادئة وواثقة.',
                'face_shape_notes' => 'وجه طفولي مستدير قليلًا.',
                'body_proportion_notes' => 'نسب جسم طفولية مناسبة للعمر.',
                'identity_rules' => 'حافظ على شكل الوجه والعينين والشعر والابتسامة.',
                'negative_instructions' => 'لا تغير الوجه ولا تضف نصوصًا أو شعارات.',
                'confidence_notes' => 'صورة الوجه واضحة.',
                'field_confidence' => [
                    'approximate_age_group' => 'high',
                    'face_shape_notes' => 'high',
                    'skin_tone' => 'high',
                    'hair_details' => 'high',
                    'eyes_and_visible_traits' => 'high',
                    'glasses_or_accessories' => 'medium',
                    'body_proportion_notes' => 'medium',
                    'gender_presentation_if_needed' => 'medium',
                ],
                'reference_photo_recommendations' => 'استخدم صورة 1 كمرجع وجه أساسي.',
                'warnings' => 'لا توجد تحذيرات مهمة.',
            ])),
        ]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $model = AiModel::whereHas('provider', fn ($query) => $query->where('driver', 'openai'))->firstOrFail();

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.character-profile.analyze', $project), [
                'model_code' => $model->code,
                'reference_photo_indices' => [0],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => data_get($request->data(), 'input.0.content.1.type') === 'input_image'
            && str_starts_with((string) data_get($request->data(), 'input.0.content.1.image_url'), 'data:image/'));

        $job = SceneGenerationJob::where('job_type', 'character_analysis')->firstOrFail();
        $this->assertSame('completed', $job->status);
        $this->assertSame('openai', $job->provider->driver);
        $this->assertNotEmpty($job->prompt_snapshot);
        $storedPayload = json_encode([
            'request' => $job->provider_request_json,
            'response' => $job->provider_response_json,
            'prompt' => $job->prompt_snapshot,
            'inputs' => $job->input_assets_json,
        ], JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('data:image/', $storedPayload);
        $this->assertStringNotContainsString('image-bytes', $storedPayload);
        $this->assertStringNotContainsString('output_text', $storedPayload);
        $this->assertArrayNotHasKey('raw', $job->provider_response_json);
        $this->assertSame('طفلة مصرية بملامح هادئة وابتسامة طبيعية.', data_get($job->provider_response_json, 'structured_result.appearance_summary'));
        $this->assertSame(200, data_get($job->provider_response_json, 'usage.total_tokens'));
        $this->assertSame('resp_test', data_get($job->provider_response_json, 'metadata.response_id'));

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.character-profile.apply-analysis', $project))
            ->assertRedirect()
            ->assertSessionHas('success');

        $profile = $project->characterProfile()->firstOrFail();
        $this->assertSame('طفلة مصرية بملامح هادئة وابتسامة طبيعية.', $profile->appearance_summary);
        $this->assertSame('عينان بنيتان وخدان ناعمان.', $profile->eye_color_traits);
        $this->assertSame('وجه طفولي مستدير قليلًا.', $profile->face_shape_notes);
    }

    public function test_openai_actions_require_active_default_model_for_capability(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableOpenAi();
        Http::fake();

        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        $settings = $provider->settings_json;
        $settings['default_models'] = [];
        $provider->update(['settings_json' => $settings]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon',
            'story_text' => 'The child looks at the moon.',
        ]);
        $model = AiModel::whereHas('provider', fn ($query) => $query->where('driver', 'openai'))->firstOrFail();

        $this->actingAs($this->adminUser())
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('فعّل نموذج OpenAI افتراضي بقدرة vision_to_text قبل تحليل صور الطفل')
            ->assertSee('فعّل نموذج OpenAI افتراضي بقدرة scene_extraction قبل استخدام استخراج المشاهد')
            ->assertSee('فعّل نموذج OpenAI بقدرة prompt_enhancement');

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.character-profile.analyze', $project), [
                'model_code' => $model->code,
                'reference_photo_indices' => [0],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('character_analysis');

        $this->assertDatabaseMissing('scene_generation_jobs', ['job_type' => 'character_analysis']);
        Http::assertNothingSent();
    }

    public function test_generation_cannot_start_when_fal_is_disabled_or_key_is_missing(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        Config::set('production_studio.ai.fal.enabled', false);
        Config::set('production_studio.ai.fal.key', 'test-key');

        $this->actingAs($admin)
            ->from(route('admin.production-studio.show', $project))
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertRedirect(route('admin.production-studio.show', $project))
            ->assertSessionHasErrors('ai_generation');

        Config::set('production_studio.ai.fal.enabled', true);
        Config::set('production_studio.ai.fal.key', null);

        $this->actingAs($admin)
            ->from(route('admin.production-studio.show', $project))
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertRedirect(route('admin.production-studio.show', $project))
            ->assertSessionHasErrors('ai_generation');

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_unauthorized_users_cannot_submit_view_approve_or_download_generated_assets(): void
    {
        Storage::fake('local');

        $owner = $this->adminUser();
        $project = $this->projectWithApprovedPhoto();
        $asset = $project->assets()->create([
            'asset_type' => 'character_sheet',
            'label' => 'Private Sheet',
            'status' => 'under_review',
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/private.png',
        ]);
        Storage::disk('local')->put($asset->file_path, 'private-image');

        $limited = $this->adminWithPermissions(['production_studio.view']);

        $this->actingAs($limited)
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertForbidden();

        $this->actingAs($limited)
            ->get(route('admin.production-studio.assets.show', [$project, $asset]))
            ->assertForbidden();

        $this->actingAs($limited)
            ->post(route('admin.production-studio.assets.approve', [$project, $asset]))
            ->assertForbidden();

        $this->actingAs($limited)
            ->delete(route('admin.production-studio.assets.delete', [$project, $asset]))
            ->assertForbidden();

        Storage::disk('local')->assertExists($asset->file_path);

        $assetResponse = $this->actingAs($owner)
            ->get(route('admin.production-studio.assets.show', [$project, $asset]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('private', $assetResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=604800', $assetResponse->headers->get('Cache-Control'));

        $etag = $this->actingAs($owner)
            ->get(route('admin.production-studio.assets.show', [$project, $asset]))
            ->headers
            ->get('ETag');

        $this->assertNotEmpty($etag);

        $this->actingAs($owner)
            ->withHeaders(['If-None-Match' => $etag])
            ->get(route('admin.production-studio.assets.show', [$project, $asset]))
            ->assertStatus(304);
    }

    public function test_authorized_user_can_create_queued_character_sheet_generation_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();

        $this->assertSame('queued', $job->status);
        $this->assertSame('character_sheet', $job->job_type);
        $this->assertSame('character_sheet', $job->generation_mode);
        $this->assertStringContainsString('Approved Child Reference Illustration requirements', $job->prompt_snapshot);
        $this->assertStringContainsString('clean child identity reference illustration', $job->prompt_snapshot);
        $this->assertStringContainsString('This is not a story scene, book cover, poster, product mockup, or page layout', $job->prompt_snapshot);
        $this->assertStringContainsString('portrait or half-body identity reference', $job->prompt_snapshot);
        $this->assertStringContainsString('No props of any kind: no book, no open pages', $job->prompt_snapshot);
        $this->assertStringContainsString('Use plain clothing with no visible logos, no school badge', $job->prompt_snapshot);
        $this->assertStringContainsString('Preserve real-photo individuality and asymmetry', $job->prompt_snapshot);
        $this->assertStringContainsString('Preserve the original hairstyle arrangement from the primary face reference', $job->prompt_snapshot);
        $this->assertStringContainsString('remove it and replace that area with plain fabric', $job->prompt_snapshot);
        $this->assertStringContainsString('Keep the face closer to a photo-derived portrait than a cartoon avatar', $job->prompt_snapshot);
        $this->assertStringNotContainsString('Selected story title for context only', $job->prompt_snapshot);
        $this->assertStringNotContainsString('A3 landscape two-page story spread', $job->prompt_snapshot);
        $this->assertSame('0.0300', (string) $job->estimated_cost);
        $this->assertSame('estimated', $job->cost_source);
        Queue::assertPushed(SubmitAiGenerationJob::class);
    }

    public function test_cannot_generate_child_reference_when_character_profile_is_incomplete(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $project->characterProfile()->update([
            'appearance_summary' => null,
            'hair_details' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_cannot_generate_without_reference_image_when_model_requires_image_url(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $project->characterProfile()->update([
            'approved_reference_photos' => [],
            'primary_face_reference_index' => null,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.ai.cover', $project), $this->generationPayload([
                'model_code' => config('production_studio.ai.fal.default_premium_model'),
                'reference_photo_indices' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_ajax_generation_returns_inline_job_status_payload(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertCreated()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.status', 'queued')
            ->assertJsonPath('job.job_type', 'character_sheet')
            ->assertJsonStructure(['message', 'status_url', 'job' => ['id', 'status', 'job_type', 'estimated_cost']]);

        $job = SceneGenerationJob::firstOrFail();
        $this->assertSame($job->id, $response->json('job.id'));

        $this->actingAs($admin)
            ->getJson(route('admin.production-studio.ai.jobs.status', [$project, $job]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('job.id', $job->id)
            ->assertJsonPath('job.status', 'queued');

        Queue::assertPushed(SubmitAiGenerationJob::class);
    }

    public function test_generation_rejects_child_reference_photos_that_are_not_approved_for_studio(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        Storage::disk('local')->put('orders/photos/unapproved.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png', 'orders/photos/unapproved.png']);

        $this->actingAs($admin)
            ->from(route('admin.production-studio.show', $project))
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload([
                'reference_photo_indices' => [1],
            ]))
            ->assertRedirect(route('admin.production-studio.show', $project))
            ->assertSessionHasErrors('ai_generation');

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_authorized_user_can_create_queued_scene_generation_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $project->update([
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'رينا',
            'child_story_role' => 'الأميرة رينا',
            'personalization_status' => 'personalized',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon Scene',
            'story_text' => 'رينا walks under moonlight.',
            'visual_direction' => 'رينا مبتسمة appears in a calm magical night garden.',
            'child_action_pose' => 'رينا holds a small lantern.',
            'text_safe_area_notes' => 'Keep calm sky area on the left.',
            'personalized_hero_name' => 'رينا',
            'template_hero_name' => 'جنا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

        $this->actingAs($admin)
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();

        $this->assertSame($scene->id, $job->production_scene_id);
        $this->assertSame('scene_image', $job->job_type);
        $this->assertSame('character_scene', $job->generation_mode);
        $this->assertStringContainsString('Moon Scene', $job->prompt_snapshot);
        $this->assertStringContainsString('Keep the child\'s real photo-derived face, hairstyle, skin tone, apparent age, and body proportions consistent in every illustration.', $job->prompt_snapshot);
        $this->assertStringContainsString('Do not transform the child into a different-looking character. Use the real photo-derived face as the identity anchor', $job->prompt_snapshot);
        $this->assertStringContainsString('The scene child must use the same real photo-derived face, hairstyle, skin tone, apparent age, and body proportions', $job->prompt_snapshot);
        $this->assertStringNotContainsString('جنا', $job->prompt_snapshot);
        $this->assertSame('جنا', data_get($job->provider_request_json, 'personalization_debug.template_hero_name'));
        $this->assertSame('رينا', data_get($job->provider_request_json, 'personalization_debug.child_hero_name'));
        $this->assertStringContainsString('Use reference images for identity only, not for composition.', $job->prompt_snapshot);
        $this->assertStringContainsString('If the reference image conflicts with the scene, keep only the child identity and replace the background/composition with the described scene.', $job->prompt_snapshot);
        $this->assertStringContainsString('Create a new wide landscape scene composition from scratch.', $job->prompt_snapshot);
        $this->assertStringContainsString('CRITICAL OUTPUT TYPE — WIDE STORY SCENE, NOT A CHARACTER PORTRAIT', $job->prompt_snapshot);
        $this->assertStringContainsString('Fill both halves of the landscape canvas with continuous artwork', $job->prompt_snapshot);
        $this->assertStringContainsString('Generate pure story illustration only. Do not create a poster, title card, social graphic, thumbnail, book cover, profile card, or educational flashcard.', $job->prompt_snapshot);
        $this->assertStringContainsString('Do not render any visible text, letters, captions, headings, labels, speech bubbles, signs, or symbols in any language.', $job->prompt_snapshot);
        $this->assertStringContainsString('Korean text', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('title-card layout', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('centered portrait crop', $job->negative_prompt_snapshot);
        $this->assertStringNotContainsString('fantasy background', $job->negative_prompt_snapshot);
        $this->assertStringNotContainsString('no props', strtolower($job->prompt_snapshot));
        $this->assertStringNotContainsString('Egyptian child with natural face and warm smile.', $job->prompt_snapshot);
        $this->assertStringContainsString('A result is incorrect if it replaces this location, time, action, or any mandatory key object', $job->prompt_snapshot);
        $this->assertStringContainsString('Do not reuse the usual smile when the scene requires worry', $job->prompt_snapshot);
        $this->assertStringNotContainsString('رينا مبتسمة', $job->prompt_snapshot);
        $this->assertLessThan(6500, mb_strlen($job->prompt_snapshot));
        $this->assertFalse($job->input_assets_json['character_sheet_first']);
        $this->assertSame('primary_face_reference', $job->input_assets_json['reference_assets'][0]['type']);
        $this->assertSame('approved_child_reference_illustration', $job->input_assets_json['reference_assets'][1]['type']);
        Queue::assertPushed(SubmitAiGenerationJob::class);

        Http::fake([
            'https://queue.fal.run/*' => Http::response([
                'request_id' => 'fal-scene-request',
                'status' => 'IN_QUEUE',
                'status_url' => 'https://fal.test/status',
                'response_url' => 'https://fal.test/result',
            ]),
        ]);

        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return str_contains((string) data_get($payload, 'image_url'), base64_encode('image-bytes'))
                && ! str_contains((string) data_get($payload, 'image_url'), base64_encode('approved-reference-bytes'))
                && data_get($payload, 'resolution_mode') === '3:2'
                && data_get($payload, 'guidance_scale') === 4.5
                && data_get($payload, 'num_inference_steps') === 32;
        });
    }

    public function test_fal_pro_scene_uses_supported_landscape_aspect_ratio_schema(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $project->update([
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'رينا',
            'child_story_role' => 'الأميرة رينا',
            'personalization_status' => 'personalized',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'مشهد القلعة',
            'story_text' => 'رينا تراقب الفانوس من القلعة.',
            'visual_direction' => 'مشهد واسع تظهر فيه رينا داخل القلعة مع الجبل والضباب.',
            'child_action_pose' => 'رينا تقف عند نافذة القصر.',
            'personalized_hero_name' => 'رينا',
            'template_hero_name' => 'جنا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'model_code' => 'fal-ai/flux-pro/kontext',
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::fake([
            'https://queue.fal.run/*' => Http::response([
                'request_id' => 'fal-pro-scene-request',
                'status' => 'IN_QUEUE',
                'status_url' => 'https://fal.test/status',
                'response_url' => 'https://fal.test/result',
            ]),
        ]);

        $job = SceneGenerationJob::firstOrFail();
        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return data_get($payload, 'aspect_ratio') === '3:2'
                && ! array_key_exists('resolution_mode', $payload)
                && ! array_key_exists('negative_prompt', $payload)
                && ! array_key_exists('num_inference_steps', $payload);
        });
    }

    public function test_scene_generation_allows_narrative_text_without_repeating_child_name(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png'], ['child_name' => 'رقية']);
        $project->update([
            'template_hero_name' => 'مريم',
            'personalized_hero_name' => 'رقية',
            'child_story_role' => 'الأميرة رقية',
            'personalization_status' => 'needs_review',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 2,
            'title' => 'ثلاث ليالٍ فقط',
            'story_text' => 'باب القصر انفتح بقوة، ودخل رجل عجوز مقطوع النفس يحذر من انطفاء الفانوس.',
            'visual_direction' => 'رقية واقفة بجانب رجل عجوز داخل قاعة القصر وتراقبه بقلق.',
            'child_action_pose' => 'رقية تقف بثبات وتنظر إلى الرجل العجوز باهتمام.',
            'text_safe_area_notes' => 'اترك مساحة هادئة للنص في الجانب الأيسر.',
            'personalized_hero_name' => 'رقية',
            'template_hero_name' => 'مريم',
            'personalization_status' => 'needs_review',
        ]);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

        $this->assertStringNotContainsString('رقية', $scene->story_text);
        $this->assertTrue($scene->isPersonalizedForImageGeneration());

        $this->actingAs($admin)
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();
        $this->assertStringContainsString($scene->story_text, $job->prompt_snapshot);
        $this->assertStringContainsString('رقية', $job->prompt_snapshot);

        app(ScenePersonalizationService::class)->refreshSceneStatus($scene);
        $this->assertSame('personalized', $scene->fresh()->personalization_status);
        Queue::assertPushed(SubmitAiGenerationJob::class);
    }

    public function test_portrait_scene_output_is_rejected_before_it_becomes_an_asset(): void
    {
        Storage::fake('local');
        $this->enableFal('secret-key');

        $project = $this->projectWithApprovedPhoto();
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Landscape scene',
            'story_text' => 'Scene story text.',
        ]);
        $job = $project->generationJobs()->create([
            'production_scene_id' => $scene->id,
            'job_type' => 'scene_image',
            'generation_mode' => 'character_scene',
            'ai_provider_id' => AiProvider::where('driver', 'fal')->value('id'),
            'ai_model_id' => AiModel::where('code', 'fal-ai/flux-kontext/dev')->value('id'),
            'status' => 'processing',
            'external_request_id' => 'portrait-scene',
            'external_status_url' => 'https://fal.test/status',
            'external_response_url' => 'https://fal.test/result',
            'prompt_snapshot' => 'Landscape scene prompt',
        ]);
        $portraitPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAUCAIAAAA7jDsBAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQokWNgGAWjgD4AAAJsAAGClu6+AAAAAElFTkSuQmCC');

        Http::fake([
            'https://fal.test/status' => Http::response(['status' => 'COMPLETED']),
            'https://fal.test/result' => Http::response(['images' => [['url' => 'https://fal.test/portrait.png']]]),
            'https://fal.test/portrait.png' => Http::response($portraitPng, 200, ['Content-Type' => 'image/png']),
        ]);

        (new PollAiGenerationJob($job->id))->handle(app(AiProviderManager::class));

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertStringContainsString('صورة المشهد لأنها عمودية', $job->fresh()->error_message);
        $this->assertDatabaseCount('production_project_assets', 0);
    }

    public function test_story_scenes_can_be_extracted_with_deterministic_parser_without_openai_call(): void
    {
        $project = $this->projectWithApprovedPhoto();
        $content = collect(range(1, 13))
            ->map(fn (int $i): string => "Scene {$i}: مغامرة جنا {$i}\nالأميرة جنا تحل اللغز {$i} بمساعدة بابا.")
            ->implode("\n\n");
        $project->storyVersions()->create([
            'version_number' => 1,
            'title' => 'Draft',
            'full_story_content' => $content,
            'status' => 'draft',
            'created_by_user_id' => $this->adminUser()->id,
        ]);

        Http::fake();

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.extract-scenes', $project))
            ->assertRedirect()
            ->assertSessionHas('success');

        Http::assertNothingSent();

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.apply-scenes', $project), [
                'detected_hero_name' => 'جنا',
                'personalization_action' => 'confirm',
                'confirm_personalization' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(13, $project->scenes()->count());
        $this->assertSame('الأميرة رينا تحل اللغز 1 بمساعدة بابا.', $project->scenes()->where('scene_number', 1)->first()->story_text);
    }

    public function test_building_scenes_personalizes_template_hero_without_changing_story_master_or_supporting_characters(): void
    {
        $project = $this->projectWithApprovedPhoto(orderOverrides: [
            'child_name' => 'ديدا',
            'child_gender' => 'girl',
        ]);
        $originalStory = $project->order->story->full_desc;
        $content = collect(range(1, 13))
            ->map(fn (int $i): string => "مشهد {$i}: جنا واللغز {$i}\nالأميرة جنا تبحث عن المفتاح مع ريتاج، ثم يساعدهما بابا.")
            ->implode("\n\n");
        $version = $project->storyVersions()->create([
            'version_number' => 1,
            'title' => 'قالب جنا',
            'full_story_content' => $content,
            'status' => 'draft',
            'created_by_user_id' => $this->adminUser()->id,
        ]);

        Http::fake();

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.extract-scenes', $project), [
                'source_version_id' => $version->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->adminUser())
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('بطل القالب المكتشف')
            ->assertSee('جنا')
            ->assertSee('ديدا')
            ->assertSee('تأكيد تخصيص المشاهد باسم ديدا');

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.apply-scenes', $project), [
                'detected_hero_name' => 'جنا',
                'personalization_action' => 'confirm',
                'confirm_personalization' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $scene = $project->scenes()->where('scene_number', 1)->firstOrFail();
        $this->assertStringContainsString('ديدا', $scene->story_text);
        $this->assertStringNotContainsString('جنا', $scene->story_text);
        $this->assertStringContainsString('ريتاج', $scene->story_text);
        $this->assertStringContainsString('بابا', $scene->story_text);
        $this->assertStringContainsString('ديدا', $scene->visual_direction);
        $this->assertStringNotContainsString('جنا', $scene->visual_direction);
        $this->assertSame('personalized', $scene->personalization_status);
        $this->assertStringContainsString('جنا', (string) data_get($scene->original_template_data_json, 'written_text'));
        $this->assertSame($originalStory, $project->order->story->fresh()->full_desc);
        $this->assertSame($content, $version->fresh()->full_story_content);
        Http::assertNothingSent();
    }

    public function test_gender_adaptation_uses_mocked_openai_and_keeps_supporting_characters(): void
    {
        $this->enableOpenAi();
        $project = $this->projectWithApprovedPhoto(orderOverrides: [
            'child_name' => 'آدم',
            'child_gender' => 'boy',
        ]);
        $content = collect(range(1, 13))
            ->map(fn (int $i): string => "مشهد {$i}: مغامرة جنا\nالأميرة جنا تقف مع ريتاج في المشهد {$i}.")
            ->implode("\n\n");
        $project->storyVersions()->create([
            'version_number' => 1,
            'title' => 'قالب أميرة',
            'full_story_content' => $content,
            'status' => 'draft',
            'created_by_user_id' => $this->adminUser()->id,
        ]);
        $payload = $this->openAiScenePayload();
        $payload['template_hero_gender'] = 'girl';
        $payload['gender_adaptation_applied'] = true;
        $payload['supporting_character_names'] = ['ريتاج'];
        $payload['scenes'] = collect($payload['scenes'])->map(function (array $scene): array {
            $scene['written_text'] = 'آدم يقف مع ريتاج ويتابع المغامرة.';
            $scene['visual_direction'] = 'آدم هو البطل الرئيسي وريتاج شخصية مساندة.';
            $scene['child_action_pose'] = 'آدم يتحرك بثقة داخل المشهد.';

            return $scene;
        })->all();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiJsonResponse($payload)),
        ]);

        $model = AiModel::whereHas('provider', fn ($query) => $query->where('driver', 'openai'))->firstOrFail();
        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.extract-scenes', $project), ['model_code' => $model->code])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.apply-scenes', $project), [
                'detected_hero_name' => 'جنا',
                'personalization_action' => 'confirm',
                'confirm_personalization' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $scene = $project->scenes()->firstOrFail();
        $this->assertStringContainsString('آدم', $scene->story_text);
        $this->assertStringContainsString('ريتاج', $scene->story_text);
        $this->assertStringNotContainsString('جنا', $scene->story_text);
        $this->assertSame('personalized', $scene->personalization_status);
        Http::assertSent(fn ($request): bool => str_contains(json_encode($request->data(), JSON_UNESCAPED_UNICODE), 'Current child name: آدم'));
    }

    public function test_scene_generation_is_blocked_when_old_template_hero_remains(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png'], ['child_name' => 'ديدا']);
        $project->update(['template_hero_name' => 'جنا', 'personalized_hero_name' => 'ديدا']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'جنا في القصر',
            'story_text' => 'جنا تقف في القصر.',
            'visual_direction' => 'جنا هي البطلة الرئيسية.',
            'child_action_pose' => 'جنا تمسك الفانوس.',
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'ديدا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

        $this->actingAs($this->adminUser())
            ->postJson(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['message' => 'خصّص المشهد باسم الطفل قبل توليد الصورة. اسم بطل القالب ما زال موجودًا في: title، story_text، visual_direction، child_action_pose.']);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('scene_generation_jobs', 0);
    }

    public function test_scene_prompt_uses_personalized_context_and_includes_debug_flags(): void
    {
        $project = $this->projectWithApprovedPhoto(orderOverrides: ['child_name' => 'ديدا']);
        $project->update([
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'ديدا',
            'personalization_status' => 'personalized',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'ديدا والفانوس',
            'story_text' => 'ديدا تنظر إلى الفانوس.',
            'visual_direction' => 'ديدا تقف أمام القصر في مشهد A3 أفقي.',
            'child_action_pose' => 'ديدا تمسك الفانوس.',
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'ديدا',
            'personalization_status' => 'personalized',
        ]);

        $compiled = app(ProductionPromptCompiler::class)->compile($project, $scene, 'scene_image', 'premium_storybook');

        $this->assertStringContainsString('personalization_applied: true', $compiled['prompt']);
        $this->assertStringContainsString('template_hero_name: [replaced before image generation]', $compiled['prompt']);
        $this->assertStringContainsString('child_hero_name: ديدا', $compiled['prompt']);
        $this->assertStringContainsString('old_hero_name_remaining: false', $compiled['prompt']);
        $this->assertStringContainsString('personalized_scene_context_included: true', $compiled['prompt']);
        $this->assertStringContainsString('Scene story text context: ديدا تنظر إلى الفانوس.', $compiled['prompt']);
        $this->assertStringNotContainsString('جنا', $compiled['prompt']);
        $this->assertSame('جنا', data_get($compiled, 'personalization_debug.template_hero_name'));
    }

    public function test_story_scenes_can_be_extracted_through_mocked_openai_fallback(): void
    {
        $this->enableOpenAi();
        $project = $this->projectWithApprovedPhoto();
        $model = AiModel::whereHas('provider', fn ($query) => $query->where('driver', 'openai'))->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiJsonResponse($this->openAiScenePayload())),
        ]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.extract-scenes', $project), [
                'model_code' => $model->code,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.story-versions.apply-scenes', $project), [
                'detected_hero_name' => 'جنا',
                'personalization_action' => 'confirm',
                'confirm_personalization' => true,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $scene = $project->scenes()->where('scene_number', 1)->firstOrFail();
        $this->assertSame('Scene title 1', $scene->title);
        $this->assertSame('رينا completes scene 1', $scene->story_text);
        $this->assertSame('Show رينا in visual direction 1', $scene->visual_direction);
        $this->assertSame('رينا performs child pose 1', $scene->child_action_pose);
        $this->assertSame('Environment 1', $scene->environment);
        $this->assertSame('Safe text area 1', $scene->text_safe_area_notes);
        $this->assertDatabaseHas('scene_generation_jobs', ['job_type' => 'scene_extraction', 'generation_mode' => 'scene_extraction']);

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $body = json_encode($data, JSON_THROW_ON_ERROR);

            return str_contains($body, 'HeroKid fixed booklet structure')
                && str_contains($body, 'one single connected full-width A3 landscape illustration')
                && str_contains($body, 'Do not ask for generated text, letters, labels, signs, titles, captions, or logos inside the image')
                && ($data['max_output_tokens'] ?? null) === 6000;
        });
    }

    public function test_improve_visual_direction_uses_openai_preview_then_apply(): void
    {
        $this->enableOpenAi();
        $project = $this->projectWithApprovedPhoto();
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon',
            'story_text' => 'The child looks at the moon.',
        ]);
        $model = AiModel::whereHas('provider', fn ($query) => $query->where('driver', 'openai'))->firstOrFail();

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiJsonResponse([
                'visual_direction' => 'A calm rooftop under a soft moon.',
                'child_action_pose' => 'The child points gently at the moon.',
                'environment' => 'Rooftop garden',
                'mood_lighting' => 'Soft blue moonlight',
                'supporting_characters' => 'No extra children',
                'key_objects' => 'Moon, small lantern',
                'continuity_notes' => 'Keep pajamas consistent.',
                'safe_text_area_notes' => 'Use quiet sky area for text.',
            ])),
        ]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.scenes.improve', [$project, $scene]), ['model_code' => $model->code])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNull($scene->fresh()->visual_direction);

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.scenes.apply-improvement', [$project, $scene]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $scene->refresh();
        $this->assertSame('A calm rooftop under a soft moon.', $scene->visual_direction);
        $this->assertSame('The child points gently at the moon.', $scene->child_action_pose);
        $this->assertSame('Use quiet sky area for text.', $scene->text_safe_area_notes);

        Http::assertSent(function ($request): bool {
            $data = $request->data();
            $body = json_encode($data, JSON_THROW_ON_ERROR);

            return str_contains($body, 'one connected A3 landscape two-page reader spread')
                && str_contains($body, 'Do not describe two separate unrelated illustrations')
                && str_contains($body, 'No Arabic text or any other visible text should be requested inside the image')
                && ($data['max_output_tokens'] ?? null) === 1500;
        });
    }

    public function test_scene_generation_is_blocked_when_scene_context_is_missing(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Missing context',
            'visual_direction' => 'A nice garden.',
        ]);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

        $this->actingAs($this->adminUser())
            ->postJson(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_scene_generation_requires_approved_child_reference_illustration(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon Scene',
            'visual_direction' => 'A calm magical night garden.',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'character_sheet_id' => null,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false);

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_authorized_user_can_create_queued_cover_generation_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

        $this->actingAs($admin)
            ->post(route('admin.production-studio.ai.cover', $project), $this->generationPayload([
                'model_code' => config('production_studio.ai.fal.default_premium_model'),
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();

        $this->assertNull($job->production_scene_id);
        $this->assertSame('cover_image', $job->job_type);
        $this->assertSame('cover_generation', $job->generation_mode);
        $this->assertStringContainsString('Cover artwork requirements', $job->prompt_snapshot);
        $this->assertStringContainsString('do not render final cover text', $job->prompt_snapshot);
        $this->assertStringContainsString('Keep the child\'s real photo-derived face, hairstyle, skin tone, apparent age, and body proportions consistent in every illustration.', $job->prompt_snapshot);
        $this->assertStringContainsString('The cover child must use the same real photo-derived face and apparent age from the original references.', $job->prompt_snapshot);
        $this->assertSame('0.0800', (string) $job->estimated_cost);
        Queue::assertPushed(SubmitAiGenerationJob::class);
    }

    public function test_cover_generation_requires_explicit_primary_face_fallback_without_approved_reference(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.ai.cover', $project), $this->generationPayload([
                'model_code' => config('production_studio.ai.fal.default_premium_model'),
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'يفضل اعتماد صورة مرجعية للطفل قبل توليد الغلاف.');

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();

        $this->actingAs($admin)
            ->postJson(route('admin.production-studio.ai.cover', $project), $this->generationPayload([
                'model_code' => config('production_studio.ai.fal.default_premium_model'),
                'confirm_primary_face_cover_fallback' => '1',
            ]))
            ->assertCreated()
            ->assertJsonPath('ok', true);

        $job = SceneGenerationJob::firstOrFail();
        $this->assertNull($job->input_assets_json['character_sheet_id']);
        $this->assertSame(1, $job->input_assets_json['input_count']);
        Queue::assertPushed(SubmitAiGenerationJob::class);
    }

    public function test_fal_provider_can_be_mocked_and_stores_output_asset_and_actual_cost(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal('secret-key');

        Http::fake([
            'https://queue.fal.run/*' => Http::response([
                'request_id' => 'fal-request-1',
                'status' => 'IN_QUEUE',
                'status_url' => 'https://fal.test/status',
                'response_url' => 'https://fal.test/result',
            ]),
            'https://fal.test/status' => Http::response(['status' => 'COMPLETED']),
            'https://fal.test/result' => Http::response([
                'images' => [['url' => 'https://fal.test/output.png']],
                'metrics' => ['cost' => '0.0412'],
            ]),
            'https://fal.test/output.png' => Http::response('generated-image-bytes', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->actingAs($this->adminUser());
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        $this->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())->assertRedirect();
        $job = SceneGenerationJob::firstOrFail();

        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));
        (new PollAiGenerationJob($job->id))->handle(app(AiProviderManager::class));

        $job = $job->fresh();
        $asset = ProductionProjectAsset::firstOrFail();

        $this->assertSame('completed', $job->status);
        $this->assertSame('0.0412', (string) $job->actual_cost);
        $this->assertSame('provider_actual', $job->cost_source);
        $this->assertSame('under_review', $asset->status);
        $this->assertSame('character_sheet', $asset->asset_type);
        $this->assertNotEmpty(data_get($job->input_assets_json, 'reference_assets'));
        Http::assertSent(fn ($request) => str_starts_with((string) data_get($request->data(), 'image_url'), 'data:image/'));
        Storage::disk('local')->assertExists($asset->file_path);
        Storage::disk('public')->assertMissing($asset->file_path);

        $this->actingAs($this->adminUser())
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('حذف نهائي');

        $this->actingAs($this->adminUser())
            ->deleteJson(route('admin.production-studio.assets.delete', [$project, $asset]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_asset_id', $asset->id);

        Storage::disk('local')->assertMissing($asset->file_path);
        Storage::disk('local')->assertExists('orders/photos/kid.png');
        $this->assertDatabaseMissing('production_project_assets', ['id' => $asset->id]);
        $this->assertDatabaseHas('scene_generation_jobs', [
            'id' => $job->id,
            'status' => 'completed',
            'actual_cost' => '0.0412',
            'output_asset_path' => null,
        ]);
        $this->assertNull(data_get($job->fresh()->output_metadata_json, 'asset_id'));
        $this->assertNotNull(data_get($job->fresh()->output_metadata_json, 'asset_deleted_at'));
        $this->assertDatabaseHas('production_project_activity_logs', [
            'production_project_id' => $project->id,
            'action' => 'ai_asset.deleted',
        ]);
    }

    public function test_fal_kontext_request_omits_unsupported_multiple_reference_field(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/face.png', 'face-bytes');
        Storage::disk('local')->put('orders/photos/body.png', 'body-bytes');
        Storage::disk('local')->put('orders/photos/style.png', 'style-bytes');
        $this->enableFal('secret-key');

        Http::fake([
            'https://queue.fal.run/*' => Http::response([
                'request_id' => 'fal-request-refs',
                'status' => 'IN_QUEUE',
                'status_url' => 'https://fal.test/status',
                'response_url' => 'https://fal.test/result',
            ]),
        ]);

        $project = $this->projectWithApprovedPhoto([
            'orders/photos/face.png',
            'orders/photos/body.png',
            'orders/photos/style.png',
        ]);
        $project->characterProfile()->update([
            'approved_reference_photos' => [0, 1, 2],
            'reference_photo_selection' => [0, 1, 2],
            'primary_face_reference_index' => 0,
            'body_reference_index' => 1,
            'style_reference_index' => 2,
        ]);

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload([
                'reference_photo_indices' => [0, 1, 2],
            ]))
            ->assertRedirect();

        $job = SceneGenerationJob::firstOrFail();
        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        $job = $job->fresh();
        $this->assertSame(3, $job->input_assets_json['input_count']);
        $this->assertCount(3, $job->input_assets_json['reference_assets']);

        Http::assertSent(function ($request) {
            $payload = $request->data();

            return str_starts_with((string) data_get($payload, 'image_url'), 'data:image/')
                && ! array_key_exists('image_urls', $payload);
        });
    }

    public function test_openai_image_model_can_generate_private_scene_asset_without_storing_image_payload(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableOpenAi('sk-openai-image-test');
        $generatedBytes = $this->validPngBytes(24, 16, true);

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_test_123',
                'created' => 1234567890,
                'data' => [
                    ['b64_json' => base64_encode($generatedBytes)],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon Scene',
            'story_text' => 'رينا watches a quiet moonlit castle from the window.',
            'visual_direction' => 'A wide A3 landscape castle scene with رينا, fog, and a blank quiet area for Arabic text.',
            'child_action_pose' => 'رينا stands naturally near the window looking at the distant lantern.',
            'text_safe_area_notes' => 'Reserve a quiet lower-left blank area.',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', [
            'status' => 'approved',
            'is_primary' => true,
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/reference.png',
        ]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes(24, 16, true));

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'model_code' => 'gpt-image-2',
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();
        $this->assertSame('openai', $job->provider->driver);
        $this->assertSame('gpt-image-2', $job->model->code);
        $this->assertSame('0.0410', (string) $job->estimated_cost);

        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        $submitted = $job->fresh();
        $this->assertSame('processing', $submitted->status);
        $this->assertStringStartsWith('local://production-studio/projects/'.$project->id.'/openai-temp/', $submitted->external_response_url);
        $this->assertStringNotContainsString(base64_encode($generatedBytes), json_encode($submitted->provider_response_json));
        $this->assertSame('b64_json', data_get($submitted->provider_response_json, 'output_source'));

        (new PollAiGenerationJob($job->id))->handle(app(AiProviderManager::class));

        $completed = $job->fresh();
        $asset = ProductionProjectAsset::where('scene_generation_job_id', $job->id)->firstOrFail();

        $this->assertSame('completed', $completed->status);
        $this->assertSame('estimate_fallback', $completed->cost_source);
        $this->assertSame('openai', data_get($asset->metadata_json, 'provider'));
        $this->assertSame('gpt-image-2', data_get($asset->metadata_json, 'model'));
        Storage::disk('local')->assertExists($asset->file_path);
        Storage::disk('public')->assertMissing($asset->file_path);

        $this->assertSame('gpt-image-2', data_get($completed->provider_request_json, 'model_code'));
        $this->assertSame('medium', data_get($completed->provider_request_json, 'model_settings.quality', 'medium'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/images/edits'
            && ! str_contains($request->body(), 'input_fidelity'));
    }

    public function test_gpt_image_1_keeps_high_input_fidelity_setting(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableOpenAi('sk-openai-image-test');

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_test_fidelity',
                'data' => [['b64_json' => base64_encode($this->validPngBytes())]],
            ]),
        ]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'story_text' => 'رينا تقف عند نافذة القصر.',
            'visual_direction' => 'تظهر رينا في مشهد أفقي واسع داخل القصر.',
            'child_action_pose' => 'رينا تنظر من النافذة.',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', [
            'status' => 'approved',
            'is_primary' => true,
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/reference.png',
        ]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes());

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'model_code' => 'gpt-image-1',
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();
        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/images/edits'
            && str_contains($request->body(), 'input_fidelity')
            && str_contains($request->body(), 'high'));
    }

    public function test_openai_invalid_image_response_is_sanitized_for_admins(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableOpenAi('sk-private-openai-key');

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'error' => [
                    'message' => 'Invalid image file or mode for image 1. Authorization sk-private-openai-key',
                    'code' => 'invalid_image',
                    'type' => 'invalid_request_error',
                    'param' => 'image',
                ],
            ], 400, ['x-request-id' => 'req_safe_123']),
        ]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Castle scene',
            'story_text' => 'رينا تقف عند نافذة القصر ليلًا.',
            'visual_direction' => 'مشهد أفقي واسع تظهر فيه رينا داخل قصر ليلي أمام جبل وفانوس مطفأ.',
            'child_action_pose' => 'رينا تقف عند النافذة بقلق.',
            'environment' => 'قصر حجري وجبل ضباب.',
            'mood_lighting' => 'ليل وضوء قمر خافت.',
            'key_objects' => 'فانوس ضخم مطفأ.',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', [
            'status' => 'approved',
            'is_primary' => true,
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/reference.png',
        ]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes(20, 20, true));

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'model_code' => 'gpt-image-2',
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();
        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('رفض OpenAI الصورة المرجعية بعد تجهيزها', $job->error_message);
        $this->assertStringContainsString('code=invalid_image', $job->error_message);
        $this->assertStringContainsString('request=req_safe_123', $job->error_message);
        $this->assertStringContainsString('client=hero-kid-generation-'.$job->id, $job->error_message);
        $this->assertStringNotContainsString('sk-private-openai-key', $job->error_message);
        $this->assertStringNotContainsString('Authorization', $job->error_message);
        $this->assertNull($job->provider_response_json);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->header('X-Client-Request-Id')[0] === 'hero-kid-generation-'.$job->id);
    }

    public function test_openai_safety_rejection_shows_safe_diagnostics_without_automatic_retry(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableOpenAi('sk-never-log-this');

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'error' => [
                    'message' => 'Request blocked by the safety system. sk-never-log-this',
                    'code' => 'content_policy_violation',
                    'type' => 'image_generation_error',
                    'param' => 'prompt',
                ],
            ], 400, ['x-request-id' => 'req_policy_456']),
        ]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 4,
            'story_text' => 'رينا تقف في القصر.',
            'visual_direction' => 'تظهر رينا داخل مشهد قصر آمن ومناسب للأطفال.',
            'child_action_pose' => 'رينا تقف بثبات.',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', [
            'status' => 'approved',
            'is_primary' => true,
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/reference.png',
        ]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes());

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'model_code' => 'gpt-image-2',
                'character_sheet_id' => $sheet->id,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();
        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        $job->refresh();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('رفض نظام أمان OpenAI هذا المشهد', $job->error_message);
        $this->assertStringContainsString('code=content_policy_violation', $job->error_message);
        $this->assertStringContainsString('request=req_policy_456', $job->error_message);
        $this->assertStringNotContainsString('sk-never-log-this', $job->error_message);
        Http::assertSentCount(1);
    }

    public function test_same_scene_can_have_multiple_versions_but_only_one_approved_final_image(): void
    {
        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto();
        $scene = $project->scenes()->create(['scene_number' => 1]);
        $assetOne = $this->asset($project, 'scene_image', ['production_scene_id' => $scene->id, 'version_number' => 1]);
        $assetTwo = $this->asset($project, 'scene_image', ['production_scene_id' => $scene->id, 'version_number' => 2]);

        $this->actingAs($admin);
        app(ApproveGeneratedAssetAction::class)->execute($assetOne);
        app(ApproveGeneratedAssetAction::class)->execute($assetTwo);

        $this->assertFalse($assetOne->fresh()->is_final);
        $this->assertTrue($assetTwo->fresh()->is_final);
        $this->assertSame(2, $project->assets()->where('asset_type', 'scene_image')->count());
    }

    public function test_only_one_primary_approved_character_sheet_exists_per_project(): void
    {
        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto();
        $assetOne = $this->asset($project, 'character_sheet', ['version_number' => 1]);
        $assetTwo = $this->asset($project, 'character_sheet', ['version_number' => 2]);

        $this->actingAs($admin);
        app(ApproveGeneratedAssetAction::class)->execute($assetOne);
        app(ApproveGeneratedAssetAction::class)->execute($assetTwo);

        $this->assertFalse($assetOne->fresh()->is_primary);
        $this->assertTrue($assetTwo->fresh()->is_primary);
    }

    public function test_failed_jobs_keep_safe_error_information_without_api_key(): void
    {
        $this->enableFal('secret-key');

        Http::fake([
            'https://fal.test/status' => Http::response([
                'status' => 'FAILED',
                'error' => ['message' => 'bad secret-key provider failure'],
            ]),
        ]);

        $project = $this->projectWithApprovedPhoto();
        $job = $project->generationJobs()->create([
            'job_type' => 'character_sheet',
            'generation_mode' => 'character_sheet',
            'ai_provider_id' => AiProvider::where('driver', 'fal')->value('id'),
            'ai_model_id' => AiModel::where('code', config('production_studio.ai.fal.default_model'))->value('id'),
            'status' => 'processing',
            'external_request_id' => 'fal-fail',
            'external_status_url' => 'https://fal.test/status',
            'prompt_snapshot' => 'Prompt',
        ]);

        (new PollAiGenerationJob($job->id))->handle(app(AiProviderManager::class));

        $this->assertSame('failed', $job->fresh()->status);
        $this->assertStringNotContainsString('secret-key', $job->fresh()->error_message);
    }

    public function test_prompt_snapshot_includes_identity_fidelity_and_forbids_fake_text(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertRedirect();

        $job = SceneGenerationJob::firstOrFail();

        $this->assertStringContainsString('Identity fidelity is the highest priority', $job->prompt_snapshot);
        $this->assertStringContainsString('Preserve the exact face shape', $job->prompt_snapshot);
        $this->assertStringContainsString('No text, no letters, no words', $job->prompt_snapshot);
        $this->assertStringContainsString('fake HeroKid title', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('open book', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('school badge', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('decorative stars', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('exaggerated cartoon face', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('loose center-parted hair if reference hair is tied or side-swept', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('idealized studio portrait', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('doll-like face', $job->negative_prompt_snapshot);
    }

    public function test_order_status_and_existing_prompt_remain_unchanged_after_ai_job_creation(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png'], ['status' => 'under_review']);
        $order = $project->order;
        $promptBefore = StoryProductionPrompt::forOrder($order);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.ai.character-sheet', $project), $this->generationPayload())
            ->assertRedirect();

        $this->assertSame('under_review', $order->fresh()->status);
        $this->assertSame($promptBefore, StoryProductionPrompt::forOrder($order->fresh(['story'])));
    }

    public function test_identity_lock_uses_only_primary_face_and_approved_illustration_with_quality_and_variants(): void
    {
        Queue::fake();
        Storage::fake('local');
        foreach (range(0, 2) as $index) {
            Storage::disk('local')->put("orders/photos/kid-{$index}.png", $this->validPngBytes());
        }
        $this->enableOpenAi();

        $project = $this->projectWithApprovedPhoto([
            'orders/photos/kid-0.png',
            'orders/photos/kid-1.png',
            'orders/photos/kid-2.png',
        ]);
        $project->characterProfile->update([
            'approved_reference_photos' => [0, 1, 2],
            'body_reference_index' => 1,
            'style_reference_index' => 2,
        ]);
        $project->update([
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'مشهد الهوية',
            'story_text' => 'يبدأ الحدث داخل القصر.',
            'visual_direction' => 'رينا تقف داخل القصر.',
            'child_action_pose' => 'رينا تمسك الفانوس.',
            'template_hero_name' => 'جنا',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes());

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload([
                'model_code' => 'gpt-image-2',
                'character_sheet_id' => $sheet->id,
                'reference_photo_indices' => [0, 1, 2],
                'identity_lock' => true,
                'generation_quality' => 'high',
                'output_count' => 2,
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $jobs = SceneGenerationJob::where('job_type', 'scene_image')->get();
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame([0], $job->input_assets_json['reference_photo_indices']);
            $this->assertSame(['primary_face_reference', 'approved_child_reference_illustration'], collect($job->input_assets_json['reference_assets'])->pluck('type')->all());
            $this->assertTrue($job->input_assets_json['identity_lock']);
            $this->assertSame('high', data_get($job->provider_request_json, 'generation_quality'));
            $this->assertSame('0.1650', $job->estimated_cost);
            $this->assertStringContainsString('Image 1: AUTHORITATIVE real-photo facial identity reference', $job->prompt_snapshot);
            $this->assertStringContainsString('Image 2: approved illustration for secondary art consistency only', $job->prompt_snapshot);
        }
        Queue::assertPushed(SubmitAiGenerationJob::class, 2);

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_identity_lock',
                'data' => [['b64_json' => base64_encode($this->validPngBytes(32, 16))]],
            ]),
        ]);
        (new SubmitAiGenerationJob($jobs->first()->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/images/edits'
            && str_contains($request->body(), 'name="quality"')
            && str_contains($request->body(), 'high')
            && ! str_contains($request->body(), 'name="input_fidelity"')
            && substr_count($request->body(), 'name="image[]"') === 2);
    }

    public function test_identity_correction_uses_primary_face_and_generated_scene_as_two_inputs(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableOpenAi();
        $project = $this->projectWithApprovedPhoto();
        $scene = $project->scenes()->create(['scene_number' => 1, 'title' => 'Scene', 'story_text' => 'Story']);
        $source = $this->asset($project, 'scene_image', ['production_scene_id' => $scene->id]);
        Storage::disk('local')->put($source->file_path, $this->validPngBytes(32, 16));

        $this->actingAs($this->adminUser())
            ->post(route('admin.production-studio.assets.identity-correction', [$project, $source]), [
                'model_code' => 'gpt-image-2',
                'generation_quality' => 'high',
                'prompt_notes' => 'Restore the original eye shape.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();
        $this->assertSame('identity_correction', $job->generation_mode);
        $this->assertSame(['primary_face_reference', 'generated_scene_base'], collect($job->input_assets_json['reference_assets'])->pluck('type')->all());
        $this->assertSame($source->id, $job->input_assets_json['source_asset_id']);
        $this->assertSame('0.1650', $job->estimated_cost);
        $this->assertStringContainsString('Image 1 is the AUTHORITATIVE real child face reference.', $job->prompt_snapshot);
        $this->assertStringContainsString('Image 2 is the generated scene', $job->prompt_snapshot);
        Queue::assertPushed(SubmitAiGenerationJob::class);

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_identity_correction',
                'data' => [['b64_json' => base64_encode($this->validPngBytes(32, 16))]],
            ]),
        ]);
        (new SubmitAiGenerationJob($job->id))->handle(app(AiProviderManager::class), app(GenerationInputAssetResolver::class));

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.com/v1/images/edits'
            && substr_count($request->body(), 'name="image[]"') === 2
            && str_contains($request->body(), 'name="size"')
            && str_contains($request->body(), '1536x1024')
            && str_contains($request->body(), 'Image 1 is the AUTHORITATIVE real child face reference.'));
    }

    public function test_generated_scene_identity_review_stores_safe_structured_result_and_blocks_failed_approval(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableOpenAi();
        $project = $this->projectWithApprovedPhoto();
        $scene = $project->scenes()->create(['scene_number' => 1, 'title' => 'Scene']);
        $asset = $this->asset($project, 'scene_image', ['production_scene_id' => $scene->id]);
        Storage::disk('local')->put($asset->file_path, $this->validPngBytes(32, 16));

        $job = app(IdentityReviewDispatcher::class)->dispatchFor($asset);
        $this->assertNotNull($job);
        Queue::assertPushed(ProcessStructuredAiJob::class);

        $criteria = collect(app(ProductionAutomationIdentityValidator::class)->requiredCriteria())
            ->mapWithKeys(fn (string $key): array => [$key => [
                'status' => 'pass',
                'evidence' => 'No issue.',
                'blocking' => false,
            ]])
            ->all();
        $criteria['face_structure'] = [
            'status' => 'fail',
            'evidence' => 'Different face shape.',
            'blocking' => true,
        ];
        $result = [
            'score' => 92,
            'summary' => 'High score but face structure blocks approval.',
            'criteria' => $criteria,
        ];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiJsonResponse($result)),
        ]);

        (new ProcessStructuredAiJob($job->id))->handle(app(AiProviderManager::class));

        $asset->refresh();
        $this->assertSame('completed', data_get($asset->metadata_json, 'identity_review.status'));
        $this->assertSame(92, data_get($asset->metadata_json, 'identity_review.result.score'));
        $this->assertTrue(data_get($asset->metadata_json, 'identity_review.result.criteria.face_structure.blocking'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('فشل فحص اتساق هوية الطفل');
        app(ApproveGeneratedAssetAction::class)->execute($asset);
    }

    public function test_admin_can_explicitly_override_failed_identity_review_with_reason(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto();
        $scene = $project->scenes()->create(['scene_number' => 1, 'title' => 'Scene']);
        $criteria = collect(app(ProductionAutomationIdentityValidator::class)->requiredCriteria())
            ->mapWithKeys(fn (string $key): array => [$key => [
                'status' => 'pass',
                'evidence' => 'No issue.',
                'blocking' => false,
            ]])
            ->all();
        $criteria['face_structure'] = [
            'status' => 'fail',
            'evidence' => 'Different face shape.',
            'blocking' => true,
        ];
        $asset = $this->asset($project, 'scene_image', [
            'production_scene_id' => $scene->id,
            'status' => 'rejected',
            'rejection_reason' => 'Visual validation found blocking issues.',
            'metadata_json' => [
                'identity_review' => [
                    'status' => 'completed',
                    'result' => [
                        'score' => 92,
                        'summary' => 'High score but face structure blocks approval.',
                        'criteria' => $criteria,
                    ],
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->from(route('admin.production-studio.show', $project))
            ->post(route('admin.production-studio.assets.approve', [$project, $asset]), [
                'identity_override' => '1',
                'confirm_identity_override' => '1',
                'identity_override_reason' => 'Reviewed manually and accepted for this scene.',
                'review_notes' => 'Manual visual approval.',
            ])
            ->assertRedirect(route('admin.production-studio.show', $project));

        $asset->refresh();
        $this->assertSame('approved', $asset->status);
        $this->assertTrue($asset->is_final);
        $this->assertNull($asset->rejection_reason);
        $this->assertTrue(data_get($asset->metadata_json, 'identity_review.manual_override.approved'));
        $this->assertSame('Reviewed manually and accepted for this scene.', data_get($asset->metadata_json, 'identity_review.manual_override.reason'));
        $this->assertStringContainsString('Manual identity validation override', (string) $asset->review_notes);
    }

    public function test_admin_can_queue_all_missing_scene_images_without_duplicating_reviewable_or_active_scenes(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableFal();

        $project = $this->projectWithApprovedPhoto(orderOverrides: ['child_name' => 'رينا']);
        $project->update(['personalized_hero_name' => 'رينا', 'personalization_status' => 'personalized']);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes());

        $scenes = collect(range(1, 5))->map(fn (int $number) => $project->scenes()->create([
            'scene_number' => $number,
            'title' => 'المشهد '.$number,
            'story_text' => 'نص المشهد '.$number,
            'visual_direction' => 'رينا داخل بيئة المشهد '.$number,
            'child_action_pose' => 'رينا تنفذ حركة المشهد '.$number,
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]));

        $this->asset($project, 'scene_image', ['production_scene_id' => $scenes[0]->id, 'status' => 'approved', 'is_final' => true]);
        $this->asset($project, 'scene_image', ['production_scene_id' => $scenes[1]->id, 'status' => 'under_review']);

        $model = AiModel::query()->where('code', config('production_studio.ai.fal.default_model'))->firstOrFail();
        $project->generationJobs()->create([
            'production_scene_id' => $scenes[2]->id,
            'ai_provider_id' => $model->ai_provider_id,
            'ai_model_id' => $model->id,
            'job_type' => 'scene_image',
            'generation_mode' => 'character_scene',
            'prompt_snapshot' => 'Existing active job',
            'status' => 'processing',
        ]);

        $this->actingAs($this->adminUser())
            ->postJson(route('admin.production-studio.ai.scenes.bulk', $project), $this->generationPayload([
                'character_sheet_id' => $sheet->id,
                'confirm_bulk_generation' => true,
                'generation_quality' => 'medium',
            ]))
            ->assertCreated()
            ->assertJsonCount(2, 'jobs')
            ->assertJsonPath('jobs.0.scene_number', 4)
            ->assertJsonPath('jobs.0.scene_title', 'المشهد 4')
            ->assertJsonPath('jobs.1.scene_number', 5);

        $this->assertDatabaseCount('scene_generation_jobs', 3);
        Queue::assertPushed(SubmitAiGenerationJob::class, 2);
    }

    public function test_bulk_scene_generation_is_all_or_nothing_when_a_missing_scene_is_not_ready(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', $this->validPngBytes());
        $this->enableFal();

        $project = $this->projectWithApprovedPhoto(orderOverrides: ['child_name' => 'رينا']);
        $project->update(['personalized_hero_name' => 'رينا', 'personalization_status' => 'personalized']);
        $sheet = $this->asset($project, 'character_sheet', ['status' => 'approved', 'is_primary' => true]);
        Storage::disk('local')->put($sheet->file_path, $this->validPngBytes());

        $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'جاهز',
            'story_text' => 'نص جاهز',
            'visual_direction' => 'رينا داخل القصر',
            'child_action_pose' => 'رينا تمسك الفانوس',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);
        $project->scenes()->create([
            'scene_number' => 2,
            'title' => 'ناقص',
            'story_text' => 'نص فقط',
            'personalized_hero_name' => 'رينا',
            'personalization_status' => 'personalized',
        ]);

        $this->actingAs($this->adminUser())
            ->postJson(route('admin.production-studio.ai.scenes.bulk', $project), $this->generationPayload([
                'character_sheet_id' => $sheet->id,
                'confirm_bulk_generation' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonPath('ok', false)
            ->assertJsonFragment(['message' => 'لا يمكن بدء التوليد الجماعي. أكمل النص والتوجيه البصري ووضع الطفل والتخصيص في: مشهد 2 — ناقص']);

        $this->assertDatabaseCount('scene_generation_jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_generated_scene_cards_show_scene_number_title_and_version(): void
    {
        Storage::fake('local');
        $project = $this->projectWithApprovedPhoto(orderOverrides: ['child_name' => 'رينا']);
        $scene = $project->scenes()->create(['scene_number' => 7, 'title' => 'بوابة القصر']);
        $this->asset($project, 'scene_image', [
            'production_scene_id' => $scene->id,
            'label' => 'Scene Image v3',
            'version_number' => 3,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.production-studio.show', $project))
            ->assertOk()
            ->assertSee('المشهد 7 — بوابة القصر')
            ->assertSee('صورة المشهد — الإصدار v3');
    }

    private function enableFal(string $key = 'test-fal-key'): void
    {
        Config::set('production_studio.enabled', true);

        app(AiProviderRegistrySyncer::class)->sync();

        $provider = AiProvider::where('driver', 'fal')->firstOrFail();
        app(AiProviderCredentialService::class)->save($provider, $key);
        $provider->update([
            'is_active' => true,
            'is_configured' => true,
            'is_available' => true,
            'last_health_check_status' => null,
        ]);
    }

    private function enableOpenAi(string $key = 'test-openai-key'): void
    {
        Config::set('production_studio.enabled', true);

        app(AiProviderRegistrySyncer::class)->sync();

        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        app(AiProviderCredentialService::class)->save($provider, $key);
        $provider->update([
            'is_active' => true,
            'is_configured' => true,
            'is_available' => true,
            'last_health_check_status' => null,
        ]);
    }

    private function generationPayload(array $overrides = []): array
    {
        return array_merge([
            'model_code' => config('production_studio.ai.fal.default_model'),
            'style_preset' => 'premium_storybook',
            'reference_photo_indices' => [0],
            'prompt_notes' => 'Use a soft smile.',
        ], $overrides);
    }

    private function projectWithApprovedPhoto(array $photos = ['orders/photos/kid.png'], array $orderOverrides = []): ProductionProject
    {
        $order = $this->orderWithStory(array_merge([
            'uploaded_photos' => $photos,
        ], $orderOverrides));

        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'intake',
            'created_by_user_id' => User::where('role', 'admin')->first()?->id,
            'sent_to_studio_at' => now(),
        ]);

        $project->characterProfile()->create([
            'appearance_summary' => 'Egyptian child with natural face and warm smile.',
            'hair_details' => 'Dark curly hair with natural volume.',
            'skin_tone' => 'Light warm skin tone.',
            'eye_color_traits' => 'Brown eyes, soft cheeks, natural smile.',
            'typical_expression' => 'Calm friendly smile.',
            'identity_rules' => 'Preserve exact face shape, eyes, nose, smile, hairline, hairstyle, skin tone, apparent age, and body proportions.',
            'negative_instructions' => 'No changed face, no changed hairstyle, no makeup, no anime face, no text, no logos.',
            'approved_reference_photos' => [0],
            'reference_photo_selection' => [0],
            'primary_face_reference_index' => 0,
        ]);

        return $project->load(['order.story', 'characterProfile']);
    }

    private function asset(ProductionProject $project, string $type, array $overrides = []): ProductionProjectAsset
    {
        return $project->assets()->create(array_merge([
            'asset_type' => $type,
            'label' => $type,
            'status' => 'under_review',
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/'.Str::random(8).'.png',
        ], $overrides));
    }

    private function openAiJsonResponse(array $payload): array
    {
        return [
            'id' => 'resp_test',
            'output_text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usage' => [
                'input_tokens' => 120,
                'output_tokens' => 80,
                'total_tokens' => 200,
            ],
        ];
    }

    private function validPngBytes(int $width = 16, int $height = 16, bool $palette = false): string
    {
        $image = $palette ? imagecreate($width, $height) : imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 231, 76, 120);
        imagefilledrectangle($image, 0, 0, $width, $height, $background);

        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);

        return (string) $contents;
    }

    private function openAiScenePayload(): array
    {
        return [
            'template_hero_name' => 'جنا',
            'template_hero_gender' => 'girl',
            'hero_detection_confidence' => 'high',
            'supporting_character_names' => ['بابا'],
            'replacement_strategy' => 'replace_template_hero_with_child_name',
            'personalization_applied' => true,
            'gender_adaptation_applied' => false,
            'personalization_warnings' => [],
            'story_title' => 'Story title',
            'story_summary' => 'Story summary',
            'target_age_range' => '6-9',
            'educational_values' => ['confidence'],
            'scenes' => collect(range(1, 13))->map(fn (int $i): array => [
                'scene_number' => $i,
                'scene_title' => 'Scene title '.$i,
                'written_text' => 'رينا completes scene '.$i,
                'visual_direction' => 'Show رينا in visual direction '.$i,
                'child_action_pose' => 'رينا performs child pose '.$i,
                'environment' => 'Environment '.$i,
                'mood_lighting' => 'Mood lighting '.$i,
                'supporting_characters' => 'Supporting characters '.$i,
                'key_objects' => 'Key objects '.$i,
                'continuity_notes' => 'Continuity notes '.$i,
                'safe_text_area_notes' => 'Safe text area '.$i,
                'educational_value' => 'Educational value '.$i,
            ])->all(),
        ];
    }

    private function adminUser(): User
    {
        return User::create([
            'name' => 'Admin User',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);
    }

    private function adminWithPermissions(array $permissionKeys): User
    {
        $admin = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'role' => 'admin',
        ]);

        $admin->permissions()->sync(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return $admin->refresh();
    }

    private function orderWithStory(array $overrides = []): Order
    {
        $story = Story::create([
            'title' => 'رحلة القمر قبل النوم',
            'slug' => 'moon-trip-'.Str::random(6),
            'short_desc' => 'قصة قصيرة عن الهدوء والشجاعة.',
            'full_desc' => 'مشهد أول. مشهد ثاني. مشهد ثالث.',
            'age_range' => '6-9 سنوات',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 100,
            'active' => true,
        ]);

        return Order::create(array_merge([
            'order_number' => 'HK-2026-'.strtoupper(Str::random(6)),
            'story_id' => $story->id,
            'parent_name' => 'ولي الأمر',
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'lesson' => 'الشجاعة',
            'interests' => 'الفضاء والنجوم',
            'parent_notes' => 'ملاحظات خاصة للطفل.',
            'delivery_details' => ['phone' => '01111822277', 'country' => 'Egypt'],
            'uploaded_photos' => [],
            'status' => 'new',
        ], $overrides));
    }
}
