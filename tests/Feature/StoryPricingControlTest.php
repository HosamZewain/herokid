<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\Story;
use App\Models\User;
use App\Services\Pricing\StoryPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class StoryPricingControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_story_offer_controls_every_story_without_changing_product_prices(): void
    {
        $this->setStoryPricing(globalEnabled: true, offerEnabled: true);
        $firstStory = $this->story('offer-story-one', 'قصة العرض الأولى', 120);
        $secondStory = $this->story('offer-story-two', 'قصة العرض الثانية', 275);
        $product = $this->product('unaffected-product', 'منتج بسعر مستقل', 52500);

        $response = $this->get(route('shop.index'))->assertOk();
        $items = collect($response->viewData('items')->items());

        foreach ([$firstStory, $secondStory] as $story) {
            $catalogItem = $items->firstWhere('id', 'story:'.$story->id);

            $this->assertSame(349.0, $catalogItem->price);
            $this->assertSame(399.0, $catalogItem->originalPrice);
            $this->assertSame('عرض خاص', $catalogItem->offerLabel);
        }

        $this->assertSame(525.0, $items->firstWhere('id', 'product:'.$product->id)->price);
        $response->assertSee('عرض خاص');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee(format_money(349));

        $this->get(route('stories.show', $firstStory->slug))
            ->assertOk()
            ->assertSee('"price":"349"', false)
            ->assertSee(format_money(399))
            ->assertSee(format_money(349));
    }

    public function test_offer_can_be_stopped_and_global_pricing_can_be_disabled_later(): void
    {
        $story = $this->story('changeable-price-story', 'قصة بسعر متغير', 275);

        $this->setStoryPricing(globalEnabled: true, offerEnabled: false);
        $pricing = app(StoryPricingService::class);
        $this->assertSame(399.0, $pricing->effectivePrice($story));
        $this->assertFalse($pricing->hasActiveOffer($story));

        $this->setStoryPricing(globalEnabled: false, offerEnabled: false);
        $pricing = app(StoryPricingService::class);
        $this->assertSame(275.0, $pricing->effectivePrice($story));
    }

    public function test_admin_can_save_story_offer_and_offer_must_be_below_regular_price(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'story-pricing-admin@example.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $validSettings = [
            'site_name' => 'HeroKid',
            'site_email' => 'hello@example.test',
            'whatsapp_number' => '201000000000',
            'price_soft_cover' => '299',
            'price_hard_cover' => '399',
            'story_global_price_enabled' => '1',
            'story_regular_price' => '399',
            'story_offer_enabled' => '1',
            'story_offer_price' => '349',
            'story_offer_label' => 'عرض الصيف',
        ];

        $this->actingAs($admin)
            ->put(route('admin.settings.update'), ['settings' => $validSettings])
            ->assertRedirect(route('admin.settings.index'));

        $this->assertDatabaseHas('settings', ['key' => 'story_offer_price', 'value' => '349']);
        $this->assertDatabaseHas('settings', ['key' => 'story_offer_label', 'value' => 'عرض الصيف']);

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => array_merge($validSettings, ['story_offer_price' => '450']),
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors('settings.story_offer_price');
    }

    private function setStoryPricing(bool $globalEnabled, bool $offerEnabled): void
    {
        foreach ([
            'story_global_price_enabled' => $globalEnabled ? '1' : '0',
            'story_regular_price' => '399',
            'story_offer_enabled' => $offerEnabled ? '1' : '0',
            'story_offer_price' => '349',
            'story_offer_label' => 'عرض خاص',
        ] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget('site_settings');
    }

    private function story(string $slug, string $title, int $price): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => $slug,
            'short_desc' => 'وصف القصة',
            'language' => 'ar',
            'gender' => 'both',
            'age_range' => '6-9',
            'price' => $price,
            'active' => true,
        ]);
    }

    private function product(string $slug, string $name, int $priceCents): Product
    {
        $category = ProductCategory::create([
            'name_ar' => 'منتجات مستقلة',
            'slug' => 'independent-products',
            'is_active' => true,
            'show_in_store' => true,
        ]);

        return Product::create([
            'product_category_id' => $category->id,
            'name_ar' => $name,
            'slug' => $slug,
            'price_cents' => $priceCents,
            'is_active' => true,
            'fulfillment_type' => 'physical',
            'purchase_mode' => 'standalone',
            'personalization_mode' => 'none',
            'inventory_mode' => 'no_tracking',
        ]);
    }
}
