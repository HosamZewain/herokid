<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\ChildIdentityRequest;
use App\Models\Order;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\OrderSceneTextService;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderDetailsUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_scene_text_handoff_is_open_by_default_and_edit_form_is_available(): void
    {
        $admin = $this->admin();
        $story = $this->story();
        $this->addTemplates($story);
        $order = $this->order($story);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order));

        $response->assertOk()
            ->assertSee('تعديل بيانات الطلب')
            ->assertSee('حفظ ومزامنة كل البيانات')
            ->assertSee(route('admin.orders.details.update', $order), false);
        $this->assertMatchesRegularExpression(
            '/<details\b[^>]*\bopen\b[^>]*\bdata-order-scene-texts\b/u',
            $response->getContent(),
        );
    }

    public function test_admin_can_update_order_details_and_all_order_owned_snapshots(): void
    {
        $admin = $this->admin();
        $story = $this->story(['gender' => 'girl']);
        $this->addTemplates(
            $story,
            '{{child_name}} عمرها {{child_age}}',
            '{{child_name}} عمره {{child_age}}',
        );
        $order = $this->order($story, [
            'checkout_group_key' => 'CHECKOUT-EDIT',
            'child_name' => 'رنا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'delivery_details' => ['phone' => '201000000001', 'checkout_group' => 'CHECKOUT-EDIT'],
        ]);
        $sibling = $this->order($story, [
            'order_number' => 'HK-DETAIL-SIBLING',
            'checkout_group_key' => 'CHECKOUT-EDIT',
            'delivery_details' => ['phone' => '201000000001', 'checkout_group' => 'CHECKOUT-EDIT'],
        ]);
        $storyItem = $order->items()->create([
            'item_type' => 'story',
            'story_id' => $story->id,
            'title' => $story->title,
            'personalization_snapshot' => [
                'child_name' => 'رنا',
                'child_age' => 6,
                'child_age_range' => '٣ - ٦ سنوات',
                'child_gender' => 'girl',
            ],
        ]);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);
        $order->productionPromptOverride()->create([
            'prompt_text' => 'برومبت يدوي قديم لرنا',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $identity = ChildIdentityRequest::create([
            'uuid' => (string) Str::uuid(),
            'resume_token_hash' => hash('sha256', Str::random(64)),
            'parent_name' => 'ولي الأمر',
            'parent_phone' => '201000000001',
            'child_name' => 'رنا',
            'child_age' => 6,
            'age_range' => '٣ - ٦ سنوات',
            'gender' => 'girl',
            'status' => 'converted',
            'consent_accepted_at' => now(),
            'consent_version' => 'test-v1',
            'converted_order_id' => $order->id,
            'converted_at' => now(),
        ]);
        $order->forceFill(['child_identity_request_id' => $identity->id])->save();

        $this->actingAs($admin)
            ->patch(route('admin.orders.details.update', $order), $this->payload())
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame('عمر', $order->child_name);
        $this->assertSame(8, $order->child_age);
        $this->assertSame('boy', $order->child_gender);
        $this->assertSame('ولي أمر جديد', $order->parent_name);
        $this->assertSame('201111111111', data_get($order->delivery_details, 'phone'));
        $this->assertSame('ولي أمر جديد', $sibling->fresh()->parent_name);
        $this->assertSame('201111111111', data_get($sibling->fresh()->delivery_details, 'phone'));
        $this->assertSame('عمر', $identity->fresh()->child_name);
        $this->assertSame(8, $identity->fresh()->child_age);
        $this->assertSame('boy', $identity->fresh()->gender);
        $this->assertSame('ولي أمر جديد', $identity->fresh()->parent_name);
        $this->assertSame('201111111111', $identity->fresh()->parent_phone);
        $this->assertDatabaseHas('child_identity_events', [
            'child_identity_request_id' => $identity->id,
            'order_id' => $order->id,
            'event_type' => 'request.order_details_corrected',
            'actor_type' => 'admin',
        ]);

        $personalization = $storyItem->fresh()->personalization_snapshot;
        $this->assertSame('عمر', $personalization['child_name']);
        $this->assertSame(8, $personalization['child_age']);
        $this->assertSame('boy', $personalization['child_gender']);

        $firstScene = $order->sceneTextSnapshots()->where('scene_number', 1)->firstOrFail();
        $this->assertSame('alternate', $firstScene->selected_text_variant);
        $this->assertSame('عمر عمره 8', $firstScene->rendered_text);
        $this->assertSame('boy', $firstScene->render_context_snapshot['child_gender']);

        $prompt = StoryProductionPrompt::forOrder($order);
        $this->assertStringContainsString('برومبت يدوي قديم لرنا', $prompt);
        $this->assertStringContainsString('HERO_KID_ORDER_DETAILS_START', $prompt);
        $this->assertStringContainsString('- Child name: عمر', $prompt);
        $this->assertStringContainsString('- Child age: 8', $prompt);
        $this->assertStringContainsString('- Child gender: Boy', $prompt);

        $log = AdminActivityLog::where('action', 'order.details_updated')->firstOrFail();
        $this->assertSame('تصحيح البيانات بعد تواصل العميل', data_get($log->properties, 'reason'));
        $this->assertSame('عمر', data_get($log->properties, 'changes.child_name.new'));
        $this->assertTrue((bool) data_get($log->properties, 'scene_snapshots_refreshed'));
    }

    public function test_production_project_is_safely_synchronized_without_overwriting_manual_scene_text(): void
    {
        $admin = $this->admin();
        $story = $this->story(['gender' => 'girl']);
        $this->addTemplates(
            $story,
            '{{child_name}} عمرها {{child_age}}',
            '{{child_name}} عمره {{child_age}}',
        );
        $order = $this->order($story, [
            'child_name' => 'رنا',
            'child_age' => 6,
            'child_gender' => 'girl',
        ]);
        app(OrderSceneTextService::class)->snapshotForOrder($order, $story);
        $oldSceneText = $order->sceneTextSnapshots()->where('scene_number', 1)->value('rendered_text');

        $project = ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'draft',
            'current_stage' => 'scenes',
            'personalized_hero_name' => 'رنا',
            'personalization_status' => 'personalized',
            'source_snapshot_json' => ['child_name' => 'رنا', 'custom_key' => 'preserved'],
        ]);
        $automatic = $project->scenes()->create([
            'scene_number' => 1,
            'story_text' => $oldSceneText,
            'visual_direction' => 'رنا داخل الغرفة',
            'child_action_pose' => 'رنا واقفة',
            'status' => 'draft',
            'personalized_hero_name' => 'رنا',
            'personalization_status' => 'personalized',
        ]);
        $manual = $project->scenes()->create([
            'scene_number' => 2,
            'story_text' => 'صياغة يدوية خاصة لرنا',
            'visual_direction' => 'لقطة خاصة لرنا',
            'child_action_pose' => 'رنا ترفع يدها',
            'status' => 'draft',
            'personalized_hero_name' => 'رنا',
            'personalization_status' => 'personalized',
        ]);
        $version = $project->storyVersions()->create([
            'version_number' => 1,
            'title' => 'قصة رنا',
            'full_story_content' => 'رنا هي بطلة القصة',
            'status' => 'draft',
        ]);
        $qa = $project->qaChecks()->create([
            'category' => 'child_identity',
            'item_key' => 'child_gender_correct',
            'label' => 'Gender',
            'result' => 'pass',
            'reviewed_by_user_id' => $admin->id,
            'reviewed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.orders.details.update', $order), $this->payload())
            ->assertRedirect(route('admin.orders.show', $order));

        $this->assertSame('عمر عمره 8', $automatic->fresh()->story_text);
        $this->assertSame('عمر داخل الغرفة', $automatic->fresh()->visual_direction);
        $this->assertSame('صياغة يدوية خاصة لعمر', $manual->fresh()->story_text);
        $this->assertSame('needs_review', $manual->fresh()->personalization_status);
        $this->assertStringContainsString(
            'تم تغيير عمر/جنس الطفل',
            implode(' ', $manual->fresh()->personalization_warnings),
        );

        $project->refresh();
        $this->assertSame('عمر', $project->personalized_hero_name);
        $this->assertSame('needs_review', $project->personalization_status);
        $this->assertSame('عمر', data_get($project->source_snapshot_json, 'child_name'));
        $this->assertSame(8, data_get($project->source_snapshot_json, 'child_age'));
        $this->assertSame('preserved', data_get($project->source_snapshot_json, 'custom_key'));
        $this->assertSame('قصة عمر', $version->fresh()->title);
        $this->assertSame('not_reviewed', $qa->fresh()->result);
        $this->assertDatabaseHas('production_project_activity_logs', [
            'production_project_id' => $project->id,
            'action' => 'order.details_synced',
        ]);
    }

    public function test_update_requires_orders_update_permission(): void
    {
        $user = User::factory()->create(['role' => 'customer']);
        $story = $this->story();
        $order = $this->order($story);

        $this->actingAs($user)
            ->patch(route('admin.orders.details.update', $order), $this->payload())
            ->assertForbidden();
    }

    private function payload(): array
    {
        return [
            'parent_name' => 'ولي أمر جديد',
            'phone' => '201111111111',
            'child_name' => 'عمر',
            'child_age' => 8,
            'child_gender' => 'boy',
            'language' => 'ar',
            'lesson' => 'الشجاعة',
            'interests' => 'الفضاء والرسم',
            'gift_note' => 'إلى بطلنا عمر',
            'parent_notes' => 'استخدم اللون الأزرق',
            'change_reason' => 'تصحيح البيانات بعد تواصل العميل',
        ];
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function story(array $overrides = []): Story
    {
        return Story::create(array_merge([
            'title' => 'قصة التعديل',
            'slug' => 'order-details-'.Str::lower(Str::random(6)),
            'language' => 'ar',
            'gender' => 'both',
            'age_range' => '٦ - ٩ سنوات',
            'price' => 349,
            'active' => true,
        ], $overrides));
    }

    private function order(Story $story, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'HK-DETAIL-'.Str::upper(Str::random(6)),
            'parent_name' => 'ولي الأمر',
            'story_id' => $story->id,
            'child_name' => 'رنا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'lesson' => 'التعاون',
            'interests' => 'الرسم',
            'gift_note' => 'إلى رنا',
            'parent_notes' => 'ملاحظة قديمة',
            'status' => 'new',
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [],
        ], $overrides));
    }

    private function addTemplates(Story $story, string $original = '{{child_name}} {{child_age}}', ?string $alternate = null): void
    {
        foreach (range(1, 13) as $sceneNumber) {
            $story->sceneTemplates()->create([
                'scene_number' => $sceneNumber,
                'title' => 'المشهد '.$sceneNumber,
                'text_template' => $original,
                'alternate_text_template' => $alternate,
            ]);
        }
    }
}
