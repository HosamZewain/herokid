<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesTurn;
use App\Models\Setting;
use App\Models\StoryCategory;
use App\Services\SalesAssistant\SalesBudget;
use App\Services\SalesAssistant\SalesCapabilities;
use App\Services\SalesAssistant\SalesCatalog;
use App\Services\SalesAssistant\SalesCatalogContext;
use App\Services\SalesAssistant\SalesProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\SalesAssistantFixtures;
use Tests\TestCase;

class SalesAssistantSemanticCatalogTest extends TestCase
{
    use RefreshDatabase, SalesAssistantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_model_can_select_a_meaningful_story_without_any_literal_query_overlap_or_top_twelve_cutoff(): void
    {
        $this->salesEnabled();
        foreach (range(1, 18) as $n) {
            $this->salesStory('other-'.$n, 'عنوان منشور '.$n, 'التعاون', '6-9')->update(['gender' => 'girl']);
        }
        $adventure = $this->salesStory('secret-river', 'سر القارب الأزرق', 'الخيال والشجاعة والاستكشاف', '6-9');
        $adventure->update(['gender' => 'girl', 'short_desc' => 'بطلة تعبر نهرًا إلى جزيرة مجهولة وتكتشف سرها.']);
        $category = StoryCategory::create(['name' => 'مغامرات', 'slug' => 'adventures']);
        $adventure->categories()->attach($category);
        $boy = $this->salesStory('boy', 'السر للأولاد', 'مغامرات', '6-9');
        $boy->update(['gender' => 'boy']);
        $inactive = $this->salesStory('unpublished', 'غير منشور', 'مغامرات');
        $inactive->update(['active' => false]);
        $c = $this->salesConversation();
        SalesTurn::create(['id' => (string) Str::uuid(), 'conversation_id' => $c->id, 'client_nonce' => (string) Str::uuid(), 'status' => 'completed', 'request_text' => 'بنتي 6 سنين', 'budget_day' => now()->toDateString(), 'capability_nonce' => Str::random(64), 'created_at' => now()->subMinute()]);
        $turn = app(SalesBudget::class)->reserve($c, 'خليها تسرح بخيالها بعيد عن الروتين', (string) Str::uuid());
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$adventure->id, 'reason' => 'رحلة اكتشاف وخيال.']], 'أنا مساعد HeroKid الذكي. دي رحلة خيالية مناسبة.'))]);
        $answer = app(SalesProvider::class)->answer($turn, []);
        Http::assertSent(function ($request) use ($adventure, $boy, $inactive) {
            $input = $request->data()['input'][0]['content'];
            $data = json_decode($input, true);
            $keys = array_column($data['catalog_candidates'], 'key');
            $this->assertCount(20, $keys);
            $this->assertContains('story:'.$adventure->id, $keys);
            $this->assertContains('story:'.$boy->id, $keys);
            $this->assertNotContains('story:'.$inactive->id, $keys);
            $this->assertTrue($data['catalog_scope']['complete']);
            $this->assertLessThanOrEqual(32000, strlen($input));
            $this->assertSame('gpt-6-luna', $request->data()['model']);
            $this->assertFalse($data['first_reply']);

            return true;
        });
        $this->assertSame('story:'.$adventure->id, $answer['result']['recommendations'][0]['key']);
        $this->assertStringNotContainsString('أنا مساعد', $answer['result']['message']);
        Http::assertSentCount(1);
    }

    public function test_mcp_browses_semantically_relevant_inventory_even_for_unseen_words_and_pages_without_loss(): void
    {
        $this->salesEnabled();
        foreach (range(1, 36) as $n) {
            $this->salesStory('story-'.$n, 'قصة '.$n, str_repeat('تنمية الخيال والشجاعة ', 8));
        }
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'مش عايز مطابقة كلمات', (string) Str::uuid());
        $turn->update(['status' => 'processing']);
        $cap = app(SalesCapabilities::class);
        $resolved = $cap->resolve($cap->issue($turn, []));
        $cursor = 0;
        $seen = [];
        do {
            $page = $cap->tool($resolved, 'search_catalog', ['query' => 'تعبير جديد تمامًا', 'cursor' => $cursor, 'kind' => 'story']);
            $this->assertLessThanOrEqual(12000, strlen(json_encode($page)));
            $this->assertSame(36, $page['total_eligible']);
            $seen = array_merge($seen, array_column($page['items'], 'key'));
            $cursor = $page['next_cursor'];
        } while ($cursor !== null && $turn->fresh()->tool_calls < 4);
        $this->assertNotEmpty($seen);
        $this->assertCount(count($seen), array_unique($seen));
        $this->assertContains('story:'.app(SalesCatalogContext::class)->eligible([])[0]['id'], $seen);
        $this->assertTrue($page['complete'] || $page['next_cursor'] !== null);
    }

    public function test_oversize_catalog_never_claims_a_truncated_page_is_the_whole_store(): void
    {
        foreach (range(1, 50) as $n) {
            $this->salesStory('story-'.$n, str_repeat('عنوان طويل ', 10).$n);
        }
        $context = app(SalesCatalogContext::class);
        $first = $context->overview([], 2500);
        $this->assertFalse($first['complete']);
        $this->assertSame(50, $first['total_eligible']);
        $this->assertGreaterThan(0, $first['next_cursor']);
        $this->assertLessThanOrEqual(2500, $context->bytes($first));
        $second = $context->overview([], 2500, $first['next_cursor']);
        $this->assertSame([], array_intersect(array_column($first['items'], 'key'), array_column($second['items'], 'key')));
    }

    public function test_popularity_uses_the_existing_public_recorded_order_ranking_and_exposes_no_order_details(): void
    {
        $this->salesEnabled();
        $first = $this->salesStory('first', 'حكاية القمر');
        $second = $this->salesStory('second', 'حكاية النجمة');
        $boy = $this->salesStory('boy', 'للأولاد');
        $boy->update(['gender' => 'boy']);
        foreach ([$first, $first, $second, $boy, $boy, $boy] as $story) {
            Order::withoutEvents(fn () => Order::create(['order_number' => 'SYN-'.Str::uuid(), 'story_id' => $story->id, 'child_name' => 'Synthetic private customer', 'child_age' => 6, 'child_gender' => 'girl', 'status' => 'new_confirmed', 'payment_status' => 'unpaid']));
        }
        $ranking = app(SalesCatalogContext::class)->popularStories(['age' => 6, 'gender' => 'girl']);
        $this->assertSame(['story:'.$boy->id, 'story:'.$first->id, 'story:'.$second->id], $ranking);
        $this->assertStringNotContainsString('Synthetic private customer', json_encode($ranking));
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'الأكثر طلبًا', (string) Str::uuid());
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$first->id, 'reason' => 'من الأكثر طلبًا.']], basis: 'popularity'))]);
        $answer = app(SalesProvider::class)->answer($turn, []);
        Http::assertSent(fn ($request) => count(json_decode($request->data()['input'][0]['content'], true)['popular_stories']) === 3);
        $this->assertSame('story:'.$first->id, $answer['result']['recommendations'][0]['key']);
    }

    public function test_current_public_offer_is_grounded_in_native_pricing_and_private_codes_never_reach_model(): void
    {
        $this->salesEnabled();
        $story = $this->salesStory();
        foreach (['story_global_price_enabled' => '1', 'story_regular_price' => '399', 'story_offer_enabled' => '1', 'story_offer_price' => '299', 'story_offer_label' => 'عرض القراءة'] as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        DB::table('mobile_promo_codes')->insert(['code' => 'PRIVATE-DO-NOT-DISCLOSE', 'discount_type' => 'percent', 'discount_value' => 1000, 'is_active' => 1, 'website_enabled' => 1, 'mobile_enabled' => 0, 'used_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'هل في خصومات؟', (string) Str::uuid());
        Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$story->id, 'reason' => 'عرض آلي على القصة.']], 'في عرض ظاهر في البطاقة.', basis: 'offer'))]);
        $answer = app(SalesProvider::class)->answer($turn, []);
        Http::assertSent(function ($request) use ($story) {
            $input = $request->data()['input'][0]['content'];
            $data = json_decode($input, true);
            $this->assertSame(['story:'.$story->id], $data['public_offer_keys']);
            $this->assertStringNotContainsString('PRIVATE-DO-NOT-DISCLOSE', $input);

            return true;
        });
        $card = app(SalesCatalog::class)->cards($answer['result']['recommendations'])[0];
        $this->assertSame(29900, $card['price_cents']);
        $this->assertSame(39900, $card['regular_price_cents']);
        $this->assertTrue($card['offer_applied']);
        $this->assertSame(0, DB::table('website_promo_code_redemptions')->count());
        $turn->update(['status' => 'processing']);
        $cap = app(SalesCapabilities::class);
        $policies = $cap->tool($cap->resolve($cap->issue($turn, [])), 'get_store_policies', ['topic' => 'offers']);
        $this->assertSame(['story:'.$story->id], $policies['public_offer_keys']);
        $this->assertStringNotContainsString('PRIVATE-DO-NOT-DISCLOSE', json_encode($policies));

    }

    public function test_scheduled_expired_and_variant_override_prices_are_not_advertised_as_active_offers(): void
    {
        $category = ProductCategory::create(['name_ar' => 'أنشطة', 'slug' => 'activities', 'is_active' => true, 'show_in_store' => true]);
        $product = Product::create(['product_category_id' => $category->id, 'name_ar' => 'نشاط تجريبي', 'slug' => 'activity',
            'price_cents' => 20000, 'sale_price_cents' => 15000, 'sale_starts_at' => now()->addHour(), 'sale_ends_at' => now()->addDay(),
            'is_active' => true, 'inventory_mode' => 'no_tracking', 'purchase_mode' => 'standalone', 'personalization_mode' => 'none']);
        $context = app(SalesCatalogContext::class);
        $this->assertSame([], $context->offers([]));
        $product->update(['sale_starts_at' => now()->subHour()]);
        Cache::forget(SalesCatalog::CACHE_KEY);
        $this->assertSame(['product:'.$product->id], $context->offers([]));
        $product->update(['sale_ends_at' => now()->subMinute()]);
        Cache::forget(SalesCatalog::CACHE_KEY);
        $this->assertSame([], $context->offers([]));
        $product->update(['sale_ends_at' => now()->addHour()]);
        $product->variants()->create(['name_ar' => 'سعر مستقل', 'price_override_cents' => 25000, 'is_active' => true]);
        Cache::forget(SalesCatalog::CACHE_KEY);
        $this->assertSame([], $context->offers([]));
    }

    public function test_model_cannot_label_an_unsold_or_undiscounted_item_as_a_verified_offer_or_bestseller(): void
    {
        $this->salesEnabled();
        $story = $this->salesStory();
        foreach (['offer', 'popularity'] as $basis) {
            $turn = app(SalesBudget::class)->reserve($this->salesConversation(), 'رشحلي', (string) Str::uuid());
            Http::fake(['*' => Http::response($this->salesReply([['key' => 'story:'.$story->id, 'reason' => 'ادعاء غير مثبت']], basis: $basis))]);
            $answer = app(SalesProvider::class)->answer($turn, []);
            $this->assertSame([], $answer['result']['recommendations']);
        }
    }
}
