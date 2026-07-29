<?php

namespace Tests\Feature;

use App\Jobs\GenerateChildIdentityAttemptJob;
use App\Models\AiProvider;
use App\Models\ChildIdentityGenerationAttempt;
use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\Setting;
use App\Models\Story;
use App\Models\StoryCategory;
use App\Models\User;
use App\Services\Ai\AiImagePricingService;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderRegistrySyncer;
use App\Services\Ai\GptImageClient;
use App\Services\ChildIdentity\ChildIdentityAggregateService;
use App\Services\ChildIdentity\ChildIdentityAttemptService;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\ChildIdentity\Sharing\ChildIdentityShareDraftService;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChildIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_valid_step_creates_permanent_admin_visible_request_and_private_resume_access(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->ageRanges();
        $this->configuredOpenAi();
        [$uploadToken, $photoIds] = $this->uploadTemporaryPhotos();

        $response = $this->post(route('child-identity.store'), $this->startPayload($uploadToken, $photoIds));
        $response->assertSessionDoesntHaveErrors();
        $identity = ChildIdentityRequest::firstOrFail();

        $response->assertRedirect(route('child-identity.show', $identity->uuid))
            ->assertSessionHas('resume_url');
        $this->assertSame('queued', $identity->status);
        $this->assertSame('٣ - ٦ سنوات', $identity->age_range);
        $this->assertNull($identity->child_age);
        $this->assertNull($identity->gender);
        $this->assertNull($identity->parent_email);
        $this->assertCount(2, $identity->photos);
        $this->assertSame('pending', $identity->attempts()->firstOrFail()->status);
        $this->assertNotNull($identity->consent_accepted_at);
        $this->assertNull($identity->marketing_consent_at);
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_request_id' => $identity->id,
            'event_type' => 'request.created',
        ]);

        $resumeUrl = session('resume_url');
        $show = $this->get(route('child-identity.show', $identity->uuid))
            ->assertOk()
            ->assertSee('جاري إنشاء هوية طفلك')
            ->assertDontSee('اسم ولي الأمر');
        $this->assertStringContainsString('no-store', (string) $show->headers->get('Cache-Control'));
        $this->app['session']->flush();
        $this->get(route('child-identity.show', $identity->uuid))->assertForbidden();

        $this->assertStringNotContainsString((string) parse_url($resumeUrl, PHP_URL_PATH), json_encode($identity->events()->pluck('metadata')->all()));
    }

    public function test_child_identity_intake_rejects_more_than_three_photos(): void
    {
        Storage::fake('local');
        config(['photo_uploads.max_files' => 4]);
        $this->ageRanges();
        $this->get(route('child-identity.index'))->assertOk();
        $uploadToken = (string) session('photo_upload.token');
        $photoIds = collect(['one.png', 'two.png', 'three.png', 'four.png'])
            ->map(fn (string $name): string => (string) $this->postJson(route('photo-uploads.store'), [
                'upload_session_token' => $uploadToken,
                'upload_batch_token' => 'child-identity-maximum-test',
                'photo' => $this->tinyPng($name),
            ])->assertCreated()->json('id'))
            ->all();

        $this->from(route('child-identity.index'))
            ->post(route('child-identity.store'), $this->startPayload($uploadToken, $photoIds))
            ->assertRedirect(route('child-identity.index'))
            ->assertSessionHasErrors('photo_upload_ids');

        $this->assertDatabaseCount('child_identity_requests', 0);
        $this->assertSame(
            'يمكنك رفع ٣ صور كحد أقصى.',
            session('errors')->first('photo_upload_ids'),
        );
    }

    public function test_existing_identity_photo_endpoint_stops_at_three_photos(): void
    {
        Storage::fake('local');
        $identity = $this->createPublicIdentity();

        foreach (['one.png', 'two.png', 'three.png'] as $name) {
            $this->post(route('child-identity.photos.store', $identity->uuid), [
                'photo' => $this->tinyPng($name),
            ])->assertRedirect();
        }

        $this->post(route('child-identity.photos.store', $identity->uuid), [
            'photo' => $this->tinyPng('four.png'),
        ])->assertSessionHasErrors('photo');

        $this->assertSame(3, $identity->fresh()->validPhotos()->count());
        $this->assertSame('يمكن رفع ٣ صور كحد أقصى.', session('errors')->first('photo'));
    }

    public function test_photos_are_uploaded_independently_to_persistent_private_storage_and_removal_keeps_file(): void
    {
        Storage::fake('local');
        $identity = $this->createPublicIdentity();

        $this->post(route('child-identity.photos.store', $identity->uuid), ['photo' => $this->tinyPng('front.png')])
            ->assertRedirect();
        $this->post(route('child-identity.photos.store', $identity->uuid), ['photo' => $this->tinyPng('profile.png')])
            ->assertRedirect();

        $identity->refresh();
        $this->assertSame('photos_uploaded', $identity->status);
        $this->assertCount(2, $identity->photos);
        $first = $identity->photos->first();
        Storage::disk('local')->assertExists($first->path);
        $this->assertSame(64, strlen($first->checksum));
        $this->assertStringStartsWith('child-identities/'.$identity->uuid.'/originals/', $first->path);

        $this->delete(route('child-identity.photos.destroy', [$identity->uuid, $first]))
            ->assertRedirect();
        Storage::disk('local')->assertExists($first->path);
        $this->assertSame('removed', $first->fresh()->upload_status);
        $this->assertDatabaseHas('child_identity_events', ['event_type' => 'photo.removed']);
    }

    public function test_heic_original_is_retained_while_generation_uses_the_browser_prepared_derivative(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->ageRanges();
        $this->configuredOpenAi();
        $this->get(route('child-identity.index'))->assertOk();
        $uploadToken = (string) session('photo_upload.token');
        $heicHeader = pack('N', 24).'ftypheic'.pack('N', 0).'heicmif1';

        $heicId = $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $uploadToken,
            'upload_batch_token' => 'heic-identity-batch',
            'photo' => UploadedFile::fake()->createWithContent('iphone-photo.heic', $heicHeader),
            'prepared_photo' => $this->tinyPng('iphone-photo-ai-input.png'),
        ])->assertCreated()->json('id');
        $pngId = $this->postJson(route('photo-uploads.store'), [
            'upload_session_token' => $uploadToken,
            'upload_batch_token' => 'heic-identity-batch',
            'photo' => $this->tinyPng('second-photo.png'),
        ])->assertCreated()->json('id');

        $this->post(
            route('child-identity.store'),
            $this->startPayload($uploadToken, [$heicId, $pngId]),
        )->assertSessionDoesntHaveErrors();

        $identity = ChildIdentityRequest::with(['photos', 'attempts.photos'])->firstOrFail();
        $heicPhoto = $identity->photos->firstWhere('mime_type', 'image/heic');
        $attempt = $identity->attempts->firstOrFail();
        $attemptHeicPhoto = $attempt->photos->firstWhere('id', $heicPhoto->id);

        $this->assertNotNull($heicPhoto);
        $this->assertStringEndsWith('.heic', $heicPhoto->path);
        $this->assertSame('image/png', $heicPhoto->ai_input_mime_type);
        $this->assertStringContainsString('/ai-inputs/', $heicPhoto->ai_input_path);
        Storage::disk('local')->assertExists($heicPhoto->path);
        Storage::disk('local')->assertExists($heicPhoto->ai_input_path);
        $this->assertSame($heicPhoto->ai_input_path, $attemptHeicPhoto->pivot->path);
        $this->assertSame('image/png', $attemptHeicPhoto->pivot->mime_type);

        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_heic_derivative_success',
                'data' => [['b64_json' => base64_encode($this->shareableJpegContents())]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ], 200, ['x-request-id' => 'req_heic_derivative']),
        ]);

        $this->runJob($attempt);

        $this->assertSame('succeeded', $attempt->fresh()->status, (string) $attempt->fresh()->technical_error);
    }

    public function test_failed_legacy_heic_request_can_prepare_a_derivative_without_replacing_the_original(): void
    {
        Storage::fake('local');
        $identity = $this->createPublicIdentity();
        $originalPath = 'child-identities/'.$identity->uuid.'/originals/legacy-iphone.heic';
        $heicHeader = pack('N', 24).'ftypheic'.pack('N', 0).'heicmif1';
        Storage::disk('local')->put($originalPath, $heicHeader);
        $photo = $identity->photos()->create([
            'disk' => 'local',
            'path' => $originalPath,
            'original_filename' => 'legacy-iphone.heic',
            'mime_type' => 'image/heic',
            'file_size' => strlen($heicHeader),
            'checksum' => hash('sha256', $heicHeader),
            'sort_order' => 1,
            'upload_status' => 'uploaded',
            'validation_status' => 'valid',
        ]);
        $identity->forceFill(['status' => 'generation_failed'])->save();

        $this->get(route('child-identity.show', $identity->uuid))
            ->assertOk()
            ->assertSee('تعذر تجهيز إحدى الصور في المحاولة السابقة')
            ->assertSee('تجهيز الصور وإعادة المحاولة')
            ->assertDontSee('iPhone')
            ->assertSee('data-identity-heic-recovery', false)
            ->assertSee(route('child-identity.photos.ai-input', [$identity->uuid, $photo]), false);

        $this->postJson(route('child-identity.photos.ai-input', [$identity->uuid, $photo]), [
            'prepared_photo' => $this->tinyPng('legacy-ai-input.png'),
        ])->assertOk();

        $photo->refresh();
        Storage::disk('local')->assertExists($originalPath);
        Storage::disk('local')->assertExists($photo->ai_input_path);
        $this->assertSame($originalPath, $photo->path);
        $this->assertSame('image/png', $photo->ai_input_mime_type);
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_request_id' => $identity->id,
            'event_type' => 'photo.ai_input_prepared',
        ]);
    }

    public function test_rejected_upload_is_quarantined_and_retained_for_admin_history(): void
    {
        Storage::fake('local');
        $identity = $this->createPublicIdentity();

        $this->post(route('child-identity.photos.store', $identity->uuid), [
            'photo' => UploadedFile::fake()->createWithContent('not-an-image.txt', 'private-invalid-upload'),
        ])->assertSessionHasErrors('photo');

        $photo = $identity->photos()->firstOrFail();
        $this->assertSame('invalid', $photo->validation_status);
        $this->assertStringContainsString('/quarantine/', $photo->path);
        Storage::disk('local')->assertExists($photo->path);
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_request_id' => $identity->id,
            'event_type' => 'photo.rejected',
        ]);
    }

    public function test_every_generation_command_is_immutable_idempotent_and_validation_failures_remain_recorded(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $key = (string) Str::uuid();

        $this->post(route('child-identity.generate', $identity->uuid), ['idempotency_key' => $key])
            ->assertSessionHasErrors('generation');

        $failed = ChildIdentityGenerationAttempt::firstOrFail();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('not_billable', $failed->billing_status);
        $this->assertSame('0.000000', $failed->cost_usd);

        $this->uploadTwoPhotos($identity);
        $secondKey = (string) Str::uuid();
        $service = app(ChildIdentityAttemptService::class);
        $attempt = $service->create($identity->fresh(), $secondKey);
        $same = $service->create($identity->fresh(), $secondKey);

        $this->assertSame($attempt->id, $same->id);
        $this->assertSame(2, $identity->attempts()->count());
        $this->assertSame(2, $attempt->photos()->count());
        $this->post(route('child-identity.generate', $identity->uuid), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('generation');
        $this->assertSame(3, $identity->attempts()->count());
        $this->assertSame('generation_in_progress', $identity->attempts()->latest('attempt_number')->firstOrFail()->error_code);
        $this->assertSame('queued', $identity->fresh()->status);
        $this->getJson(route('child-identity.poll', $identity->uuid))
            ->assertOk()
            ->assertJson(['attempt_number' => $attempt->attempt_number, 'attempt_status' => 'pending', 'refresh' => true]);
        Queue::assertPushed(GenerateChildIdentityAttemptJob::class, 1);
    }

    public function test_fake_gpt_image_success_stores_output_usage_usd_cost_and_aggregates(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_test_success',
                'created' => now()->timestamp,
                'data' => [['b64_json' => base64_encode($this->shareableJpegContents())]],
                'usage' => ['input_tokens' => 123, 'output_tokens' => 456],
            ], 200, ['x-request-id' => 'req_identity_success']),
        ]);

        $this->runJob($attempt);

        $attempt->refresh();
        $identity->refresh();
        $this->assertSame('succeeded', $attempt->status, $attempt->error_code.' '.$attempt->technical_error);
        $this->assertSame('req_identity_success', $attempt->api_request_id);
        $this->assertSame('calculated', $attempt->cost_calculation_method);
        $this->assertSame('estimated', $attempt->billing_status);
        $this->assertSame('0.041000', $attempt->cost_usd);
        $this->assertNull($attempt->usd_to_egp_rate);
        $this->assertNull($attempt->cost_egp);
        Storage::disk('local')->assertExists($attempt->output_storage_path);
        Storage::disk('local')->assertExists($attempt->share_feed_card_path);
        Storage::disk('local')->assertExists($attempt->share_story_card_path);
        Storage::disk('local')->assertExists($attempt->share_og_card_path);
        $this->assertNotNull($attempt->share_draft_token);
        $this->assertNotNull($attempt->share_cards_generated_at);
        $this->assertDatabaseCount('child_identity_shares', 0);
        $this->assertSame(1, $identity->successful_attempts);
        $this->assertSame('0.041000', $identity->total_cost_usd);
        $this->assertSame($attempt->id, $identity->approved_attempt_id);
        $this->assertSame('approved', $identity->status);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/images/edits'
            && $request->hasHeader('Authorization', 'Bearer test-openai-key'));
    }

    public function test_generated_identity_reveals_category_and_story_wizard_only_after_customer_action(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
        $output = $this->shareableJpegContents();
        $attempt->forceFill([
            'status' => 'succeeded',
            'output_disk' => 'local',
            'output_storage_path' => 'child-identities/'.$identity->uuid.'/attempts/1/output.jpg',
            'output_checksum' => hash('sha256', $output),
            'completed_at' => now(),
        ])->save();
        Storage::disk('local')->put($attempt->output_storage_path, $output);
        app(ChildIdentityShareDraftService::class)->prepare($attempt);
        $identity->forceFill(['approved_attempt_id' => $attempt->id, 'status' => 'approved'])->save();
        $category = StoryCategory::create(['name' => 'مغامرات', 'slug' => 'wizard-adventures']);
        $story = $this->story($category);

        $this->get(route('child-identity.show', $identity->uuid))
            ->assertOk()
            ->assertSee('اختر قصة بهذه الهوية')
            ->assertDontSee('ما نوع القصة التي تحبها؟');

        $this->get(route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'category']))
            ->assertOk()
            ->assertSee('ما نوع القصة التي تحبها؟')
            ->assertSee($category->name);

        $this->post(route('child-identity.category', $identity->uuid), [
            'story_category_id' => $category->id,
        ])->assertRedirect(route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'stories']));

        $this->get(route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'stories']))
            ->assertOk()
            ->assertSee($story->title);

        $this->post(route('child-identity.story', $identity->uuid), [
            'story_id' => $story->id,
        ])->assertRedirect(route('child-identity.show', ['identity' => $identity->uuid, 'step' => 'confirm']));
    }

    public function test_admin_can_edit_the_exact_prompt_used_by_the_next_attempt(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $customPrompt = str_repeat('Create a consistent child character identity sheet with a neutral background. ', 3);

        $this->actingAs($admin)
            ->patch(route('admin.child-identities.prompt.update', $identity->id), [
                'prompt_override' => $customPrompt,
            ])
            ->assertRedirect();

        $attempt = app(ChildIdentityAttemptService::class)->create(
            $identity->fresh(),
            (string) Str::uuid(),
            'admin',
            $admin,
        );

        $this->assertSame(trim($customPrompt), $attempt->prompt_snapshot);
        $this->assertSame('request_override', data_get($attempt->request_metadata, 'prompt_source'));
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_request_id' => $identity->id,
            'event_type' => 'prompt.updated_by_admin',
        ]);
        $this->actingAs($admin)
            ->get(route('admin.child-identities.show', $identity->id))
            ->assertOk()
            ->assertSee('برومبت OpenAI لهذا الطلب')
            ->assertSee(trim($customPrompt));
    }

    public function test_provider_failure_remains_recorded_with_unknown_billing_instead_of_silent_zero(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'error' => ['code' => 'provider_error', 'message' => 'temporary failure'],
            ], 500, ['x-request-id' => 'req_identity_failure']),
        ]);

        try {
            $this->runJob($attempt);
        } catch (\Throwable) {
            // The queue may retry, but the immutable failed attempt is already finalized.
        }

        $attempt->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('unknown', $attempt->billing_status);
        $this->assertSame('unknown', $attempt->cost_calculation_method);
        $this->assertNull($attempt->cost_usd);
        $this->assertSame('req_identity_failure', $attempt->api_request_id);
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_generation_attempt_id' => $attempt->id,
            'event_type' => 'generation.failed',
        ]);
    }

    public function test_provider_reported_usd_cost_is_authoritative(): void
    {
        $provider = $this->configuredOpenAi();
        $model = $provider->models()->where('code', 'gpt-image-2')->firstOrFail();
        $cost = app(AiImagePricingService::class)->calculate(
            $model,
            '1536x1024',
            'medium',
            ['cost_usd' => '0.052345'],
        );

        $this->assertSame('0.052345', $cost['cost_usd']);
        $this->assertSame('provider_reported', $cost['method']);
        $this->assertSame('billable', $cost['billing_status']);
        $this->assertSame('provider_usage', $cost['rule']['source']);
    }

    public function test_invalid_provider_output_is_not_counted_as_a_successful_identity(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
        Http::fake([
            'https://api.openai.com/v1/images/edits' => Http::response([
                'id' => 'img_invalid_output',
                'data' => [['b64_json' => base64_encode('not-an-image')]],
            ], 200, ['x-request-id' => 'req_invalid_output']),
        ]);

        try {
            $this->runJob($attempt);
        } catch (\Throwable) {
            // The immutable attempt is finalized before the queue records the retryable provider failure.
        }

        $attempt->refresh();
        $identity->refresh();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('invalid_output_image', $attempt->error_code);
        $this->assertSame('unknown', $attempt->billing_status);
        $this->assertNull($attempt->cost_usd);
        $this->assertSame(0, $identity->successful_attempts);
        Storage::disk('local')->assertMissing('child-identities/'.$identity->uuid.'/attempts/'.$attempt->attempt_number.'/output.png');
    }

    public function test_signed_media_requires_both_valid_signature_and_private_request_access(): void
    {
        Storage::fake('local');
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $photo = $identity->photos()->firstOrFail();
        $signedUrl = URL::temporarySignedRoute(
            'child-identity.media.photo',
            now()->addMinutes(5),
            ['identity' => $identity->uuid, 'photo' => $photo->id],
        );

        $this->get($signedUrl)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('child-identity.media.photo', [$identity->uuid, $photo]))
            ->assertForbidden();

        $this->app['session']->flush();
        $this->get($signedUrl)->assertForbidden();
    }

    public function test_customer_limit_counts_saved_outputs_but_admin_can_generate_beyond_it(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);

        foreach ([1, 2] as $number) {
            $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
            $path = 'child-identities/'.$identity->uuid.'/attempts/'.$attempt->attempt_number.'/output.png';
            Storage::disk('local')->put($path, $this->tinyPngContents());
            $attempt->forceFill([
                'status' => 'succeeded',
                'output_disk' => 'local',
                'output_storage_path' => $path,
                'output_checksum' => hash('sha256', $this->tinyPngContents()),
                'cost_usd' => '0.041000',
                'cost_calculation_method' => 'calculated',
                'billing_status' => 'estimated',
                'completed_at' => now(),
            ])->save();
            app(ChildIdentityAggregateService::class)->recalculate($identity);
        }

        $this->post(route('child-identity.generate', $identity->uuid), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasErrors('generation');
        $limited = $identity->attempts()->latest('attempt_number')->firstOrFail();
        $this->assertSame('customer_limit_reached', $limited->error_code);
        $this->assertSame('not_billable', $limited->billing_status);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('admin.child-identities.generate', $identity->id), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertRedirect();
        $this->assertSame('pending', $identity->attempts()->latest('attempt_number')->firstOrFail()->status);
    }

    public function test_approved_identity_uses_normal_cart_checkout_and_links_only_matching_story_order(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $identity->forceFill(['gender' => null])->save();
        $this->uploadTwoPhotos($identity);
        $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
        $attempt->forceFill([
            'status' => 'succeeded',
            'output_disk' => 'local',
            'output_storage_path' => 'child-identities/'.$identity->uuid.'/attempts/1/output.png',
            'output_checksum' => hash('sha256', $this->tinyPngContents()),
            'cost_usd' => '0.041000',
            'cost_calculation_method' => 'calculated',
            'billing_status' => 'estimated',
            'completed_at' => now(),
        ])->save();
        Storage::disk('local')->put($attempt->output_storage_path, $this->tinyPngContents());
        app(ChildIdentityAggregateService::class)->recalculate($identity);
        $referralShare = ChildIdentityShare::create([
            'child_identity_request_id' => $identity->id,
            'generation_attempt_id' => $attempt->id,
            'public_token' => Str::random(64),
            'status' => 'ready',
            'share_enabled' => true,
            'display_child_first_name' => false,
            'consent_accepted_at' => now(),
            'consent_version' => 'test-v1',
            'created_by_type' => 'customer',
            'card_disk' => 'local',
            'template_version' => 'test-v1',
            'card_fingerprint' => hash('sha256', 'checkout-referral'),
            'generated_fingerprint' => hash('sha256', 'checkout-referral'),
            'generation_version' => 1,
            'caption_snapshot' => 'HeroKid referral',
            'hashtags_snapshot' => '#HeroKid',
            'cards_generated_at' => now(),
        ]);
        $identity->forceFill(['referred_by_child_identity_share_id' => $referralShare->id])->save();

        $this->post(route('child-identity.approve', [$identity->uuid, $attempt]))->assertRedirect();
        $category = StoryCategory::create(['name' => 'مغامرات', 'slug' => 'adventures']);
        $story = $this->story($category);
        $this->post(route('child-identity.category', $identity->uuid), ['story_category_id' => $category->id])->assertRedirect();
        $this->post(route('child-identity.story', $identity->uuid), ['story_id' => $story->id])->assertRedirect();
        $this->post(route('child-identity.cart', $identity->uuid))->assertRedirect(route('cart.index'));
        $firstCartKey = array_key_first(session('cart.items'));
        $this->delete(route('cart.destroy', $firstCartKey))->assertRedirect(route('cart.index'));
        $this->assertSame('story_selected', $identity->fresh()->status);
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_request_id' => $identity->id,
            'event_type' => 'cart.removed',
        ]);
        $this->post(route('child-identity.cart', $identity->uuid))->assertRedirect(route('cart.index'));
        $this->post(route('child-identity.cart', $identity->uuid))->assertRedirect(route('cart.index'));
        $this->assertCount(1, session('cart.items'));
        $this->post(route('child-identity.photos.store', $identity->uuid), [
            'photo' => $this->tinyPng('after-cart.png'),
        ])->assertRedirect();
        $this->assertSame('in_cart', $identity->fresh()->status);
        $secondStory = Story::create([
            'title' => 'قصة ثانية في السلة',
            'slug' => 'second-cart-story',
            'language' => 'ar',
            'lesson_value' => 'التعاون',
            'age_range' => '٣ - ٦ سنوات',
            'price' => 399,
            'active' => true,
        ]);
        $this->post(route('cart.store', $secondStory), [
            'child_name' => 'طفل آخر',
            'child_age' => 6,
            'child_gender' => 'boy',
            'privacy_consent' => '1',
            'photos' => [$this->tinyPng('second-story.png'), $this->tinyPng('second-story-side.png')],
        ])->assertRedirect();
        $this->assertCount(2, session('cart.items'));

        $cartItem = collect(session('cart.items'))
            ->firstWhere('child_identity_request_id', $identity->id);
        $this->assertSame($identity->id, $cartItem['child_identity_request_id']);
        $this->assertSame($attempt->id, $cartItem['child_identity_approved_attempt_id']);
        $this->assertNull($cartItem['child_gender']);
        $this->assertCount(2, $cartItem['uploaded_photos']);

        $studioCounts = collect([
            'production_projects',
            'scene_generation_jobs',
            'production_automation_runs',
            'production_project_activity_logs',
        ])->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]);
        [$country, $governorate] = $this->deliveryLocation();
        $this->post(route('checkout.store'), [
            'parent_name' => 'مريم أحمد',
            'phone' => '201001112233',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'القاهرة',
            'street' => 'شارع الاختبار',
            'address_details' => 'عمارة ١',
        ])->assertRedirect(route('checkout.success'));

        $order = Order::where('child_identity_request_id', $identity->id)->firstOrFail();
        $identity->refresh();
        $this->assertSame(2, Order::count());
        $this->assertNotNull(Order::whereNull('child_identity_request_id')->first());
        $this->assertSame($identity->id, $order->child_identity_request_id);
        $this->assertSame($attempt->id, $order->child_identity_approved_attempt_id);
        $this->assertSame($referralShare->id, $order->referred_by_child_identity_share_id);
        $this->assertNull($order->child_gender);
        $this->assertSame('converted', $identity->status);
        $this->assertSame($order->id, $identity->converted_order_id);
        $this->assertCount(3, $order->uploaded_photos);
        $snapshot = $order->items()->where('item_type', 'story')->firstOrFail()->personalization_snapshot;
        $this->assertSame($identity->uuid, data_get($snapshot, 'child_identity.request_uuid'));
        $this->assertSame('0.041000', data_get($snapshot, 'child_identity.generation_cost_usd'));
        $this->assertSame(3, data_get($snapshot, 'uploaded_photos_count'));
        $this->assertSame($studioCounts['production_projects'], ProductionProject::count());
        foreach ($studioCounts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), "Unexpected Production Studio record in {$table}.");
        }
        $this->assertNull($order->productionProject);
        $this->assertSame(1, $referralShare->fresh()->total_orders);
        $this->assertDatabaseHas('child_identity_share_events', [
            'child_identity_share_id' => $referralShare->id,
            'event_type' => 'share.order_created',
            'referred_order_id' => $order->id,
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $replacement = app(ChildIdentityAttemptService::class)->create(
            $identity->fresh(),
            (string) Str::uuid(),
            'admin',
            $admin,
        );
        $replacement->forceFill([
            'status' => 'succeeded',
            'output_disk' => 'local',
            'output_storage_path' => 'child-identities/'.$identity->uuid.'/attempts/2/output.png',
            'output_checksum' => hash('sha256', $this->tinyPngContents()),
            'cost_usd' => '0.041000',
            'cost_calculation_method' => 'calculated',
            'billing_status' => 'estimated',
            'completed_at' => now(),
        ])->save();
        Storage::disk('local')->put($replacement->output_storage_path, $this->tinyPngContents());
        app(ChildIdentityAggregateService::class)->recalculate($identity);
        $this->actingAs($admin)
            ->post(route('admin.child-identities.attempts.approve', [$identity->id, $replacement]))
            ->assertRedirect();
        $this->assertSame($replacement->id, $order->fresh()->child_identity_approved_attempt_id);
        $this->assertSame('converted', $identity->fresh()->status);
        $this->assertSame(
            $attempt->id,
            data_get($order->items()->where('item_type', 'story')->firstOrFail()->personalization_snapshot, 'child_identity.approved_attempt_id'),
        );

        $prompt = StoryProductionPrompt::forOrder($order);
        $this->assertStringContainsString('Approved Child Identity Reference', $prompt);
        $this->assertStringContainsString(route('orders.approved-child-identity', $order), urldecode($prompt));

        $identity->delete();
        $this->assertTrue($order->fresh()->childIdentityRequest->trashed());
        $this->assertCount(3, $order->fresh()->childIdentityRequest->photos);
        Storage::disk('local')->assertExists($attempt->output_storage_path);
    }

    public function test_soft_delete_retains_media_and_order_access_while_force_delete_is_separately_authorized(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configuredOpenAi();
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $attempt = app(ChildIdentityAttemptService::class)->create($identity->fresh(), (string) Str::uuid());
        $path = $identity->photos()->firstOrFail()->path;
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->delete(route('admin.child-identities.destroy', $identity->id), [
            'reason' => 'طلب تجريبي أنشئ بالخطأ',
            'confirmation' => $identity->uuid,
        ])->assertRedirect(route('admin.child-identities.index'));
        $this->assertSoftDeleted('child_identity_requests', ['id' => $identity->id]);
        Storage::disk('local')->assertExists($path);
        Http::fake();
        $this->runJob($attempt);
        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertSame('not_billable', $attempt->fresh()->billing_status);
        Http::assertNothingSent();

        $this->actingAs($admin)->post(route('admin.child-identities.restore', $identity->id))
            ->assertRedirect(route('admin.child-identities.show', $identity->id));
        $this->assertNotSoftDeleted('child_identity_requests', ['id' => $identity->id]);
        $this->assertSame('generation_failed', $identity->fresh()->status);

        $forcePermission = $admin->permissions()->where('key', 'child_identities.force_delete')->firstOrFail();
        $admin->permissions()->detach($forcePermission);
        $admin->unsetRelation('permissions');
        $identity->delete();
        $this->actingAs($admin)->delete(route('admin.child-identities.force-delete', $identity->id), [
            'reason' => 'حذف نهائي مصرح بناءً على طلب خصوصية',
            'confirmation' => $identity->uuid,
        ])->assertForbidden();
        Storage::disk('local')->assertExists($path);
    }

    public function test_admin_can_see_incomplete_failed_and_non_converted_requests(): void
    {
        $this->ageRanges();
        $identity = ChildIdentityRequest::create(array_merge($this->identityAttributes(), [
            'status' => 'generation_failed',
        ]));
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('admin.child-identities.index', ['status' => 'generation_failed']))
            ->assertOk()
            ->assertSee($identity->child_name)
            ->assertSee($identity->uuid);
    }

    public function test_soft_delete_requires_permission_reason_and_exact_uuid_confirmation(): void
    {
        $identity = $this->createPublicIdentity();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->delete(route('admin.child-identities.destroy', $identity->id), [
            'confirmation' => $identity->uuid,
        ])->assertSessionHasErrors('reason');
        $this->assertNotSoftDeleted('child_identity_requests', ['id' => $identity->id]);

        $this->actingAs($admin)->delete(route('admin.child-identities.destroy', $identity->id), [
            'reason' => 'طلب تجريبي غير مطلوب',
            'confirmation' => 'wrong-uuid',
        ])->assertSessionHasErrors('confirmation');
        $this->assertNotSoftDeleted('child_identity_requests', ['id' => $identity->id]);

        $permission = $admin->permissions()->where('key', 'child_identities.delete')->firstOrFail();
        $admin->permissions()->detach($permission);
        $admin->unsetRelation('permissions');
        $this->actingAs($admin)->delete(route('admin.child-identities.destroy', $identity->id), [
            'reason' => 'طلب تجريبي غير مطلوب',
            'confirmation' => $identity->uuid,
        ])->assertForbidden();
        $this->assertNotSoftDeleted('child_identity_requests', ['id' => $identity->id]);
    }

    public function test_elevated_permanent_delete_removes_media_and_keeps_audit_manifest(): void
    {
        Storage::fake('local');
        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $paths = $identity->photos()->pluck('path')->all();
        $identity->delete();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->delete(route('admin.child-identities.force-delete', $identity->id), [
            'reason' => 'طلب خصوصية موثق للحذف النهائي للصور',
            'confirmation' => $identity->uuid,
        ])->assertRedirect(route('admin.child-identities.trash'));

        $this->assertDatabaseMissing('child_identity_requests', ['id' => $identity->id]);
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $admin->id,
            'action' => 'child_identity.force_deleted',
        ]);
    }

    public function test_child_identity_generation_settings_are_permissioned_configurable_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $payload = [
            'enabled' => '1',
            'image_size' => '1536x1024',
            'image_quality' => 'high',
            'prompt_template' => str_repeat('Character sheet prompt. ', 4),
            'prompt_version' => 'character-sheet-v2',
            'processing_copy' => [
                'heading' => 'نجهز الآن هوية :child',
                'description' => 'تم استلام طلبك وسيتم عرض النتيجة تلقائيًا.',
                'received_title' => 'وصلتنا الصور',
                'received_description' => 'تم حفظ :count صور',
                'queued_title' => 'الطلب جاهز',
                'queued_waiting_description' => 'بانتظار بدء الإنشاء',
                'queued_completed_description' => 'تم تجهيز الطلب',
                'generating_title' => 'نرسم هوية طفلك',
                'generating_active_description' => 'يجري إنشاء الهوية الآن',
                'generating_waiting_description' => 'ستبدأ هذه المرحلة قريبًا',
                'result_title' => 'النتيجة النهائية',
                'result_description' => 'ستظهر النتيجة هنا عند اكتمالها',
            ],
        ];

        $this->actingAs($admin)
            ->get(route('admin.child-identities.settings.edit'))
            ->assertOk()
            ->assertSee('نصوص شاشة انتظار إنشاء الهوية')
            ->assertSee('processing_copy[heading]', false)
            ->assertSee('processing_copy[result_description]', false)
            ->assertSee('share_card_footer', false);

        $this->actingAs($admin)
            ->put(route('admin.child-identities.settings.update'), $payload)
            ->assertRedirect();
        $this->assertDatabaseHas('settings', ['key' => 'child_identity_image_quality', 'value' => 'high']);
        $this->assertDatabaseHas('settings', ['key' => 'child_identity_prompt_version', 'value' => 'character-sheet-v2']);
        $this->assertDatabaseHas('settings', ['key' => 'child_identity_processing_heading', 'value' => 'نجهز الآن هوية :child']);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $admin->id,
            'action' => 'child_identity.settings_updated',
        ]);

        $identity = $this->createPublicIdentity();
        $this->uploadTwoPhotos($identity);
        $this->get(route('child-identity.show', $identity->uuid))
            ->assertOk()
            ->assertSee('نجهز الآن هوية ليلى')
            ->assertSee('وصلتنا الصور')
            ->assertSee('تم حفظ ٢ صور')
            ->assertSee('النتيجة النهائية');

        $permission = $admin->permissions()->where('key', 'child_identities.settings')->firstOrFail();
        $admin->permissions()->detach($permission);
        $admin->unsetRelation('permissions');
        $this->actingAs($admin)
            ->put(route('admin.child-identities.settings.update'), $payload)
            ->assertForbidden();
    }

    public function test_child_identity_source_has_no_production_studio_dependency(): void
    {
        $paths = [
            app_path('Models/ChildIdentityRequest.php'),
            app_path('Models/ChildIdentityPhoto.php'),
            app_path('Models/ChildIdentityGenerationAttempt.php'),
            app_path('Models/ChildIdentityEvent.php'),
            app_path('Services/ChildIdentity'),
            app_path('Http/Controllers/Front/ChildIdentityController.php'),
            app_path('Http/Controllers/Front/ChildIdentityMediaController.php'),
            app_path('Http/Controllers/Admin/ChildIdentityController.php'),
            app_path('Http/Controllers/Admin/ChildIdentityMediaController.php'),
            app_path('Http/Controllers/Admin/ChildIdentitySettingsController.php'),
            app_path('Jobs/GenerateChildIdentityAttemptJob.php'),
        ];
        $files = collect($paths)->flatMap(function (string $path): array {
            if (is_file($path)) {
                return [$path];
            }

            return collect(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)))
                ->filter(fn ($file) => $file->isFile() && $file->getExtension() === 'php')
                ->map(fn ($file) => $file->getPathname())
                ->values()
                ->all();
        });

        foreach ($files as $file) {
            $source = file_get_contents($file);

            foreach ([
                'ProductionStudio',
                'ProductionProject',
                'ProductionAutomation',
                'ProductionScene',
                'SceneGenerationJob',
                'production_studio',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    private function createPublicIdentity(): ChildIdentityRequest
    {
        $this->ageRanges();
        $identity = ChildIdentityRequest::create($this->identityAttributes());
        $this->withSession(['child_identity_grants' => [$identity->uuid]]);

        return $identity;
    }

    private function startPayload(string $uploadToken, array $photoIds): array
    {
        return [
            'parent_name' => 'مريم أحمد',
            'parent_phone' => '01001112233',
            'child_name' => 'ليلى',
            'age_range' => '٣ - ٦ سنوات',
            'upload_session_token' => $uploadToken,
            'photo_upload_ids' => $photoIds,
            'processing_consent' => '1',
        ];
    }

    private function uploadTemporaryPhotos(): array
    {
        $this->get(route('child-identity.index'))->assertOk();
        $token = (string) session('photo_upload.token');
        $ids = collect(['first.png', 'second.png'])->map(function (string $name) use ($token): string {
            return (string) $this->post(route('photo-uploads.store'), [
                'upload_session_token' => $token,
                'photo' => $this->tinyPng($name),
            ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->json('id');
        })->all();

        return [$token, $ids];
    }

    private function identityAttributes(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'resume_token_hash' => hash('sha256', Str::random(80)),
            'parent_name' => 'مريم أحمد',
            'parent_phone' => '201001112233',
            'parent_email' => 'parent@example.com',
            'child_name' => 'ليلى',
            'child_age' => 5,
            'age_range' => '٣ - ٦ سنوات',
            'gender' => 'girl',
            'status' => 'incomplete',
            'consent_accepted_at' => now(),
            'consent_version' => 'test-v1',
            'last_activity_at' => now(),
        ];
    }

    private function ageRanges(): void
    {
        Setting::updateOrCreate(['key' => 'age_ranges'], [
            'value' => json_encode(['٣ - ٦ سنوات', '٦ - ٩ سنوات'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    private function uploadTwoPhotos(ChildIdentityRequest $identity): void
    {
        $this->post(route('child-identity.photos.store', $identity->uuid), ['photo' => $this->tinyPng('one.png')])
            ->assertRedirect();
        $this->post(route('child-identity.photos.store', $identity->uuid), ['photo' => $this->tinyPng('two.png')])
            ->assertRedirect();
    }

    private function configuredOpenAi(): AiProvider
    {
        app(AiProviderRegistrySyncer::class)->sync();
        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        app(AiProviderCredentialService::class)->save($provider, 'test-openai-key');
        $provider->forceFill([
            'is_active' => true,
            'is_configured' => true,
            'is_available' => true,
            'last_health_check_status' => null,
        ])->save();
        $settings = $provider->settings_json ?? [];
        data_set($settings, 'default_models.character_sheet', 'gpt-image-2');
        $provider->forceFill(['settings_json' => $settings])->save();
        $provider->models()->where('code', 'gpt-image-2')->update(['is_active' => true]);

        return $provider->fresh();
    }

    private function runJob(ChildIdentityGenerationAttempt $attempt): void
    {
        (new GenerateChildIdentityAttemptJob($attempt->id))->handle(
            app(GptImageClient::class),
            app(AiImagePricingService::class),
            app(ChildIdentityAggregateService::class),
            app(ChildIdentityEventLogger::class),
            app(ChildIdentityShareDraftService::class),
        );
    }

    private function story(StoryCategory $category): Story
    {
        $story = Story::create([
            'title' => 'رحلة النجوم',
            'slug' => 'star-journey',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'age_range' => '٣ - ٦ سنوات',
            'price' => 399,
            'active' => true,
        ]);
        $story->categories()->attach($category);

        return $story;
    }

    private function deliveryLocation(): array
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)->firstOrFail();

        return [$country, $governorate];
    }

    private function tinyPng(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name, 20, 20);
    }

    private function tinyPngContents(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        );
    }

    private function shareableJpegContents(): string
    {
        $image = imagecreatetruecolor(900, 600);
        $background = imagecolorallocate($image, 238, 242, 255);
        $accent = imagecolorallocate($image, 79, 70, 229);
        imagefill($image, 0, 0, $background);
        imagefilledellipse($image, 450, 300, 360, 360, $accent);
        ob_start();
        imagejpeg($image, null, 92);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
