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
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\GenerationInputAssetResolver;
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

        $this->actingAs($owner)
            ->get(route('admin.production-studio.assets.show', [$project, $asset]))
            ->assertOk();
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
        $this->assertStringContainsString('Character sheet requirements', $job->prompt_snapshot);
        $this->assertSame('0.0300', (string) $job->estimated_cost);
        $this->assertSame('estimate', $job->cost_source);
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

        $this->actingAs($admin)
            ->post(route('admin.production-studio.ai.scene', [$project, $scene]), $this->generationPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $job = SceneGenerationJob::firstOrFail();

        $this->assertSame($scene->id, $job->production_scene_id);
        $this->assertSame('scene_image', $job->job_type);
        $this->assertSame('character_scene', $job->generation_mode);
        $this->assertStringContainsString('Moon Scene', $job->prompt_snapshot);
        Queue::assertPushed(SubmitAiGenerationJob::class);
    }

    public function test_authorized_user_can_create_queued_cover_generation_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');
        $this->enableFal();

        $admin = $this->adminUser();
        $project = $this->projectWithApprovedPhoto(['orders/photos/kid.png']);

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
        $this->assertStringContainsString('Cover requirements', $job->prompt_snapshot);
        $this->assertSame('0.0800', (string) $job->estimated_cost);
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
        Storage::disk('local')->assertExists($asset->file_path);
        Storage::disk('public')->assertMissing($asset->file_path);
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
        Config::set('production_studio.ai.fal.enabled', true);
        Config::set('production_studio.ai.fal.key', $key);
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
            'appearance_summary' => 'Curly dark hair and warm smile.',
            'approved_reference_photos' => [0],
            'reference_photo_selection' => [0],
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
