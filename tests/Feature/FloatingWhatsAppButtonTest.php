<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FloatingWhatsAppButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_show_a_floating_whatsapp_button_using_the_configured_number(): void
    {
        Setting::updateOrCreate(['key' => 'whatsapp_number'], ['value' => '01501188884']);
        Setting::updateOrCreate(['key' => 'whatsapp_url'], ['value' => '']);
        Cache::forget('site_settings');

        $expectedUrl = 'https://wa.me/201501188884?text='.rawurlencode('مرحباً، أريد الاستفسار عن خدمات HeroKid');

        foreach ([route('home'), route('faq')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('data-floating-whatsapp', false)
                ->assertSee($expectedUrl, false)
                ->assertSee('aria-label="تواصل مع HeroKid عبر واتساب"', false);
        }
    }

    public function test_configured_whatsapp_url_is_preferred_and_keeps_existing_query_parameters(): void
    {
        Setting::updateOrCreate(['key' => 'whatsapp_number'], ['value' => '01501188884']);
        Setting::updateOrCreate(['key' => 'whatsapp_url'], ['value' => 'https://wa.me/209999999999?source=website']);
        Cache::forget('site_settings');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('https://wa.me/209999999999?source=website&amp;text=', false)
            ->assertDontSee('https://wa.me/201501188884', false);
    }
}
