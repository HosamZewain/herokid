<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\Story;
use App\Models\StorySceneTemplate;
use App\Models\User;
use App\Services\Orders\OrderChildIdentityPromptService;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderChildIdentityPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_page_shows_separate_identity_prompt_with_story_role_context(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $order = $this->orderWithStory();
        $photoPath = 'orders/photos/identity/kid.png';
        Storage::disk('local')->put($photoPath, 'image-bytes');
        $order->update(['uploaded_photos' => [$photoPath]]);

        StorySceneTemplate::create([
            'story_id' => $order->story_id,
            'scene_number' => 1,
            'title' => 'بداية المغامرة',
            'text_template' => 'يرتدي {{child_name}} زي رائد فضاء وينطلق لإنقاذ محطة الأمل.',
        ]);
        StorySceneTemplate::create([
            'story_id' => $order->story_id,
            'scene_number' => 7,
            'title' => 'مواجهة الشهب',
            'text_template' => 'يقود {{child_name}} الفريق بشجاعة وسط الشهب المتساقطة.',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.orders.show', $order));

        $response->assertOk()
            ->assertSee('Child Identity Prompt')
            ->assertSee('Story Production Prompt')
            ->assertSee('ينشئ هوية الطفل فقط قبل توليد المشاهد')
            ->assertSee('نسخ برومبت الهوية');

        $prompt = $this->identityPromptFromResponse($response->getContent());
        $this->assertStringContainsString('Create ONLY the reusable child hero identity', $prompt);
        $this->assertStringContainsString('- Child name: كريم', $prompt);
        $this->assertStringContainsString('- Story title: بطل محطة الأمل', $prompt);
        $this->assertStringContainsString('يرتدي كريم زي رائد فضاء', $prompt);
        $this->assertStringContainsString('يقود كريم الفريق بشجاعة', $prompt);
        $this->assertStringContainsString('/orders/'.$order->id.'/production-photos/0', $prompt);
        $this->assertStringContainsString('Do not generate any story scenes yet.', $prompt);
        $this->assertStringNotContainsString($photoPath, $prompt);

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('برومبت إنشاء هوية الطفل كريم')
            ->assertSee('رفع الهوية المعتمدة')
            ->assertSee('data-copy-inline-production-prompt="child-identity-prompt-'.$order->id.'"', false);
    }

    public function test_admin_can_upload_an_approved_identity_without_changing_the_story_prompt_template(): void
    {
        Storage::fake('local');
        $admin = $this->adminUser();
        $order = $this->orderWithStory();
        Setting::query()->updateOrCreate(
            ['key' => StoryProductionPrompt::SETTING_KEY],
            ['value' => 'Current production template {{approved_child_identity_reference}}'],
        );
        $templateBefore = StoryProductionPrompt::activeTemplate();

        $this->actingAs($admin)
            ->post(route('admin.orders.approved-child-identity.store', $order), [
                'approved_identity' => UploadedFile::fake()->image('approved-identity.png', 1200, 800),
            ])
            ->assertRedirect(route('admin.orders.groups.show', $order).'#story-identity-'.$order->id)
            ->assertSessionHas('success');

        $order->refresh()->load('childIdentityRequest', 'childIdentityApprovedAttempt');
        $attempt = $order->childIdentityApprovedAttempt;

        $this->assertNotNull($order->childIdentityRequest);
        $this->assertNotNull($attempt);
        $this->assertSame('manual-upload', $attempt->provider);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame($attempt->id, $order->childIdentityRequest->approved_attempt_id);
        Storage::disk('local')->assertExists($attempt->output_storage_path);
        $this->assertSame($templateBefore, StoryProductionPrompt::activeTemplate());
        $this->assertStringContainsString(
            route('orders.approved-child-identity', $order),
            urldecode(StoryProductionPrompt::forOrder($order)),
        );

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('هوية معتمدة ومربوطة')
            ->assertSee('استبدال الهوية المعتمدة')
            ->assertSee('فتح الهوية المعتمدة');
    }

    public function test_identity_prompt_override_keeps_managed_order_context_current(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory();

        $this->actingAs($admin)
            ->post(route('admin.orders.child-identity-prompt.override', $order), [
                'prompt_text' => 'CUSTOM IDENTITY DIRECTIONS: keep the hero outfit blue and silver.',
            ])
            ->assertRedirect();

        $order->update(['child_name' => 'سليم', 'child_age' => 9]);
        $prompt = app(OrderChildIdentityPromptService::class)->forOrder($order->fresh());

        $this->assertStringContainsString('CUSTOM IDENTITY DIRECTIONS', $prompt);
        $this->assertStringContainsString('- Child name: سليم', $prompt);
        $this->assertStringContainsString('- Child age: 9', $prompt);
        $this->assertDatabaseHas('order_child_identity_prompt_overrides', [
            'order_id' => $order->id,
            'updated_by' => $admin->id,
        ]);
    }

    public function test_admin_can_snapshot_and_reset_identity_prompt_without_changing_production_prompt(): void
    {
        $admin = $this->adminUser();
        $order = $this->orderWithStory();
        $productionPromptBefore = StoryProductionPrompt::forOrder($order);

        $this->actingAs($admin)
            ->post(route('admin.orders.child-identity-prompt.override', $order), [
                'prompt_text' => 'Identity-only custom prompt.',
            ])
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('admin.orders.child-identity-prompt.snapshot', $order), [
                'prompt_text' => app(OrderChildIdentityPromptService::class)->forOrder($order->fresh()),
                'snapshot_reason' => 'before-customer-review',
            ])
            ->assertRedirect();

        $snapshot = $order->childIdentityPromptSnapshots()->firstOrFail();
        $this->assertSame('1.0', $snapshot->prompt_version);
        $this->assertSame('before-customer-review', $snapshot->snapshot_reason);
        $this->assertStringContainsString('Identity-only custom prompt.', $snapshot->prompt_text);

        $this->actingAs($admin)
            ->delete(route('admin.orders.child-identity-prompt.override-reset', $order))
            ->assertRedirect();

        $this->assertFalse($order->childIdentityPromptOverride()->exists());
        $this->assertSame($productionPromptBefore, StoryProductionPrompt::forOrder($order->fresh()));
    }

    public function test_product_only_order_does_not_show_identity_prompt(): void
    {
        $order = Order::create([
            'order_number' => 'HK-2026-PRODUCT',
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('id="child-identity-prompt"', false);
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

    private function orderWithStory(): Order
    {
        $story = Story::create([
            'title' => 'بطل محطة الأمل',
            'slug' => 'hope-station-'.Str::random(6),
            'short_desc' => 'مغامرة فضائية عن القيادة والعمل الجماعي.',
            'full_desc' => '<p>يسافر بطل القصة إلى محطة فضائية ويقود فريقه لإنقاذها.</p>',
            'age_range' => '6-9 سنوات',
            'gender' => 'both',
            'language' => 'ar',
            'lesson_value' => 'القيادة والعمل الجماعي',
            'price' => 349,
            'active' => true,
        ]);

        return Order::create([
            'order_number' => 'HK-2026-'.strtoupper(Str::random(6)),
            'story_id' => $story->id,
            'child_name' => 'كريم',
            'child_age' => 7,
            'child_gender' => 'boy',
            'language' => 'ar',
            'lesson' => 'القيادة والعمل الجماعي',
            'interests' => 'الفضاء والروبوتات',
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }

    private function identityPromptFromResponse(string $content): string
    {
        preg_match('/<textarea[^>]*id="child-identity-prompt"[^>]*>(.*?)<\/textarea>/s', $content, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
