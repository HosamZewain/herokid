<?php

namespace Tests\Feature;

use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AppDownloadPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_page_has_both_real_store_links_screenshots_and_safe_public_caching(): void
    {
        $response = $this->get('/app')->assertOk()
            ->assertSee('عالم طفلك.')
            ->assertSee('data-app-download', false)
            ->assertSee('data-store-link="ios"', false)
            ->assertSee('data-store-link="android"', false)
            ->assertSee(config('mobile_app.ios_url'), false)
            ->assertSee(e(config('mobile_app.android_url')), false)
            ->assertSee('images/app/home.webp', false)
            ->assertSee('images/app/catalog.webp', false)
            ->assertSee('images/app/account.webp', false)
            ->assertSee('loading="lazy"', false)
            ->assertSee('<link rel="canonical" href="'.Seo::url('/app').'">', false)
            ->assertDontSee('http-equiv="refresh"', false);
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Cookie', $response->headers->get('Vary'));
        $this->assertSame('app.download', app('router')->getRoutes()->match(Request::create('/app'))->getName());
    }

    public function test_mobile_user_agents_receive_the_same_cache_safe_html_and_no_redirect(): void
    {
        foreach (['iPhone', 'Android', 'Macintosh'] as $userAgent) {
            $this->withHeader('User-Agent', $userAgent)->get('/app')->assertOk()
                ->assertSee(config('mobile_app.ios_url'), false)
                ->assertSee(e(config('mobile_app.android_url')), false);
        }
    }

    public function test_app_is_discoverable_and_assets_are_small_local_webp_files(): void
    {
        $this->get(route('about'))->assertOk()->assertSee(route('app.download'), false)->assertSee('حمّل تطبيق HeroKid');
        $this->get(route('sitemap'))->assertOk()->assertSee('<loc>'.Seo::url('/app').'</loc>', false);
        foreach (['home', 'catalog', 'account'] as $screen) {
            $path = public_path('images/app/'.$screen.'.webp');
            $this->assertFileExists($path);
            $this->assertLessThan(180000, filesize($path));
            $this->assertSame('image/webp', getimagesize($path)['mime']);
        }
    }
}
