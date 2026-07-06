<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProductionStudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_studio_list_is_empty_by_default_and_orders_do_not_appear_automatically(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)
            ->get(route('admin.production-studio.index'))
            ->assertOk()
            ->assertSee('لا توجد مشاريع داخل استوديو الإنتاج بعد')
            ->assertDontSee($order->order_number);

        $this->assertDatabaseCount('production_projects', 0);
    }

    public function test_sending_order_creates_linked_project_without_changing_order_or_prompt(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['status' => 'new']);
        $promptBefore = StoryProductionPrompt::forOrder($order);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.from-order', $order))
            ->assertRedirect();

        $project = ProductionProject::firstOrFail();

        $this->assertSame($order->id, $project->order_id);
        $this->assertSame('draft', $project->status);
        $this->assertSame('intake', $project->current_stage);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame($promptBefore, StoryProductionPrompt::forOrder($order->fresh(['story'])));
        $this->assertDatabaseHas('production_project_activity_logs', [
            'production_project_id' => $project->id,
            'action' => 'project.created',
        ]);
    }

    public function test_sending_same_order_twice_redirects_to_existing_project_without_duplicate(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.production-studio.from-order', $order))
            ->assertRedirect(route('admin.production-studio.show', $project))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('production_projects', 1);
    }

    public function test_order_page_shows_studio_action_or_existing_project_status(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('إرسال إلى استوديو الإنتاج')
            ->assertSee('ولا يؤثر على سير الطلب الحالي');

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order->fresh()))
            ->assertOk()
            ->assertSee('فتح مشروع الاستوديو')
            ->assertSee('مسودة')
            ->assertDontSee('إرسال إلى استوديو الإنتاج');
    }

    public function test_feature_flag_hides_menu_and_order_action_and_blocks_routes(): void
    {
        Config::set('production_studio.enabled', false);

        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('استوديو الإنتاج')
            ->assertDontSee('إرسال إلى استوديو الإنتاج');

        $this->actingAs($admin)
            ->get(route('admin.production-studio.index'))
            ->assertNotFound();

        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_feature_flag_blocks_direct_studio_project_action_and_photo_routes(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['uploaded_photos' => ['orders/photos/kid.png']]);

        Storage::fake('local');
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();
        $version = $project->storyVersions()->create([
            'version_number' => 1,
            'title' => 'Studio Draft',
            'status' => 'draft',
        ]);
        $scene = $project->scenes()->create([
            'scene_number' => 1,
            'title' => 'Scene 1',
            'status' => 'draft',
        ]);
        $qaCheck = $project->qaChecks()->firstOrFail();

        Config::set('production_studio.enabled', false);

        $this->actingAs($admin)->get(route('admin.production-studio.index'))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.production-studio.show', $project))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.production-studio.photo', [$project, 0]))->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.production-studio.update', $project), ['status' => 'in_progress'])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.production-studio.archive', $project))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.production-studio.cancel', $project), ['cancel_reason' => 'disabled'])->assertNotFound();
        $this->actingAs($admin)->post(route('admin.production-studio.reopen', $project))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.production-studio.story-versions.from-story', $project))->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.production-studio.story-versions.review', [$project, $version]), ['status' => 'approved'])->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.production-studio.character-profile.update', $project))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.production-studio.scenes.store', $project), ['scene_number' => 2])->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.production-studio.scenes.update', [$project, $scene]), ['scene_number' => 1])->assertNotFound();
        $this->actingAs($admin)->patch(route('admin.production-studio.qa.update', [$project, $qaCheck]), ['result' => 'pass'])->assertNotFound();

        $this->assertSame('draft', $project->fresh()->status);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_existing_active_admins_receive_studio_permissions_when_permission_migration_runs(): void
    {
        $admin = $this->adminWithPermissions([]);
        $this->assertFalse($admin->hasPermission('production_studio.view'));

        $migration = require database_path('migrations/2026_07_06_000002_add_production_studio_permissions.php');
        $migration->up();

        $this->assertTrue($admin->refresh()->hasPermission('production_studio.view'));
        $this->assertTrue($admin->hasPermission('production_studio.create_from_order'));
    }

    public function test_unauthorized_admin_cannot_view_studio_or_child_photo(): void
    {
        Storage::fake('local');

        $owner = $this->adminUser();
        $order = $this->orderWithStory([
            'uploaded_photos' => ['orders/photos/kid.png'],
        ]);
        Storage::disk('local')->put('orders/photos/kid.png', 'image-bytes');

        $this->actingAs($owner)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();
        $limitedAdmin = $this->adminWithPermissions(['orders.view']);

        $this->actingAs($limitedAdmin)
            ->get(route('admin.production-studio.show', $project))
            ->assertForbidden();

        $this->actingAs($limitedAdmin)
            ->get(route('admin.production-studio.photo', [$project, 0]))
            ->assertForbidden();
    }

    public function test_studio_photo_route_only_serves_linked_order_photos_and_rejects_bad_indexes(): void
    {
        Storage::fake('local');

        $admin = $this->adminUser();
        $order = $this->orderWithStory([
            'uploaded_photos' => ['orders/photos/kid-owned.png'],
        ]);
        Storage::disk('local')->put('orders/photos/kid-owned.png', 'owned-image-bytes');

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.production-studio.photo', [$project, 0]))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.production-studio.photo', [$project, 1]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->get('/admin/production-studio/'.$project->id.'/photos/not-a-number')
            ->assertNotFound();

        $order->update(['uploaded_photos' => ['../secret.txt']]);

        $this->actingAs($admin)
            ->get(route('admin.production-studio.photo', [$project, 0]))
            ->assertNotFound();
    }

    public function test_story_draft_creation_does_not_overwrite_original_story_record(): void
    {
        $admin = $this->adminUser();
        $story = $this->story([
            'title' => 'القصة الأصلية',
            'full_desc' => 'النص الأصلي للقصة.',
        ]);
        $order = $this->orderWithStory(['story_id' => $story->id], story: $story);
        $originalStoryData = $story->only(['title', 'full_desc']);

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.production-studio.story-versions.from-story', $project))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('production_story_versions', [
            'production_project_id' => $project->id,
            'version_number' => 1,
            'title' => 'القصة الأصلية',
        ]);
        $this->assertSame($originalStoryData, $story->fresh()->only(['title', 'full_desc']));
    }

    public function test_qa_blocker_prevents_ready_for_print_without_override_and_allows_with_reason(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();

        $this->actingAs($admin)
            ->from(route('admin.production-studio.show', $project))
            ->patch(route('admin.production-studio.update', $project), [
                'status' => 'ready_for_print',
                'current_stage' => 'print_ready',
            ])
            ->assertRedirect(route('admin.production-studio.show', $project))
            ->assertSessionHasErrors('qa_override_reason');

        $this->assertNotSame('ready_for_print', $project->fresh()->status);

        $this->actingAs($admin)
            ->patch(route('admin.production-studio.update', $project), [
                'status' => 'ready_for_print',
                'current_stage' => 'print_ready',
                'qa_override_reason' => 'تمت مراجعة البنود يدويًا خارج النظام.',
            ])
            ->assertRedirect();

        $this->assertSame('ready_for_print', $project->fresh()->status);
        $this->assertTrue($project->qaChecks()->where('override_allowed', true)->exists());
    }

    public function test_archive_cancel_reopen_and_resend_do_not_affect_order_or_prompt_or_create_duplicates(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['status' => 'under_review']);
        $promptBefore = StoryProductionPrompt::forOrder($order);

        $this->actingAs($admin)->post(route('admin.production-studio.from-order', $order))->assertRedirect();
        $project = ProductionProject::firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.production-studio.archive', $project))
            ->assertRedirect();

        $this->assertSame('archived', $project->fresh()->status);
        $this->assertSame('under_review', $order->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.reopen', $project))
            ->assertRedirect();

        $this->assertSame('draft', $project->fresh()->status);
        $this->assertSame('under_review', $order->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.cancel', $project), ['cancel_reason' => 'تجربة فقط'])
            ->assertRedirect();

        $this->assertSame('cancelled', $project->fresh()->status);
        $this->assertSame('under_review', $order->fresh()->status);

        $this->actingAs($admin)
            ->post(route('admin.production-studio.reopen', $project))
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.production-studio.from-order', $order))
            ->assertRedirect(route('admin.production-studio.show', $project));

        $this->assertDatabaseCount('production_projects', 1);
        $this->assertSame('under_review', $order->fresh()->status);
        $this->assertSame($promptBefore, StoryProductionPrompt::forOrder($order->fresh(['story'])));
    }

    public function test_existing_order_status_update_still_works_when_studio_module_exists(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['status' => 'new']);

        $this->actingAs($admin)
            ->patch(route('admin.orders.update', $order), [
                'status' => 'under_review',
                'admin_notes' => 'مراجعة عادية خارج الاستوديو.',
            ])
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame('under_review', $order->fresh()->status);
        $this->assertDatabaseCount('production_projects', 0);
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

    private function story(array $overrides = []): Story
    {
        return Story::create(array_merge([
            'title' => 'رحلة القمر قبل النوم',
            'slug' => 'moon-trip-'.Str::random(6),
            'short_desc' => 'قصة قصيرة عن الهدوء والشجاعة.',
            'full_desc' => 'مشهد أول. مشهد ثاني. مشهد ثالث.',
            'age_range' => '6-9 سنوات',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 100,
            'active' => true,
        ], $overrides));
    }

    private function orderWithStory(array $overrides = [], ?Story $story = null): Order
    {
        $story ??= $this->story();

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
            'gift_note' => 'إهداء',
            'parent_notes' => 'ملاحظات خاصة للطفل.',
            'delivery_details' => ['phone' => '01111822277', 'country' => 'Egypt'],
            'uploaded_photos' => [],
            'status' => 'new',
        ], $overrides));
    }
}
