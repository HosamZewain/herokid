<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\Story;
use App\Support\Seo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ApprovedHomepageIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_homepage_uses_real_mixed_catalog_links_and_prices(): void
    {
        $story = $this->story();
        $product = $this->product();
        $this->product(['name_ar' => 'منتج مخفي', 'slug' => 'hidden-home-product', 'is_active' => false]);

        $response = $this->get(route('home'))->assertOk()
            ->assertSee('data-homepage-design="workshop"', false)
            ->assertSee('كل طفل عنده عالم.')
            ->assertSee($story->title)->assertSee($product->name_ar)
            ->assertDontSee('منتج مخفي')
            ->assertSee(route('stories.show', $story->slug), false)
            ->assertSee(route('shop.product.show', $product), false);

        $items = $response->viewData('homeCatalogItems');
        $this->assertSame(['product', 'story'], $items->pluck('type')->all());
        $this->assertSame(90.0, $items->first()->price);
        $this->assertSame(120.0, $items->first()->originalPrice);
        $response->assertSee($items->first()->priceLabel)->assertSee($items->first()->originalPriceLabel);
    }

    public function test_expired_product_offer_uses_regular_price_on_homepage(): void
    {
        $this->product(['sale_ends_at' => now()->subMinute()]);
        $item = $this->get(route('home'))->assertOk()->viewData('homeCatalogItems')->first();
        $this->assertSame(120.0, $item->price);
        $this->assertNull($item->originalPrice);
    }

    public function test_disabled_store_and_hidden_sections_do_not_leak_products(): void
    {
        $this->story();
        $this->product();
        $this->setting('shop_enabled', '0');
        $this->get(route('home'))->assertOk()->assertDontSee('منتج الصفحة الرئيسية')
            ->assertDontSee('data-home-section="store"', false)->assertSee('قصة الصفحة الرئيسية');

        $this->setting('home_section_stories_enabled', '0');
        $this->get(route('home'))->assertOk()->assertDontSee('data-home-catalog', false)
            ->assertDontSee('data-home-section="stories"', false);
    }

    public function test_home_copy_is_editable_and_html_is_escaped(): void
    {
        $this->setting('home_workshop_title_1', 'عالم الطفل الجديد');
        $this->setting('home_workshop_title_2', '<script>notExecutable()</script>');
        $this->get(route('home'))->assertOk()->assertSee('عالم الطفل الجديد')
            ->assertSee('&lt;script&gt;notExecutable()&lt;/script&gt;', false)
            ->assertDontSee('<script>notExecutable()</script>', false);
    }

    public function test_local_preview_images_use_local_http_without_weakening_production_https(): void
    {
        config(['app.url' => 'http://localhost:8088']);
        $this->app['env'] = 'local';
        $this->assertSame('http://localhost:8088/images/sample.webp', Seo::imageUrl('/images/sample.webp'));
        $this->app['env'] = 'production';
        $this->assertSame('https://localhost:8088/images/sample.webp', Seo::imageUrl('/images/sample.webp'));
    }

    #[DataProvider('publicPages')]
    public function test_public_page_families_share_navigation_guide_footer_and_theme(string $route): void
    {
        $html = $this->get(route($route))->assertOk()->getContent();
        $this->assertStringContainsString('hk-site-header', $html);
        $this->assertStringContainsString('hk-site-footer', $html);
        $this->assertStringContainsString('id="hk-footer-discover"', $html);
        $this->assertStringContainsString('id="hk-footer-guide"', $html);
        $this->assertStringContainsString('aria-label="السياسات"', $html);
        $this->assertStringContainsString('herokid-front', $html);
        $this->assertStringContainsString('front-theme-', $html);
        $this->assertStringNotContainsString('fonts.bunny.net', $html);
        foreach (['data-front-guide-menu', 'data-front-guide-menu-mobile'] as $menu) {
            $this->assertMatchesRegularExpression('/<details '.$menu.'\b[^>]*>.*?عن HeroKid.*?كيف يعمل؟.*?الأسئلة الشائعة.*?تتبع الطلب.*?<\/details>/s', $html);
        }
        if ($route !== 'home') {
            $this->assertStringNotContainsString('/build/assets/homepage-', $html, 'Homepage art/styles must not bloat purchase pages.');
        }
    }

    public static function publicPages(): array
    {
        return array_map(fn ($route) => [$route], [
            'home', 'stories.index', 'shop.index', 'football-stories.index', 'packages',
            'about', 'how-it-works', 'faq', 'contact', 'privacy', 'terms', 'track.index', 'cart.index',
        ]);
    }

    public function test_product_and_story_purchase_forms_retain_actions_and_csrf(): void
    {
        $story = $this->story();
        $product = $this->product();
        $this->get(route('stories.show', $story->slug))->assertOk()
            ->assertSee(route('cart.store', $story->slug), false)->assertSee('name="_token"', false)
            ->assertSee('name="child_name"', false)->assertSee('name="language"', false);
        $this->get(route('shop.product.show', $product))->assertOk()
            ->assertSee(route('cart.products.store', $product), false)->assertSee('name="_token"', false);
    }

    public function test_footer_preserves_catalog_help_policy_and_configured_contact_links(): void
    {
        $this->setting('site_email', 'support+family@example.test');
        $this->setting('whatsapp_number', '+201000000000');
        $this->setting('instagram_url', 'https://www.instagram.com/herokid/?a=1&b=2');
        $this->setting('footer_brand_description', 'عالم كل طفل — وصف محفوظ');

        $html = $this->get(route('home'))->assertOk()->getContent();
        preg_match('/<footer class="hk-site-footer">.*?<\/footer>/s', $html, $matches);
        $footer = $matches[0];
        foreach (['stories.index', 'packages', 'shop.index', 'child-identity.index',
            'about', 'how-it-works', 'faq', 'track.index', 'contact', 'privacy', 'terms'] as $route) {
            $this->assertStringContainsString('href="'.route($route).'"', $footer);
        }
        $this->assertStringContainsString('href="'.route('shop.index', ['type' => 'products']).'"', $footer);
        $this->assertStringContainsString('href="'.route('shop.index', ['type' => 'activities']).'"', $footer);
        $this->assertStringContainsString('href="mailto:support+family@example.test"', $footer);
        $this->assertStringContainsString('<bdi dir="ltr">+201000000000</bdi>', $footer);
        $this->assertStringContainsString('href="https://www.instagram.com/herokid/?a=1&amp;b=2"', $footer);
        $this->assertStringContainsString('aria-label="HeroKid — Instagram"', $footer);
        $this->assertStringContainsString('rel="noopener noreferrer"', $footer);
        $this->assertStringContainsString('عالم كل طفل — وصف محفوظ', $footer);
    }

    public function test_footer_respects_disabled_identity_and_empty_contact_settings(): void
    {
        $this->setting('child_identity_enabled', '0');
        foreach (['site_email', 'whatsapp_number', 'facebook_url', 'instagram_url', 'youtube_url', 'whatsapp_url'] as $key) {
            $this->setting($key, '');
        }
        $html = $this->get(route('home'))->assertOk()->getContent();
        preg_match('/<footer class="hk-site-footer">.*?<\/footer>/s', $html, $matches);
        $this->assertStringNotContainsString(route('child-identity.index'), $matches[0]);
        $this->assertStringNotContainsString('href="mailto:', $matches[0]);
        $this->assertStringNotContainsString('href="tel:', $matches[0]);
        $this->assertStringNotContainsString('target="_blank"', $matches[0]);
        $this->assertStringContainsString(route('contact'), $matches[0]);
        $this->assertStringNotContainsString('class="hk-footer-social"', $matches[0]);
    }

    public function test_social_links_are_accessible_icons_below_the_footer_logo(): void
    {
        foreach (['facebook', 'instagram', 'youtube', 'whatsapp'] as $platform) {
            $this->setting($platform.'_url', 'https://example.test/'.$platform);
        }
        $html = $this->get(route('home'))->assertOk()->getContent();
        preg_match('/<div class="hk-footer-brand-media">.*?<\/div>\s*<\/div>/s', $html, $matches);
        $this->assertStringContainsString('hk-footer-logo', $matches[0]);
        $this->assertStringContainsString('hk-footer-social', $matches[0]);
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp'] as $platform => $label) {
            $this->assertStringContainsString('href="https://example.test/'.$platform.'"', $matches[0]);
            $this->assertStringContainsString('aria-label="HeroKid — '.$label.'"', $matches[0]);
            $this->assertStringContainsString(asset('images/icons/'.$platform.'-white.svg'), $matches[0]);
            $this->assertFileExists(public_path('images/icons/'.$platform.'-white.svg'));
        }
        $this->assertStringContainsString('alt="" aria-hidden="true"', $matches[0]);
        $this->assertStringNotContainsString('<span dir="ltr">Facebook</span>', $matches[0]);
    }

    public function test_home_link_is_present_once_in_each_menu_and_active_only_on_home(): void
    {
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/<a href="'.preg_quote(route('home'), '/').'"[^>]*aria-current="page"[^>]*>الرئيسية<\/a>/', $html));
        $html = $this->get(route('stories.index'))->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/<a href="'.preg_quote(route('home'), '/').'"[^>]*>الرئيسية<\/a>/', $html));
        $this->assertSame(0, preg_match_all('/<a href="'.preg_quote(route('home'), '/').'"[^>]*aria-current="page"[^>]*>الرئيسية<\/a>/', $html));
    }

    private function story(): Story
    {
        return Story::create(['title' => 'قصة الصفحة الرئيسية', 'slug' => 'homepage-integration-story',
            'language' => 'ar', 'gender' => 'both', 'price' => 149, 'active' => true]);
    }

    private function product(array $attributes = []): Product
    {
        $category = ProductCategory::firstOrCreate(['slug' => 'homepage-integration'],
            ['name_ar' => 'منتجات', 'is_active' => true, 'show_in_store' => true]);

        return Product::create(array_merge(['product_category_id' => $category->id,
            'name_ar' => 'منتج الصفحة الرئيسية', 'slug' => 'homepage-integration-product',
            'price_cents' => 12000, 'sale_price_cents' => 9000, 'is_active' => true,
            'purchase_mode' => 'standalone', 'personalization_mode' => 'none'], $attributes));
    }

    private function setting(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('site_settings');
    }
}
