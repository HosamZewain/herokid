<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Support\DefaultStoryProductionPromptTemplate;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class AdminOrderProductionPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_story_production_prompt_section_renders_order_story_and_image_urls(): void
    {
        Storage::fake('local');

        $admin = $this->adminUser();
        $story = Story::create([
            'title' => 'ليلة نوم هادئة',
            'slug' => 'calm-sleep',
            'short_desc' => 'قصة قصيرة عن الهدوء والشجاعة قبل النوم.',
            'full_desc' => '<p>مغامرة ناعمة تساعد الطفل على الشعور بالأمان.</p>',
            'age_range' => '8-10 سنوات',
            'language' => 'ar',
            'lesson_value' => 'الهدوء والاستقلال',
            'price' => 100,
            'active' => true,
        ]);

        $photoPath = 'orders/photos/2026-06/kid.png';
        Storage::disk('local')->put($photoPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        $order = Order::create([
            'order_number' => 'HK-2026-PROMPT01',
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 4,
            'child_gender' => 'girl',
            'language' => 'ar',
            'lesson' => 'الهدوء والاستقلال',
            'interests' => "الرسم والنجوم و Frozen & Spider-Man\nألوان بنفسجية ومساحات هادئة",
            'gift_note' => 'إلى رينا الجميلة',
            'parent_notes' => 'تحب الألوان الهادئة.',
            'delivery_details' => ['email' => 'parent@example.test', 'phone' => '201000000000'],
            'uploaded_photos' => [$photoPath],
            'status' => 'new',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order));

        $response->assertOk();
        $response->assertSee('Story Production Prompt');
        $response->assertSee('Copy Prompt');
        $response->assertSee('تم إنشاؤه من قالب الإنتاج العام');
        $response->assertSee('إعادة إنشاء من القالب الحالي');
        $response->assertSee('Save as Order-Specific Prompt');
        $response->assertSee('Using Global Template');
        $response->assertSee('سيتم استبدال التعديلات اليدوية بالنسخة الجديدة من القالب. هل تريد المتابعة؟');
        $response->assertSee('تم نسخ برومبت الإنتاج بنجاح');
        $response->assertSee('HK-2026-PROMPT01');
        $response->assertSee('رينا');
        $response->assertSee('ليلة نوم هادئة');
        $prompt = $this->productionPromptFromResponse($response->getContent());

        $this->assertStringContainsString('- Selected story age range: 8-10 سنوات', $prompt);
        $this->assertStringContainsString("Interests / favorite themes: الرسم والنجوم و Frozen & Spider-Man\nألوان بنفسجية ومساحات هادئة", $prompt);
        $this->assertStringContainsString('The child’s interests are parent-provided creative preferences', $prompt);
        $this->assertStringContainsString('The final Hero Kid book must always contain exactly 28 A4 portrait pages.', $prompt);
        $this->assertStringContainsString('- 7 physical A3 sheets', $prompt);
        $this->assertStringContainsString('- The story must contain exactly 13 complete scenes.', $prompt);
        $this->assertStringContainsString('## Reader Order and Print Imposition Rules', $prompt);
        $this->assertStringContainsString('- Scene 13: Pages 26–27', $prompt);
        $this->assertStringContainsString('Do not confuse reader-order scene spreads with printer-imposed A3 sheet sides.', $prompt);
        $this->assertStringContainsString('## Spread Illustration and Text Layout Rules', $prompt);
        $this->assertStringContainsString('one single connected full-width A3 landscape illustration across two facing A4 pages', $prompt);
        $this->assertStringContainsString('- Final production canvas for each A3 spread: exactly 4961 × 3508 px.', $prompt);
        $this->assertStringContainsString('- The selected story age range was used as the primary writing-complexity reference.', $prompt);
        $this->assertStringContainsString('- The child’s raw parent-provided interests were preserved in the prompt.', $prompt);
        $this->assertStringNotContainsString('Each A4 page area must be exactly: `2480 × 3508 px`', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/0', $prompt);
        $this->assertStringNotContainsString('parent@example.test', $prompt);
        $this->assertStringNotContainsString('201000000000', $prompt);
    }

    public function test_story_production_prompt_handles_missing_optional_data_images_and_story(): void
    {
        $admin = $this->adminUser();
        $story = Story::create([
            'title' => 'Temporary Story',
            'slug' => 'temporary-story',
            'language' => 'ar',
            'price' => 100,
            'active' => true,
        ]);

        $order = Order::create([
            'order_number' => 'HK-2026-MISSING',
            'story_id' => $story->id,
            'child_name' => 'سليم',
            'child_age' => 6,
            'child_gender' => 'boy',
            'language' => 'ar',
            'uploaded_photos' => null,
            'status' => 'new',
        ]);
        $order->setRelation('story', null);
        $order->setRelation('user', null);
        $order->setRelation('statusLogs', collect());
        $order->setRelation('previews', collect());

        $response = $this->actingAs($admin)->view('admin.orders.show', [
            'order' => $order,
            'storyProductionPrompt' => StoryProductionPrompt::forOrder($order),
            'globalStoryProductionPrompt' => StoryProductionPrompt::forOrder($order, useOverride: false),
            'productionPromptTemplateSetting' => StoryProductionPrompt::templateSetting(),
            'errors' => new ViewErrorBag,
        ]);

        $response->assertSee('Story Production Prompt');
        $response->assertSee('HK-2026-MISSING');
        $response->assertSee('سليم');
        $response->assertSee('- Story title: Not available');
        $response->assertSee('- Selected story age range: Not available');
        $response->assertSee('No child images were attached to this order.');
    }

    public function test_admin_can_access_and_save_global_story_production_prompt_template(): void
    {
        $admin = $this->adminUser();
        $template = "Order: {{order_number}}\nChild: {{child_name}}\nStory: {{story_title}}";

        $this->actingAs($admin)
            ->get(route('admin.settings.story-production-prompt.edit'))
            ->assertOk()
            ->assertSee('قالب برومبت إنتاج القصة')
            ->assertSee('{{child_name}}')
            ->assertSee('استعادة القالب الافتراضي');

        $this->actingAs($admin)
            ->put(route('admin.settings.story-production-prompt.update'), ['template' => $template])
            ->assertRedirect(route('admin.settings.story-production-prompt.edit'));

        $this->assertDatabaseHas('settings', [
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => $template,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_non_admin_cannot_access_prompt_template_settings(): void
    {
        $user = User::factory()->create(['role' => 'customer']);

        $this->actingAs($user)
            ->get(route('admin.settings.story-production-prompt.edit'))
            ->assertForbidden();
    }

    public function test_unknown_variables_prevent_saving_template(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)
            ->from(route('admin.settings.story-production-prompt.edit'))
            ->put(route('admin.settings.story-production-prompt.update'), [
                'template' => 'Hello {{unknown_variable}}',
            ])
            ->assertRedirect(route('admin.settings.story-production-prompt.edit'))
            ->assertSessionHasErrors('template');

        $this->assertDatabaseMissing('settings', [
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => 'Hello {{unknown_variable}}',
        ]);
    }

    public function test_order_details_uses_active_global_template_and_renders_missing_data(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory([
            'order_number' => 'HK-2026-TEMPLATE',
            'child_name' => 'ليلى',
            'parent_notes' => null,
            'uploaded_photos' => [],
        ], [
            'title' => 'قصة القمر',
            'short_desc' => null,
        ]);

        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => "Order {{order_number}}\nChild {{child_name}}\nStory {{story_title}}\nMissing {{story_short_description}}\nNotes {{customer_notes}}\nImages:\n{{child_image_references}}",
        ]);

        $prompt = $this->productionPromptFromResponse(
            $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->getContent()
        );

        $this->assertStringContainsString('Order HK-2026-TEMPLATE', $prompt);
        $this->assertStringContainsString('Child ليلى', $prompt);
        $this->assertStringContainsString('Story قصة القمر', $prompt);
        $this->assertStringContainsString('Missing Not available', $prompt);
        $this->assertStringContainsString('Notes Not available', $prompt);
        $this->assertStringContainsString('No child images were attached to this order.', $prompt);
    }

    public function test_child_image_variable_renders_numbered_secure_urls(): void
    {
        Storage::fake('local');

        $admin = $this->adminUser();
        $photoPath = 'orders/photos/2026-07/kid.png';
        Storage::disk('local')->put($photoPath, 'image-bytes');
        $order = $this->orderWithStory([
            'uploaded_photos' => [$photoPath],
        ]);
        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => "Images:\n{{child_image_references}}",
        ]);

        $prompt = $this->productionPromptFromResponse(
            $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->getContent()
        );

        $this->assertStringContainsString('1. http://localhost/orders/'.$order->id.'/production-photos/0', $prompt);
        $this->assertStringNotContainsString($photoPath, $prompt);
    }

    public function test_order_specific_override_is_used_and_can_be_reset_to_global_template(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['child_name' => 'مراد']);

        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => 'Global {{child_name}}',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.production-prompt.override', $order), [
                'prompt_text' => 'Special prompt for this order',
            ])
            ->assertRedirect();

        $prompt = $this->productionPromptFromResponse(
            $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->getContent()
        );
        $this->assertSame('Special prompt for this order', trim($prompt));
        $this->assertStringContainsString('Using Order-Specific Override', $this->actingAs($admin)->get(route('admin.orders.show', $order))->getContent());

        Setting::where('key', StoryProductionPrompt::SETTING_KEY)->update(['value' => 'Updated global {{child_name}}']);
        $this->assertSame('Special prompt for this order', StoryProductionPrompt::forOrder($order->fresh()));

        $this->actingAs($admin)
            ->delete(route('admin.orders.production-prompt.override-reset', $order))
            ->assertRedirect();

        $this->assertSame('Updated global مراد', StoryProductionPrompt::forOrder($order->fresh()));
    }

    public function test_prompt_snapshot_remains_unchanged_after_template_update(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['child_name' => 'رينا']);

        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => 'Snapshot {{child_name}}',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.orders.production-prompt.snapshot', $order), [
                'prompt_text' => StoryProductionPrompt::forOrder($order),
                'snapshot_reason' => 'manual-test',
            ])
            ->assertRedirect();

        Setting::where('key', StoryProductionPrompt::SETTING_KEY)->update(['value' => 'Changed {{child_name}}']);

        $snapshot = $order->productionPromptSnapshots()->firstOrFail();
        $this->assertSame('Snapshot رينا', $snapshot->prompt_text);
        $this->assertSame('manual-test', $snapshot->snapshot_reason);
    }

    public function test_production_status_update_creates_snapshot_once(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['child_name' => 'رينا']);
        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => 'Auto {{child_name}}',
        ]);

        $this->actingAs($admin)->patch(route('admin.orders.update', $order), [
            'status' => 'generating',
            'admin_notes' => null,
        ])->assertRedirect();
        $this->actingAs($admin)->patch(route('admin.orders.update', $order), [
            'status' => 'printing',
            'admin_notes' => null,
        ])->assertRedirect();

        $this->assertSame(1, $order->productionPromptSnapshots()->count());
        $this->assertSame('Auto رينا', $order->productionPromptSnapshots()->first()->prompt_text);
    }

    public function test_restore_default_template_restores_maintained_default(): void
    {
        $admin = $this->adminUser();
        Setting::create([
            'key' => StoryProductionPrompt::SETTING_KEY,
            'value' => 'Custom {{child_name}}',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.story-production-prompt.reset'))
            ->assertRedirect(route('admin.settings.story-production-prompt.edit'));

        $this->assertSame(
            DefaultStoryProductionPromptTemplate::text(),
            Setting::where('key', StoryProductionPrompt::SETTING_KEY)->value('value')
        );
    }

    public function test_prompt_template_preview_renders_selected_order_without_saving(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory(['child_name' => 'سليم']);

        $this->actingAs($admin)
            ->post(route('admin.settings.story-production-prompt.preview'), [
                'template' => 'Preview {{child_name}}',
                'preview_order_id' => $order->id,
            ])
            ->assertRedirect(route('admin.settings.story-production-prompt.edit', ['preview_order_id' => $order->id]));

        $this->actingAs($admin)
            ->withSession(['_old_input' => ['template' => 'Preview {{child_name}}']])
            ->get(route('admin.settings.story-production-prompt.edit', ['preview_order_id' => $order->id]))
            ->assertOk()
            ->assertSee('Preview سليم');
    }

    public function test_signed_production_photo_url_serves_child_image_without_admin_session(): void
    {
        Storage::fake('local');

        $story = Story::create([
            'title' => 'Test Story',
            'slug' => 'test-story',
            'language' => 'ar',
            'price' => 100,
            'active' => true,
        ]);

        $photoPath = 'orders/photos/2026-06/kid.png';
        Storage::disk('local')->put($photoPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        $order = Order::create([
            'order_number' => 'HK-2026-SIGNED',
            'story_id' => $story->id,
            'child_name' => 'Rina',
            'child_age' => 4,
            'child_gender' => 'girl',
            'uploaded_photos' => [$photoPath],
            'status' => 'new',
        ]);

        $response = $this->get(URL::signedRoute('orders.production-photo', [$order, 0]));

        $response->assertOk();
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

    private function orderWithStory(array $orderOverrides = [], array $storyOverrides = []): Order
    {
        $story = Story::create(array_merge([
            'title' => 'Test Story',
            'slug' => 'test-story-'.Str::random(6),
            'short_desc' => 'Short story description.',
            'full_desc' => '<p>Full story content.</p>',
            'age_range' => '6-9 سنوات',
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 100,
            'active' => true,
        ], $storyOverrides));

        return Order::create(array_merge([
            'order_number' => 'HK-2026-'.strtoupper(Str::random(6)),
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'lesson' => 'الشجاعة',
            'interests' => 'الرسم',
            'gift_note' => 'إهداء',
            'parent_notes' => 'ملاحظات',
            'uploaded_photos' => [],
            'status' => 'new',
        ], $orderOverrides));
    }

    private function productionPromptFromResponse(string $content): string
    {
        preg_match('/<textarea[^>]*id="story-production-prompt"[^>]*>(.*?)<\/textarea>/s', $content, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
