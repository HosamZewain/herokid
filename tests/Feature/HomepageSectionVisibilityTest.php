<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class HomepageSectionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_child_identity_section_is_visible_and_reviews_are_hidden_by_default(): void
    {
        Testimonial::create([
            'reviewer_name' => 'أم سليم',
            'review_text' => 'تجربة رائعة.',
            'rating' => 5,
            'active' => true,
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-home-section="child_identity"', false)
            ->assertSee(route('child-identity.index'), false)
            ->assertSee('اصنع هوية طفلك قبل اختيار القصة')
            ->assertDontSee('data-home-section="testimonials"', false)
            ->assertDontSee('ماذا يقول الآباء؟');
    }

    public function test_each_homepage_section_can_be_hidden_or_shown_without_deleting_content(): void
    {
        Testimonial::create([
            'reviewer_name' => 'أم ليلى',
            'review_text' => 'احتفظوا بالمحتوى وأظهروا القسم عند الحاجة.',
            'rating' => 5,
            'active' => true,
        ]);

        $this->setSetting('home_section_testimonials_enabled', '1');
        $this->setSetting('home_section_child_identity_enabled', '0');
        $this->setSetting('home_section_benefits_enabled', '0');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-home-section="testimonials"', false)
            ->assertSee('أم ليلى')
            ->assertDontSee('data-home-section="child_identity"', false)
            ->assertDontSee('data-home-section="benefits"', false);

        $this->assertDatabaseHas('testimonials', ['reviewer_name' => 'أم ليلى']);
    }

    public function test_admin_can_manage_section_visibility_and_child_identity_copy_from_site_settings(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('التحكم في أقسام الصفحة الرئيسية')
            ->assertSee('settings[home_section_testimonials_enabled]', false)
            ->assertSee('settings[home_section_child_identity_enabled]', false);

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), [
                'settings' => [
                    'site_name' => 'HeroKid',
                    'site_email' => 'hello@example.com',
                    'whatsapp_number' => '201000000000',
                    'price_soft_cover' => 299,
                    'price_hard_cover' => 399,
                    'home_section_testimonials_enabled' => '1',
                    'home_section_child_identity_enabled' => '0',
                    'home_child_identity_title' => 'هوية طفلك الجديدة',
                    'home_child_identity_subtitle' => 'وصف قابل للتعديل من لوحة الإدارة.',
                    'home_child_identity_cta' => 'ابدأ الآن',
                ],
                'age_ranges' => ['٣ - ٦ سنوات'],
            ])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('settings', [
            'key' => 'home_section_testimonials_enabled',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_section_child_identity_enabled',
            'value' => '0',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'home_child_identity_title',
            'value' => 'هوية طفلك الجديدة',
        ]);
    }

    private function setSetting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('site_settings');
    }
}
