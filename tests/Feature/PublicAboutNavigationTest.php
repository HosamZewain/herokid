<?php

namespace Tests\Feature;

use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAboutNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_page_is_public_customer_focused_and_canonical(): void
    {
        $this->get(route('about'))
            ->assertOk()
            ->assertSee('عن HeroKid')
            ->assertSee('ماذا نقدّم؟')
            ->assertSee('رحلة الطلب')
            ->assertSee('الخصوصية وحماية الصور')
            ->assertSee('<link rel="canonical" href="'.Seo::url('/about').'">', false)
            ->assertSee('<meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">', false)
            ->assertSee('<meta property="og:image" content="'.Seo::imageUrl('/images/logo.jpg').'">', false)
            ->assertSee('<meta property="og:image:width" content="1024">', false)
            ->assertSee('<meta property="og:image:height" content="1024">', false)
            ->assertSee('<meta property="og:image:alt" content="HeroKid — قصص مخصصة تجعل طفلك بطل الحكاية">', false)
            ->assertSee('"@type":"AboutPage"', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('aria-label="مسار التنقل"', false);

        $this->assertFileExists(public_path('images/logo.jpg'));
    }

    public function test_about_page_is_publicly_cacheable_for_search_crawlers(): void
    {
        $response = $this->get(route('about'))->assertOk();

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
    }

    public function test_public_navigation_groups_customer_help_pages_under_one_guide_menu(): void
    {
        $response = $this->get(route('home'))
            ->assertOk()
            ->assertSee('دليل HeroKid')
            ->assertSee('data-front-guide-menu', false)
            ->assertSee('data-front-guide-menu-mobile', false);

        $html = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<details data-front-guide-menu\b.*?'.preg_quote(route('about'), '/').'.*?'.preg_quote(route('how-it-works'), '/').'.*?'.preg_quote(route('faq'), '/').'.*?'.preg_quote(route('track.index'), '/').'.*?<\/details>/s',
            $html
        );
    }

    public function test_about_page_is_in_the_public_sitemap(): void
    {
        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee('<loc>'.Seo::url('/about').'</loc>', false);
    }
}
