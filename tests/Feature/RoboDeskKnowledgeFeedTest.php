<?php

namespace Tests\Feature;

use App\Models\FaqItem;
use App\Models\PricingPackage;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\Story;
use App\Services\Catalog\CustomerKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoboDeskKnowledgeFeedTest extends TestCase
{
    use RefreshDatabase;

    private function story(int $number = 1, array $extra = []): Story
    {
        return Story::create(array_merge(['title' => 'قصة '.$number, 'slug' => 'story-'.$number, 'price' => 399,
            'active' => true, 'gender' => 'both', 'age_range' => '3-6', 'short_desc' => '<p>رحلة جميلة</p>',
            'lesson_value' => 'التعاون مع الأصدقاء', 'prompt' => 'PRIVATE PROMPT'], $extra));
    }

    private function product(int $number = 1, array $extra = []): Product
    {
        $category = ProductCategory::firstOrCreate(['slug' => 'gifts'], ['name_ar' => 'هدايا', 'is_active' => true, 'show_in_store' => true]);

        return Product::create(array_merge(['name_ar' => 'منتج '.$number, 'slug' => 'product-'.$number,
            'product_category_id' => $category->id, 'price_cents' => 20000, 'is_active' => true,
            'personalization_mode' => 'none', 'short_description_ar' => '<b>هدية جميلة</b>'], $extra));
    }

    private function package(int $number = 1, array $extra = []): PricingPackage
    {
        return PricingPackage::create(array_merge(['name' => 'باقة '.$number, 'slug' => 'package-'.$number, 'price' => 899,
            'regular_price' => 9999, 'story_count' => 3, 'active' => true, 'show_in_store' => true, 'applies_to_all_stories' => true], $extra));
    }

    public function test_full_catalog_paginates_without_duplicates_and_has_only_public_fields(): void
    {
        $this->assertSame('نص آمن 40', CustomerKnowledge::text('&lt;script&gt;private()&lt;/script&gt;&lt;b&gt;نص آمن ٤٠&lt;/b&gt;'));
        foreach (range(1, 83) as $n) {
            $this->story($n);
        }
        foreach (range(1, 26) as $n) {
            $this->product($n);
        }
        foreach (range(1, 7) as $n) {
            $this->package($n);
        }
        $this->story(84, ['active' => false]);
        $this->product(27, ['is_active' => false]);
        $this->package(8, ['show_in_store' => false]);
        $items = collect();
        foreach ([1 => 50, 2 => 50, 3 => 16] as $page => $count) {
            $response = $this->getJson('/api/feeds/robodesk/catalog?page='.$page)->assertOk()->assertJsonCount($count, 'data')->assertJsonPath('total', 116);
            $this->assertSame($page === 3 ? null : 'https://hero-kid.com/api/feeds/robodesk/catalog?page='.($page + 1), $response->json('next_page_url'));
            $items = $items->concat($response->json('data'));
        }
        $this->assertCount(116, $items->pluck('id')->unique());
        $this->assertSame(['package' => 7, 'product' => 26, 'story' => 83], $items->countBy('type')->all());
        foreach ($items as $item) {
            $this->assertSame(['id', 'type', 'sku', 'name', 'description', 'price', 'currency', 'stock', 'url', 'image', 'updated_at', 'rag_text'], array_keys($item));
            $this->assertIsNumeric($item['price']);
            $this->assertContains($item['stock'], ['in_stock', 'out_of_stock']);
            $this->assertLessThanOrEqual(1000, mb_strlen($item['description']));
            $this->assertStringNotContainsString('<', $item['rag_text']);
            $this->assertDoesNotMatchRegularExpression('/تنبيه|تعارض|لا تؤكد|المصدر|تاريخ التحقق|PRIVATE PROMPT/u', json_encode($item, JSON_UNESCAPED_UNICODE));
        }
        foreach ($items->where('type', 'story') as $story) {
            foreach (range(1, 7) as $n) {
                $this->assertStringContainsString('باقة '.$n, $story['rag_text']);
            }
        }
    }

    public function test_price_and_fixed_component_changes_invalidate_all_dependent_pages(): void
    {
        $story = $this->story();
        $product = $this->product();
        $package = $this->package();
        $package->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        $first = collect($this->getJson('/api/feeds/robodesk/catalog')->assertOk()->json('data'));
        $this->assertStringContainsString('بدل 1597 ج.م', $first->firstWhere('type', 'package')['rag_text']);
        $this->assertStringContainsString('توفير 698 ج.م', $first->firstWhere('type', 'package')['rag_text']);
        $story->update(['price' => 450]);
        $product->update(['price_cents' => 25000]);
        $second = collect($this->getJson('/api/feeds/robodesk/catalog')->assertOk()->json('data'));
        $this->assertEquals(450, $second->firstWhere('type', 'story')['price']);
        $this->assertStringContainsString('بدل 1850 ج.م', $second->firstWhere('type', 'package')['rag_text']);
        $this->assertStringContainsString('توفير 951 ج.م', $second->firstWhere('type', 'package')['rag_text']);
        $guide = json_encode($this->getJson('/api/feeds/robodesk/guide')->assertOk()->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('1850 ج.م', $guide);
    }

    public function test_restricted_pool_pivot_changes_gender_stock_variants_and_global_offer(): void
    {
        $girl = $this->story(1, ['hero_gender' => 'girl']);
        $boy = $this->story(2, ['hero_gender' => 'boy']);
        $package = $this->package(1, ['applies_to_all_stories' => false, 'story_count' => 1]);
        $package->eligibleStories()->sync([$girl->id]);
        $product = $this->product(1, ['inventory_mode' => 'track_stock', 'stock_quantity' => 0]);
        $product->variants()->create(['name_ar' => 'كبير', 'is_active' => true, 'price_override_cents' => 25500, 'stock_quantity' => 0]);
        $product->variants()->create(['name_ar' => 'صغير', 'is_active' => true, 'price_override_cents' => 19000, 'stock_quantity' => 0]);
        $items = collect($this->getJson('/api/feeds/robodesk/catalog')->assertOk()->json('data'));
        $this->assertStringContainsString('البطل: بنت', $items->firstWhere('id', 'story:story-1')['rag_text']);
        $this->assertStringNotContainsString('باقة 1', $items->firstWhere('id', 'story:story-2')['rag_text']);
        $this->assertSame('out_of_stock', $items->firstWhere('type', 'product')['stock']);
        $this->assertStringContainsString('كبير بـ 255 ج.م', $items->firstWhere('type', 'product')['rag_text']);
        $package->eligibleStories()->sync([$boy->id]);
        $items = collect($this->getJson('/api/feeds/robodesk/catalog')->assertOk()->json('data'));
        $this->assertStringContainsString('باقة 1', $items->firstWhere('id', 'story:story-2')['rag_text']);
        foreach (['story_global_price_enabled' => '1', 'story_regular_price' => '499', 'story_offer_enabled' => '1', 'story_offer_price' => '399'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        $items = collect($this->getJson('/api/feeds/robodesk/catalog')->assertOk()->json('data'));
        $this->assertStringContainsString('399 ج.م بدل 499 ج.م (عرض خاص)', $items->firstWhere('type', 'story')['rag_text']);
    }

    public function test_approved_corrections_are_backed_up_idempotent_and_never_change_selling_prices(): void
    {
        Storage::fake('local');
        $product = $this->product(1, ['slug' => 'school-sticker', 'price_cents' => 34500, 'description_ar' => 'old']);
        $package = $this->package(1, ['slug' => 'bak-3-kss-mkhss']);
        $faq = FaqItem::create(['question' => 'هل توجد باقة لأكثر من قصة؟', 'answer' => '900 جنيه']);
        $unrelatedFaq = FaqItem::create(['question' => 'ما سعر هذا المثال؟', 'answer' => '900 جنيه']);
        FaqItem::create(['question' => 'هل صور الأطفال في أمان؟', 'answer' => 'حذف بعد 90 يوما']);
        FaqItem::create(['question' => 'ما الفرق بين الغلاف الناعم والغلاف الصلب؟', 'answer' => 'old']);
        $this->artisan('robodesk:prepare-knowledge')->assertSuccessful();
        $this->assertSame('old', $product->fresh()->description_ar);
        $this->artisan('robodesk:prepare-knowledge', ['--apply' => true])->assertSuccessful();
        $this->assertSame(34500, $product->fresh()->price_cents);
        $this->assertStringContainsString('40 استيكر وسط +40', $product->fresh()->description_ar);
        $this->assertStringContainsString('899 ج.م', $faq->fresh()->customer_answer);
        $this->assertSame('900 جنيه', $unrelatedFaq->fresh()->answer);
        $package->update(['price' => 910]);
        $this->assertStringContainsString('910 ج.م', $faq->fresh()->customer_answer);
        $this->assertCount(1, Storage::disk('local')->allFiles('private/robodesk-content'));
        $product->update(['description_ar' => 'New approved content']);
        $this->artisan('robodesk:prepare-knowledge', ['--apply' => true])->assertSuccessful();
        $this->assertSame('New approved content', $product->fresh()->description_ar);
        $this->assertStringContainsString('ديبوزت', (new CustomerKnowledge)->policy('payment'));
        $this->assertStringNotContainsString('90', (new CustomerKnowledge)->policy('privacy'));
        $this->get('/faq')->assertOk()->assertSee('910 ج.م')->assertDontSee('ما الفرق بين الغلاف الناعم');
    }

    public function test_guide_pages_have_stable_ids_customer_fields_and_no_secrets(): void
    {
        $this->story();
        foreach (range(1, 55) as $n) {
            FaqItem::create(['question' => 'سؤال '.$n, 'answer' => 'إجابة '.$n, 'active' => true]);
        }
        Setting::create(['key' => 'openai_api_key', 'value' => 'sk-PRIVATE-SECRET']);
        $first = $this->getJson('/api/feeds/robodesk/guide')->assertOk()->assertJsonCount(50, 'data');
        $second = $this->getJson('/api/feeds/robodesk/guide?page=2')->assertOk()->assertJsonPath('next_page_url', null);
        $items = collect($first->json('data'))->concat($second->json('data'));
        $this->assertCount($items->count(), $items->pluck('id')->unique());
        foreach ($items as $item) {
            foreach (['sku', 'price', 'stock', 'image'] as $key) {
                $this->assertNull($item[$key]);
            }
            $this->assertLessThan(40, count(explode("\n", $item['rag_text'])));
        }
        $this->assertStringNotContainsString('sk-PRIVATE-SECRET', $first->getContent().$second->getContent());
        $this->getJson('/api/feeds/robodesk/catalog?page=0')->assertUnprocessable();
        $this->getJson('/api/feeds/robodesk/internal')->assertNotFound();
        $this->postJson('/api/feeds/robodesk/catalog')->assertStatus(405);
    }

    public function test_page_cache_expires_after_fifteen_minutes_and_is_read_only(): void
    {
        $story = $this->story();
        $this->getJson('/api/feeds/robodesk/catalog')->assertOk()->assertJsonPath('data.0.price', 399);
        // A bulk SQL write deliberately bypasses model invalidation.
        DB::table('stories')->where('id', $story->id)->update(['price' => 410]);
        $this->getJson('/api/feeds/robodesk/catalog')->assertJsonPath('data.0.price', 399);
        $this->travel(16)->minutes();
        $this->getJson('/api/feeds/robodesk/catalog')->assertJsonPath('data.0.price', 410);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_payment_events', 0);
        $this->travelBack();
    }

    public function test_public_feed_throttles_after_thirty_requests(): void
    {
        $this->story();
        for ($n = 0; $n < 30; $n++) {
            $this->getJson('/api/feeds/robodesk/catalog')->assertOk();
        }
        $this->getJson('/api/feeds/robodesk/catalog')->assertStatus(429);
    }

    public function test_product_schema_and_display_preserve_fractional_prices(): void
    {
        $product = $this->product(1, ['price_cents' => 92550, 'short_description_ar' => 'الوصف الحالي', 'seo_description_ar' => 'سعر قديم 900']);
        $this->get(route('shop.product.show', $product))->assertOk()->assertSee('٩٢٥.٥٠')->assertSee('"price":"925.50"', false);
        $this->assertSame('925.5', CustomerKnowledge::money(925.50));
    }

    public function test_cms_edits_are_shared_by_faq_home_how_it_works_and_feed(): void
    {
        Storage::fake('local');
        FaqItem::create(['question' => 'متى يتم الدفع؟', 'answer' => 'old', 'active' => true, 'sort_order' => 0]);
        $this->artisan('robodesk:prepare-knowledge', ['--apply' => true])->assertSuccessful();
        Setting::where('key', 'customer_policy_payment')->first()->update(['value' => 'ديبوزت بعد تأكيد المعاينة على واتساب']);
        $this->get('/faq')->assertOk()->assertSee('ديبوزت بعد تأكيد المعاينة على واتساب')->assertDontSee('{{policy:');
        $this->get('/')->assertOk()->assertSee('ديبوزت بعد تأكيد المعاينة على واتساب')->assertDontSee('{{policy:');
        $this->get('/how-it-works')->assertOk()->assertSee('ديبوزت بعد تأكيد المعاينة على واتساب')->assertDontSee('{{policy:');
        $guide = $this->getJson('/api/feeds/robodesk/guide')->assertOk();
        $text = json_encode($guide->json('data'), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('ديبوزت بعد تأكيد المعاينة على واتساب', $text);
        $this->assertStringNotContainsString('{{photo_', $text);
    }

    public function test_corrected_activity_ages_and_gender_remain_editable_without_a_second_source(): void
    {
        Storage::fake('local');
        $product = $this->product(1, ['slug' => 'maze-book-level-1', 'age_groups' => ['3-6']]);
        $this->artisan('robodesk:prepare-knowledge', ['--apply' => true])->assertSuccessful();
        $this->assertSame(['3-5'], $product->fresh()->age_groups);
        $story = $this->story(1, ['gender' => 'girl']);
        $this->assertSame('girl', $story->hero_gender);
        $story->hero_gender = 'any';
        $story->save();
        $this->assertSame('both', $story->fresh()->gender);
        $this->assertSame('any', $story->fresh()->hero_gender);
        $this->getJson('/api/v1/catalog?age=3-5&type=products')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_hidden_package_components_do_not_leak_into_public_feed(): void
    {
        $this->story();
        $private = $this->product(1, ['is_active' => false, 'name_ar' => 'PRIVATE PRODUCT']);
        $package = $this->package();
        $package->items()->create(['product_id' => $private->id, 'quantity' => 1]);
        $response = $this->getJson('/api/feeds/robodesk/catalog')->assertOk()->assertJsonPath('total', 1);
        $this->assertStringNotContainsString('PRIVATE PRODUCT', $response->getContent());
        $this->assertStringNotContainsString('باقة 1', json_encode($response->json('data'), JSON_UNESCAPED_UNICODE));
    }
}
