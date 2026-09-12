<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderCustomerReview;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderCustomerRatingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class OrderCustomerRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_rating_page_requires_a_valid_signed_link(): void
    {
        $order = $this->order();

        $this->get(route('order-ratings.show', $order))->assertForbidden();
        $this->post(route('order-ratings.store', $order), [
            'quality_rating' => 5,
        ])->assertForbidden();
    }

    public function test_signed_page_shows_safe_order_details_and_rating_questions(): void
    {
        $order = $this->order();

        $this->get($this->signedUrl($order))
            ->assertOk()
            ->assertSee('مدي رضاك عن جودة المنتج؟')
            ->assertSee('تعليق/اقتراح علي الخدمة')
            ->assertSee('منتج تجريبي')
            ->assertSee($order->checkoutReference->short_reference)
            ->assertSee('ولي الأمر')
            ->assertDontSee('01012345678')
            ->assertDontSee('عنوان سري للاختبار')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_signed_rating_submission_does_not_depend_on_a_browser_session_csrf_token(): void
    {
        $order = $this->order();

        $this->assertContains(
            'order-rating/*',
            app(ValidateCsrfToken::class)->getExcludedPaths(),
        );

        $this->get($this->signedUrl($order))
            ->assertOk()
            ->assertDontSee('name="_token"', false);

        $this->post($this->signedUrl($order), [
            'quality_rating' => 5,
            'customer_comment' => 'تم الإرسال من رابط واتساب بدون جلسة متصفح مسبقة',
        ])->assertRedirect($this->signedUrl($order));

        $this->assertDatabaseCount('order_customer_reviews', 1);
    }

    public function test_customer_can_submit_one_rating_for_the_checkout(): void
    {
        $order = $this->order();

        $this->post($this->signedUrl($order), [
            'quality_rating' => 5,
            'customer_comment' => 'خدمة ممتازة واقتراح واضح',
        ])->assertRedirect($this->signedUrl($order));

        $review = OrderCustomerReview::query()->sole();

        $this->assertSame($order->id, $review->order_id);
        $this->assertSame(OrderCustomerReview::TYPE_SERVICE_RATING, $review->review_type);
        $this->assertSame(OrderCustomerReview::DECISION_SUBMITTED, $review->decision);
        $this->assertSame(OrderCustomerReview::SOURCE_PUBLIC_LINK, $review->source);
        $this->assertSame(5, $review->qualityRating());
        $this->assertSame('خدمة ممتازة واقتراح واضح', $review->customer_comment);

        $this->get($this->signedUrl($order))
            ->assertOk()
            ->assertSee('شكراً لتقييمك')
            ->assertSee('خدمة ممتازة واقتراح واضح')
            ->assertDontSee('data-order-rating-form', false);
    }

    public function test_rating_validation_rejects_invalid_stars_and_long_comments(): void
    {
        $order = $this->order();

        $this->from($this->signedUrl($order))
            ->post($this->signedUrl($order), [
                'quality_rating' => 6,
                'customer_comment' => str_repeat('ا', 2001),
            ])
            ->assertRedirect($this->signedUrl($order))
            ->assertSessionHasErrors(['quality_rating', 'customer_comment']);

        $this->assertDatabaseCount('order_customer_reviews', 0);
    }

    public function test_repeating_submission_does_not_duplicate_or_overwrite_the_rating(): void
    {
        $order = $this->order();
        $url = $this->signedUrl($order);

        $this->post($url, [
            'quality_rating' => 4,
            'customer_comment' => 'التقييم الأول',
        ])->assertRedirect($url);
        $this->post($url, [
            'quality_rating' => 1,
            'customer_comment' => 'محاولة الاستبدال',
        ])->assertRedirect($url);

        $this->assertDatabaseCount('order_customer_reviews', 1);
        $review = OrderCustomerReview::query()->sole();
        $this->assertSame(4, $review->qualityRating());
        $this->assertSame('التقييم الأول', $review->customer_comment);
    }

    public function test_multi_story_checkout_uses_one_rating_and_displays_all_items(): void
    {
        $first = $this->order('GROUP-RATING-MULTI', 'HK-RATING-1', 'قصة الطفل الأول');
        $second = $this->order('GROUP-RATING-MULTI', 'HK-RATING-2', 'قصة الطفل الثاني');
        $url = $this->signedUrl($second);

        $this->get($url)
            ->assertOk()
            ->assertSee('قصة الطفل الأول')
            ->assertSee('قصة الطفل الثاني');

        $this->post($url, [
            'quality_rating' => 3,
            'customer_comment' => 'تقييم شراء متعدد القصص',
        ])->assertRedirect($url);

        $review = OrderCustomerReview::query()->sole();
        $this->assertSame($first->id, $review->order_id);
        $this->assertSame('GROUP-RATING-MULTI', data_get($review->metadata, 'checkout_group_key'));
    }

    public function test_admin_order_page_shows_submitted_rating_at_the_top(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();
        app(OrderCustomerRatingService::class)->submit($order, 5, 'رأي العميل ظاهر في الأعلى');

        $response = $this->actingAs($admin)->get(route('admin.orders.groups.show', $order));

        $response->assertOk()
            ->assertSee('data-order-customer-rating', false)
            ->assertSee('تقييم العميل')
            ->assertSee('رأي العميل ظاهر في الأعلى')
            ->assertSee('5/5');

        $content = $response->getContent();
        $this->assertLessThan(
            strpos($content, 'aria-label="إجراءات الطلب الأساسية"'),
            strpos($content, 'data-order-customer-rating'),
        );
    }

    public function test_orders_index_shows_only_rating_stars_below_the_checkout_id(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();
        app(OrderCustomerRatingService::class)->submit($order, 4, null);

        $response = $this->actingAs($admin)->get(route('admin.orders.index', [
            'catalog_type' => 'products',
        ]));

        $response->assertOk()
            ->assertSee('data-order-list-rating', false)
            ->assertSee('تقييم العميل 4 من 5')
            ->assertDontSee('1 سجل طلب');
    }

    public function test_admin_page_has_a_separate_whatsapp_rating_action_with_a_signed_url(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();
        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);
        $action = app(OrderCustomerRatingService::class)->whatsappActionForGroup($group);

        $this->assertNotNull($action);
        $this->assertStringContainsString('wa.me/201012345678', $action['url']);
        $this->assertStringContainsString(rawurlencode($this->signedUrl($order)), $action['url']);

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('data-order-rating-whatsapp', false)
            ->assertSee('إرسال رابط التقييم');
    }

    private function signedUrl(Order $order): string
    {
        return URL::signedRoute('order-ratings.show', ['order' => $order->id]);
    }

    private function order(
        string $group = 'GROUP-RATING',
        string $number = 'HK-RATING-1',
        string $itemTitle = 'منتج تجريبي',
    ): Order {
        $order = Order::query()->create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'parent_name' => 'ولي الأمر',
            'status' => 'new',
            'payment_status' => 'unpaid',
            'delivery_details' => [
                'checkout_group' => $group,
                'phone' => '01012345678',
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'address' => 'عنوان سري للاختبار',
                'delivery_fee' => 50,
            ],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'title' => $itemTitle,
            'unit_price_cents' => 30_000,
            'quantity' => 1,
            'total_price_cents' => 30_000,
        ]);

        return $order->refresh()->load('checkoutReference');
    }
}
