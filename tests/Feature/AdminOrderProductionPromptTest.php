<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Support\StoryProductionPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
        $this->assertStringContainsString('/orders/' . $order->id . '/production-photos/0', $prompt);
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
            'errors' => new ViewErrorBag(),
        ]);

        $response->assertSee('Story Production Prompt');
        $response->assertSee('HK-2026-MISSING');
        $response->assertSee('سليم');
        $response->assertSee('- Story title: Not available');
        $response->assertSee('- Selected story age range: Not available');
        $response->assertSee('No child images were attached to this order.');
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

    private function productionPromptFromResponse(string $content): string
    {
        preg_match('/<textarea[^>]*id="story-production-prompt"[^>]*>(.*?)<\/textarea>/s', $content, $matches);

        return html_entity_decode($matches[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
