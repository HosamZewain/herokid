<?php

namespace Tests\Feature;

use App\Actions\ProductionStudio\ApproveGeneratedAssetAction;
use App\Jobs\PollAiGenerationJob;
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
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon Scene',
            'story_text' => 'The child walks under moonlight.',
            'visual_direction' => 'A calm magical night garden.',
            'child_action_pose' => 'Holding a small lantern.',
            'text_safe_area_notes' => 'Keep calm sky area on the left.',
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
        $this->assertStringContainsString('Use reference images for identity only, not for composition.', $job->prompt_snapshot);
        $this->assertStringContainsString('If the reference image conflicts with the scene, keep only the child identity and replace the background/composition with the described scene.', $job->prompt_snapshot);
        $this->assertStringContainsString('Create a new wide landscape scene composition from scratch.', $job->prompt_snapshot);
        $this->assertStringContainsString('Generate pure story illustration only. Do not create a poster, title card, social graphic, thumbnail, book cover, profile card, or educational flashcard.', $job->prompt_snapshot);
        $this->assertStringContainsString('Do not render any visible text, letters, captions, headings, labels, speech bubbles, signs, or symbols in any language.', $job->prompt_snapshot);
        $this->assertStringContainsString('Korean text', $job->negative_prompt_snapshot);
        $this->assertStringContainsString('title-card layout', $job->negative_prompt_snapshot);
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

    public function test_story_scenes_can_be_extracted_with_deterministic_parser_without_openai_call(): void
    {
        $project = $this->projectWithApprovedPhoto();
        $content = collect(range(1, 13))
            ->map(fn (int $i): string => "Scene {$i}: عنوان {$i}\nنص المشهد {$i}.")
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
            ->post(route('admin.production-studio.story-versions.apply-scenes', $project))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(13, $project->scenes()->count());
        $this->assertSame('نص المشهد 1.', $project->scenes()->where('scene_number', 1)->first()->story_text);
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
            ->post(route('admin.production-studio.story-versions.apply-scenes', $project))
            ->assertRedirect()
            ->assertSessionHas('success');

        $scene = $project->scenes()->where('scene_number', 1)->firstOrFail();
        $this->assertSame('Scene title 1', $scene->title);
        $this->assertSame('Written text 1', $scene->story_text);
        $this->assertSame('Visual direction 1', $scene->visual_direction);
        $this->assertSame('Child pose 1', $scene->child_action_pose);
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
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableOpenAi('sk-openai-image-test');

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_test_123',
                'created' => 1234567890,
                'data' => [
                    ['b64_json' => base64_encode('openai-generated-image-bytes')],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150],
            ]),
        ]);

        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Moon Scene',
            'story_text' => 'The child watches a quiet moonlit castle from the window.',
            'visual_direction' => 'A wide A3 landscape castle scene with fog and a blank quiet area for Arabic text.',
            'child_action_pose' => 'The child stands naturally near the window looking at the distant lantern.',
            'text_safe_area_notes' => 'Reserve a quiet lower-left blank area.',
        ]);
        $sheet = $this->asset($project, 'character_sheet', [
            'status' => 'approved',
            'is_primary' => true,
            'file_path' => 'production-studio/projects/'.$project->id.'/generated/reference.png',
        ]);
        Storage::disk('local')->put($sheet->file_path, 'approved-reference-bytes');

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
        $this->assertStringNotContainsString(base64_encode('openai-generated-image-bytes'), json_encode($submitted->provider_response_json));
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
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/images/edits');
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

    private function openAiScenePayload(): array
    {
        return [
            'story_title' => 'Story title',
            'story_summary' => 'Story summary',
            'target_age_range' => '6-9',
            'educational_values' => ['confidence'],
            'scenes' => collect(range(1, 13))->map(fn (int $i): array => [
                'scene_number' => $i,
                'scene_title' => 'Scene title '.$i,
                'written_text' => 'Written text '.$i,
                'visual_direction' => 'Visual direction '.$i,
                'child_action_pose' => 'Child pose '.$i,
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
