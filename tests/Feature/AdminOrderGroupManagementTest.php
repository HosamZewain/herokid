<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderPaymentEvent;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\OrderDateTime;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderGroupManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_orders_index_groups_a_multi_story_checkout_and_exposes_all_contents_once(): void
    {
        [$first, $second] = $this->checkoutFixture();

        $response = $this->actingAs($this->admin)->get(route('admin.orders.index'));

        $response->assertOk()
            ->assertSee('w-full max-w-none space-y-5', false)
            ->assertSee('data-order-primary-cell', false)
            ->assertSee('data-order-row-actions', false)
            ->assertDontSee('>إجراءات</th>', false)
            ->assertSee('GROUP-MULTI')
            ->assertSee(route('admin.orders.groups.show', $first), false)
            ->assertSee($first->order_number)
            ->assertSee($second->order_number)
            ->assertSee('رنا')
            ->assertSee('آدم')
            ->assertSee('ملصق باسم الطفل')
            ->assertSee('كتاب تلوين مباشر')
            ->assertSee('حالات متعددة');

        $groups = $response->viewData('groups');
        $stats = $response->viewData('stats');
        $group = $groups->items()[0];

        $this->assertSame(1, $groups->total());
        $this->assertSame(2, $group['story_count']);
        $this->assertSame(1, $group['add_on_quantity']);
        $this->assertSame(2, $group['product_quantity']);
        $this->assertSame(93_800, $group['total_cents']);
        $this->assertSame(4_000, $group['delivery_cents']);
        $this->assertSame(['رنا', 'آدم'], $group['child_names']);
        $this->assertSame(1, $stats['checkouts']);
        $this->assertSame(2, $stats['stories']);
        $this->assertSame(3, $stats['products']);
        $this->assertSame(93_800, $stats['total_value_cents']);
        $this->assertSame(93_800, $stats['average_order_cents']);
        $this->assertSame(0, $stats['collected_cents']);
        $this->assertSame(0, $stats['payment_checkouts']);
        $this->assertSame(0, $stats['cancelled_checkouts']);
        $this->assertSame(0, $stats['paid_checkouts']);
        $this->assertSame(0, $stats['shipped_checkouts']);
    }

    public function test_dashboard_counts_and_lists_a_multi_story_checkout_once(): void
    {
        $first = $this->createStoryOrder('HK-DASH-1', 'GROUP-DASHBOARD', 'ليلى', 'new');
        $second = $this->createStoryOrder('HK-DASH-2', 'GROUP-DASHBOARD', 'عمر', 'new');

        foreach ([$first, $second] as $order) {
            $order->items()->create([
                'item_type' => 'story',
                'story_id' => $order->story_id,
                'title' => $order->story->title,
                'unit_price_cents' => 29_900,
                'quantity' => 1,
                'total_price_cents' => 29_900,
            ]);
            $order->forceFill([
                'payment_status' => 'partially_paid',
                'paid_amount_cents' => 10_000,
                'payment_updated_at' => now()->subDay(),
            ])->save();
        }

        $this->recordPaymentEvent($first, 0, 10_000);

        // A legacy general activity log must never be interpreted as a payment.
        AdminActivityLog::create([
            'user_id' => $this->admin->id,
            'action' => 'checkout.full_order_updated',
            'subject_type' => Order::class,
            'subject_id' => $first->id,
            'properties' => [
                'before' => ['paid_amount_cents' => 0],
                'after' => ['paid_amount_cents' => 44_000],
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'));

        $response->assertOk()
            ->assertSee('عمليات شراء جديدة تنتظر المراجعة')
            ->assertSee('طلبات جديدة اليوم')
            ->assertSee('مدفوعات اليوم')
            ->assertSee('تفاصيل حركات الدفع اليوم')
            ->assertSee('واقعة دفع فعلية محفوظة وغير قابلة للتعديل')
            ->assertSee('انستاباي')
            ->assertSee($this->admin->name)
            ->assertSee('قيمة الطلبات النشطة')
            ->assertSee('تتضمن 2 سجل طلب')
            ->assertSee('GROUP-DASHBOARD')
            ->assertSee('2 قصة');

        $this->assertSame(1, $response->viewData('newOrders'));
        $this->assertSame(1, $response->viewData('totalOrders'));
        $this->assertSame(2, $response->viewData('orderRecordCounts')['new']);
        $this->assertSame(1, $response->viewData('todayStats')['new_checkouts']);
        $this->assertSame(0, $response->viewData('todayStats')['yesterday_checkouts']);
        $this->assertSame(1, $response->viewData('todayStats')['new_checkouts_difference']);
        $this->assertSame(63_800, $response->viewData('todayStats')['order_value_cents']);
        $this->assertSame(63_800, $response->viewData('todayStats')['average_order_cents']);
        $this->assertSame(1, $response->viewData('todayStats')['payment_checkouts']);
        $this->assertSame(10_000, $response->viewData('todayStats')['payments_cents']);
        $this->assertCount(1, $response->viewData('todayStats')['payment_events']);
        $this->assertSame(10_000, $response->viewData('todayStats')['payment_events'][0]['amount_delta_cents']);
        $this->assertSame(1, $response->viewData('operationsStats')['active_checkouts']);
        $this->assertSame(1, $response->viewData('operationsStats')['unassigned_checkouts']);
        $this->assertSame(10_000, $response->viewData('operationsStats')['collected_cents']);
        $this->assertSame(53_800, $response->viewData('operationsStats')['outstanding_cents']);
        $this->assertCount(1, $response->viewData('recentOrders'));
        $this->assertSame(2, $response->viewData('recentOrders')->first()['story_count']);

        $groups = $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => 'new']))
            ->assertOk()
            ->viewData('groups');

        $this->assertSame(1, $groups->total());
    }

    public function test_dashboard_uses_actual_payment_deltas_and_compares_new_orders_with_yesterday(): void
    {
        $order = $this->createStoryOrder('HK-DASH-OLD', 'GROUP-DASHBOARD-OLD', 'سلمى', 'under_review');
        $order->items()->create([
            'item_type' => 'story',
            'story_id' => $order->story_id,
            'title' => $order->story->title,
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);
        $order->forceFill([
            'created_at' => now()->subDay(),
            'payment_status' => 'partially_paid',
            'paid_amount_cents' => 20_000,
            'payment_updated_at' => now(),
        ])->save();

        $withoutPaymentLog = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->viewData('todayStats');

        $this->assertSame(0, $withoutPaymentLog['new_checkouts']);
        $this->assertSame(1, $withoutPaymentLog['yesterday_checkouts']);
        $this->assertSame(-1, $withoutPaymentLog['new_checkouts_difference']);
        $this->assertSame(0, $withoutPaymentLog['payment_checkouts']);
        $this->assertSame(0, $withoutPaymentLog['payments_cents']);
        $this->assertSame(0, $withoutPaymentLog['average_order_cents']);

        $this->recordPaymentEvent($order, 13_000, 20_000);

        $withPaymentLog = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->viewData('todayStats');

        $this->assertSame(1, $withPaymentLog['payment_checkouts']);
        $this->assertSame(7_000, $withPaymentLog['payments_cents']);
    }

    public function test_dashboard_assigns_payment_events_to_the_correct_cairo_calendar_day(): void
    {
        $cairoNow = CarbonImmutable::parse('2026-08-31 00:30:00', 'Africa/Cairo');
        $this->travelTo($cairoNow->utc());

        $order = $this->createStoryOrder('HK-CAIRO-PAYMENT', 'GROUP-CAIRO-PAYMENT', 'نور', 'under_review');
        $order->items()->create([
            'item_type' => 'story',
            'story_id' => $order->story_id,
            'title' => $order->story->title,
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $this->recordPaymentEvent($order, 0, 12_000);

        OrderPaymentEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'checkout_group_key' => $order->checkoutGroupKey(),
            'order_id' => $order->id,
            'actor_user_id' => $this->admin->id,
            'event_type' => 'payment_received',
            'source' => 'admin_payment_update',
            'previous_status' => 'unpaid',
            'new_status' => 'partially_paid',
            'previous_paid_amount_cents' => 0,
            'new_paid_amount_cents' => 5_000,
            'amount_delta_cents' => 5_000,
            'affects_collection_stats' => true,
            'payment_method' => 'نقدي',
            'occurred_at' => $cairoNow->subMinutes(31)->utc(),
        ]);

        $todayStats = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->viewData('todayStats');

        $this->assertSame(1, $todayStats['payment_checkouts']);
        $this->assertSame(12_000, $todayStats['payments_cents']);
        $this->assertCount(1, $todayStats['payment_events']);
        $this->assertSame('31/08/2026 12:30 AM', $todayStats['payment_events'][0]['occurred_at_label']);
    }

    public function test_dashboard_last_seven_days_separates_story_and_product_checkouts_with_daily_values(): void
    {
        $now = CarbonImmutable::parse('2026-08-31 12:00:00', 'Africa/Cairo')->utc();
        $this->travelTo($now);

        $firstStory = $this->createStoryOrder('HK-WEEK-STORY-1', 'GROUP-WEEK-STORY', 'مريم', 'new');
        $secondStory = $this->createStoryOrder('HK-WEEK-STORY-2', 'GROUP-WEEK-STORY', 'مريم', 'new');
        foreach ([[$firstStory, 30_000], [$secondStory, 20_000]] as [$order, $price]) {
            $order->items()->create([
                'item_type' => 'story',
                'story_id' => $order->story_id,
                'title' => $order->story->title,
                'unit_price_cents' => $price,
                'quantity' => 1,
                'total_price_cents' => $price,
            ]);
            $order->forceFill(['created_at' => now()])->save();
        }

        $product = Product::create([
            'name_ar' => 'كتاب نشاط',
            'slug' => 'weekly-product',
            'price_cents' => 12_000,
            'inventory_mode' => 'no_tracking',
            'is_active' => true,
        ]);
        $productOrder = Order::create([
            'order_number' => 'HK-WEEK-PRODUCT',
            'checkout_group_key' => 'GROUP-WEEK-PRODUCT',
            'parent_name' => 'عميل المتجر',
            'delivery_details' => [
                'checkout_group' => 'GROUP-WEEK-PRODUCT',
                'phone' => '01000000001',
                'delivery_fee' => 30,
            ],
            'status' => 'cancelled',
        ]);
        $productOrder->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 12_000,
            'quantity' => 2,
            'total_price_cents' => 24_000,
        ]);
        $yesterday = now()->subDay();
        $productOrder->forceFill(['created_at' => $yesterday])->save();
        $productOrder->statusLogs()->create([
            'status_type' => 'order',
            'status' => 'cancelled',
            'notes' => 'إلغاء للاختبار',
            'created_at' => $yesterday,
            'updated_at' => $yesterday,
        ]);

        $this->recordPaymentEvent($firstStory, 3_000, 10_000);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->assertSee('آخر 7 أيام')
            ->assertSee('إجمالي قيمة الطلبات')
            ->assertSee('مدفوع اليوم');

        $days = collect($response->viewData('lastSevenDaysStats'))->keyBy('date');
        $this->assertCount(7, $days);

        $today = $days->get('2026-08-31');
        $this->assertSame(1, $today['new_checkouts']);
        $this->assertSame(1, $today['story_checkouts']);
        $this->assertSame(0, $today['product_checkouts']);
        $this->assertSame(54_000, $today['story_value_cents']);
        $this->assertSame(0, $today['product_value_cents']);
        $this->assertSame(54_000, $today['total_value_cents']);
        $this->assertSame(7_000, $today['payments_cents']);
        $this->assertSame(54_000, $today['average_order_cents']);

        $previousDay = $days->get('2026-08-30');
        $this->assertSame(1, $previousDay['new_checkouts']);
        $this->assertSame(0, $previousDay['story_checkouts']);
        $this->assertSame(1, $previousDay['product_checkouts']);
        $this->assertSame(27_000, $previousDay['product_value_cents']);
        $this->assertSame(1, $previousDay['cancelled_checkouts']);
        $this->assertSame(27_000, $previousDay['average_order_cents']);
    }

    public function test_search_status_mixed_and_date_filters_match_checkout_contents(): void
    {
        $this->checkoutFixture();

        foreach ([
            ['status' => 'new'],
            ['status' => 'mixed'],
            ['q' => 'DIRECT-BOOK'],
            ['q' => 'رنا'],
            ['q' => '01000000000'],
            ['from' => OrderDateTime::display(now())->toDateString(), 'to' => OrderDateTime::display(now())->toDateString()],
        ] as $query) {
            $groups = $this->actingAs($this->admin)
                ->get(route('admin.orders.index', $query))
                ->assertOk()
                ->viewData('groups');

            $this->assertSame(1, $groups->total(), 'Filter did not retain the grouped checkout: '.json_encode($query));
        }

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => 'delivered']))
            ->assertOk()
            ->assertDontSee('GROUP-MULTI');
    }

    public function test_orders_index_defaults_to_twenty_five_checkouts_per_page(): void
    {
        foreach (range(1, 26) as $index) {
            $this->createStoryOrder(
                'HK-PAGE-'.$index,
                'GROUP-PAGE-'.$index,
                'طفل '.$index,
                'new',
            );
        }

        $groups = $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('تصدير Excel (CSV)')
            ->viewData('groups');

        $this->assertSame(25, $groups->perPage());
        $this->assertCount(25, $groups->items());
        $this->assertSame(26, $groups->total());
    }

    public function test_orders_csv_export_contains_only_checkouts_matching_the_active_filters(): void
    {
        $included = $this->createStoryOrder('HK-EXPORT-NEW', 'GROUP-EXPORT-NEW', 'مريم', 'new');
        $included->update(['order_source' => 'whatsapp']);
        $included->items()->create([
            'item_type' => 'story',
            'story_id' => $included->story_id,
            'title' => 'قصة مريم',
            'unit_price_cents' => 34_900,
            'quantity' => 1,
            'total_price_cents' => 34_900,
        ]);

        $excluded = $this->createStoryOrder('HK-EXPORT-DONE', 'GROUP-EXPORT-DONE', 'عمر', 'delivered');
        $excluded->items()->create([
            'item_type' => 'story',
            'story_id' => $excluded->story_id,
            'title' => 'قصة عمر',
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.orders.export', [
            'status' => 'new',
            'from' => OrderDateTime::display(now())->toDateString(),
            'to' => OrderDateTime::display(now())->toDateString(),
        ]));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'must-revalidate, no-cache, no-store, private');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('عملية الشراء', $csv);
        $this->assertStringContainsString('GROUP-EXPORT-NEW', $csv);
        $this->assertStringContainsString('قصة مريم', $csv);
        $this->assertStringContainsString('واتساب', $csv);
        $this->assertStringNotContainsString('GROUP-EXPORT-DONE', $csv);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $this->admin->id,
            'action' => 'orders.exported',
        ]);
    }

    public function test_filtered_order_statistics_count_each_checkout_once_and_show_order_source(): void
    {
        $cancelled = $this->createStoryOrder('HK-STATS-CANCELLED', 'GROUP-STATS-CANCELLED', 'سلمى', 'cancelled');
        $cancelled->update(['order_source' => 'phone']);
        $cancelled->items()->create([
            'item_type' => 'story',
            'story_id' => $cancelled->story_id,
            'title' => 'قصة سلمى',
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $paid = $this->createStoryOrder('HK-STATS-PAID', 'GROUP-STATS-PAID', 'آدم', 'under_review');
        $paid->update([
            'order_source' => 'whatsapp',
            'payment_status' => 'paid_in_full',
            'paid_amount_cents' => 43_900,
        ]);
        $paid->items()->create([
            'item_type' => 'story',
            'story_id' => $paid->story_id,
            'title' => 'قصة آدم',
            'unit_price_cents' => 39_900,
            'quantity' => 1,
            'total_price_cents' => 39_900,
        ]);

        $shipped = $this->createStoryOrder('HK-STATS-SHIPPED', 'GROUP-STATS-SHIPPED', 'نور', 'shipped');
        $shipped->update(['shipping_status' => 'shipped']);
        $shipped->items()->create([
            'item_type' => 'story',
            'story_id' => $shipped->story_id,
            'title' => 'قصة نور',
            'unit_price_cents' => 25_000,
            'quantity' => 1,
            'total_price_cents' => 25_000,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'from' => OrderDateTime::display(now())->toDateString(),
                'to' => OrderDateTime::display(now())->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('إحصائيات الطلبات المطابقة للفلاتر', false)
            ->assertSee('إجمالي قيمة الطلبات')
            ->assertSee('متوسط الطلب')
            ->assertSee('إجمالي المدفوع')
            ->assertSee('قيمة الطلبات الملغاة')
            ->assertSee('قيمة الطلبات المدفوعة كليًا')
            ->assertSee('الطلبات المشحونة')
            ->assertSee('المصدر')
            ->assertSee('واتساب')
            ->assertSee('الموقع');

        $stats = $response->viewData('stats');
        $this->assertSame(2, $stats['checkouts']);
        $this->assertSame(72_900, $stats['total_value_cents']);
        $this->assertSame(36_450, $stats['average_order_cents']);
        $this->assertSame(43_900, $stats['collected_cents']);
        $this->assertSame(1, $stats['payment_checkouts']);
        $this->assertSame(0, $stats['cancelled_checkouts']);
        $this->assertSame(0, $stats['cancelled_value_cents']);
        $this->assertSame(1, $stats['paid_checkouts']);
        $this->assertSame(43_900, $stats['paid_value_cents']);
        $this->assertSame(1, $stats['shipped_checkouts']);

        $cancelledStats = $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['lifecycle' => 'cancelled']))
            ->assertOk()
            ->assertSee('مكالمة هاتفية')
            ->viewData('stats');

        $this->assertSame(1, $cancelledStats['checkouts']);
        $this->assertSame(33_900, $cancelledStats['total_value_cents']);
        $this->assertSame(1, $cancelledStats['cancelled_checkouts']);
        $this->assertSame(0, $cancelledStats['paid_checkouts']);
        $this->assertSame(0, $cancelledStats['shipped_checkouts']);
    }

    public function test_multi_story_checkout_uses_one_unified_workspace_without_internal_order_navigation(): void
    {
        [$first, $second] = $this->checkoutFixture();
        $shortReference = $first->checkoutReference()->value('short_reference');

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $first->id))
            ->assertOk()
            ->assertSee('<title>'.$shortReference.' — '.config('app.name').'</title>', false)
            ->assertSee('تعديل الطلب بالكامل')
            ->assertSee('القصص والأطفال')
            ->assertSee('المنتجات المباشرة')
            ->assertSee('مغامرة رنا')
            ->assertSee('كتاب تلوين مباشر')
            ->assertSee('data-inline-production-prompt', false)
            ->assertSee('برومبت إنتاج قصة رنا')
            ->assertSee('معاينات القصص للعميل')
            ->assertSee('data-order-shipping-disclosure', false)
            ->assertDontSee('href="'.route('admin.orders.show', $first).'"', false)
            ->assertDontSee('href="'.route('admin.orders.show', $second).'"', false);

        // The old per-story URL remains available for backward compatibility,
        // but the primary workspace no longer sends staff into it.
        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $first))
            ->assertOk()
            ->assertSee('<title>'.$shortReference.' — '.config('app.name').'</title>', false)
            ->assertSeeInOrder(['دمج طلب آخر', 'مسؤول تنفيذ عملية الشراء'])
            ->assertSee('قصص أخرى في نفس عملية الشراء')
            ->assertSee('مغامرة رنا')
            ->assertSee('ملصق باسم الطفل')
            ->assertSee(route('admin.orders.show', $second), false);
    }

    public function test_order_pages_show_creation_time_and_other_checkouts_for_the_same_customer_only(): void
    {
        config(['app.display_timezone' => 'Africa/Cairo']);

        $current = $this->createStoryOrder('HK-DUP-CURRENT', 'GROUP-DUP-CURRENT', 'رنا', 'new');
        $current->items()->create([
            'item_type' => 'story',
            'story_id' => $current->story_id,
            'title' => 'قصة رنا',
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);
        $current->forceFill([
            'created_at' => CarbonImmutable::parse('2026-09-04 18:30:00', 'UTC'),
            'updated_at' => CarbonImmutable::parse('2026-09-04 18:30:00', 'UTC'),
        ])->saveQuietly();

        $sameCheckoutSibling = $this->createStoryOrder('HK-DUP-SIBLING', 'GROUP-DUP-CURRENT', 'سليم', 'new');
        $sameCheckoutSibling->items()->create([
            'item_type' => 'story',
            'story_id' => $sameCheckoutSibling->story_id,
            'title' => 'قصة سليم',
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $related = $this->createStoryOrder('HK-DUP-RELATED', 'GROUP-DUP-RELATED', 'ليلى', 'under_review');
        $related->forceFill([
            'delivery_details' => array_replace($related->delivery_details, ['phone' => '+201000000000']),
        ])->saveQuietly();
        $related->items()->create([
            'item_type' => 'story',
            'story_id' => $related->story_id,
            'title' => 'قصة ليلى المرتبطة',
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $unrelated = $this->createStoryOrder('HK-DUP-OTHER', 'GROUP-DUP-OTHER', 'آدم', 'new');
        $unrelated->forceFill([
            'delivery_details' => array_replace($unrelated->delivery_details, ['phone' => '01222222222']),
        ])->saveQuietly();

        $relatedReference = $related->checkoutReference->short_reference;
        $unrelatedReference = $unrelated->checkoutReference->short_reference;

        foreach ([
            route('admin.orders.groups.show', $current->id),
            route('admin.orders.show', $current),
        ] as $url) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk()
                ->assertSee('تاريخ إنشاء الطلب')
                ->assertSee('04/09/2026 09:30 PM')
                ->assertSee('data-related-customer-checkouts', false)
                ->assertSee('توجد طلبات أخرى لنفس رقم الهاتف')
                ->assertSee('1 طلب مرتبط')
                ->assertSee($relatedReference)
                ->assertSee(route('admin.orders.groups.show', $related->id), false)
                ->assertDontSee($unrelatedReference);
        }
    }

    public function test_order_details_show_a_downloadable_checkout_payment_summary_with_all_items(): void
    {
        [$first] = $this->checkoutFixture();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $first))
            ->assertOk()
            ->assertSee('ملخص الطلب والدفع')
            ->assertSee('تنزيل الملخص كصورة')
            ->assertSee('المبلغ المطلوب للدفع')
            ->assertSee('مغامرة رنا')
            ->assertSee('رحلة آدم')
            ->assertSee('كتاب تلوين مباشر')
            ->assertSee('ملصق باسم الطفل')
            ->assertSee('data-order-payment-summary-data', false)
            ->assertSee('GROUP-MULTI');
    }

    public function test_single_story_order_opens_unified_checkout_and_story_production_remains_available(): void
    {
        [$order] = $this->checkoutFixture(singleStory: true);

        $index = $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee(route('admin.orders.groups.show', $order), false);

        $group = $index->viewData('groups')->items()[0];
        $this->assertSame($order->id, $group['direct_order_id']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order->id))
            ->assertOk()
            ->assertSee('تعديل الطلب بالكامل')
            ->assertSee('data-inline-production-prompt', false)
            ->assertSee('برومبت إنتاج قصة')
            ->assertDontSee('href="'.route('admin.orders.show', $order).'"', false);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('العودة لعملية الشراء كاملة')
            ->assertSee('تعديل الطلب بالكامل')
            ->assertSee('المنتجات الموجودة في عملية الشراء');
    }

    public function test_bulk_status_update_updates_each_story_with_logs_activity_and_prompt_snapshots(): void
    {
        [$first, $second] = $this->checkoutFixture();

        $this->actingAs($this->admin)
            ->patch(route('admin.orders.groups.status', $first->id), [
                'status' => 'generating',
                'admin_notes' => 'بدء إنتاج القصتين',
            ])
            ->assertRedirect();

        $this->assertSame('generating', $first->refresh()->status);
        $this->assertSame('generating', $second->refresh()->status);
        $this->assertDatabaseHas('order_status_logs', ['order_id' => $first->id, 'status' => 'generating']);
        $this->assertDatabaseHas('order_status_logs', ['order_id' => $second->id, 'status' => 'generating']);
        $this->assertDatabaseHas('order_production_prompt_snapshots', ['order_id' => $first->id, 'snapshot_reason' => 'status:generating']);
        $this->assertDatabaseHas('order_production_prompt_snapshots', ['order_id' => $second->id, 'snapshot_reason' => 'status:generating']);
        $this->assertSame(2, DB::table('admin_activity_logs')->where('action', 'order.status_updated')->count());
    }

    public function test_admin_can_update_all_four_checkout_statuses_without_a_page_reload(): void
    {
        [$first, $second] = $this->checkoutFixture();

        $response = $this->actingAs($this->admin)
            ->patchJson(route('admin.orders.groups.workflow-statuses', $first->id), [
                'status' => 'generating',
                'payment_status' => 'partially_paid',
                'paid_amount' => 100,
                'payment_method' => 'انستاباي',
                'printing_status' => 'in_progress',
                'shipping_status' => 'ready',
                'admin_notes' => 'تحديث تشغيلي موحد',
            ]);

        $response->assertOk()
            ->assertJsonPath('group.status', 'generating')
            ->assertJsonPath('group.payment_status', 'partially_paid')
            ->assertJsonPath('group.printing_status', 'in_progress')
            ->assertJsonPath('group.shipping_status', 'ready');

        foreach ([$first, $second] as $order) {
            $order->refresh();
            $this->assertSame('generating', $order->status);
            $this->assertSame('partially_paid', $order->payment_status);
            $this->assertSame(10_000, $order->paid_amount_cents);
            $this->assertSame('in_progress', $order->printing_status);
            $this->assertSame('ready', $order->shipping_status);
            $this->assertDatabaseHas('order_status_logs', [
                'order_id' => $order->id,
                'status_type' => 'printing',
                'status' => 'in_progress',
            ]);
            $this->assertDatabaseHas('order_status_logs', [
                'order_id' => $order->id,
                'status_type' => 'shipping',
                'status' => 'ready',
            ]);
        }

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'checkout.workflow_statuses_updated',
            'subject_id' => $first->id,
        ]);
    }

    public function test_orders_list_exposes_workflow_filters_ajax_controls_and_normalized_whatsapp_link(): void
    {
        [$first] = $this->checkoutFixture();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', [
                'printing_status' => 'not_started',
                'shipping_status' => 'not_ready',
            ]))
            ->assertOk()
            ->assertSee('حالة الطباعة')
            ->assertSee('حالة الشحن')
            ->assertSee('تغيير الحالات', false)
            ->assertSee(route('admin.orders.groups.workflow-statuses', $first->id), false)
            ->assertSee('https://wa.me/201000000000', false)
            ->assertDontSee('حذف العملية');

        $this->assertSame('201111822277', Phone::forWhatsApp('01111822277'));
        $this->assertSame('201111822277', Phone::forWhatsApp('201111822277'));
    }

    public function test_unified_checkout_and_story_production_details_show_the_status_workflow(): void
    {
        [$first] = $this->checkoutFixture();
        $route = route('admin.orders.groups.workflow-statuses', $first->id);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $first->id))
            ->assertOk()
            ->assertSee('حالات عملية الشراء')
            ->assertSee($route, false);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $first))
            ->assertOk()
            ->assertSee('حالات عملية الشراء')
            ->assertSee($route, false);
    }

    public function test_whole_checkout_deletion_restores_stock_cancels_production_and_is_recoverable(): void
    {
        [$first, $second, $addOn, $direct] = $this->checkoutFixture();
        $project = ProductionProject::create([
            'order_id' => $first->id,
            'status' => 'in_progress',
            'current_stage' => 'illustration',
            'created_by_user_id' => $this->admin->id,
        ]);
        $run = ProductionAutomationRun::create([
            'production_project_id' => $project->id,
            'active_project_id' => $project->id,
            'status' => 'running',
            'current_stage' => 'generation',
            'current_step_key' => 'scene-1',
            'started_by_user_id' => $this->admin->id,
            'started_at' => now(),
            'last_transition_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.groups.destroy', $first->id), [
                'deletion_reason' => 'طلب تجريبي تم تسجيله بالخطأ',
                'confirmation' => 'GROUP-MULTI',
            ])
            ->assertRedirect(route('admin.orders.index', ['view' => 'trash']));

        $this->assertSoftDeleted('orders', ['id' => $first->id]);
        $this->assertSoftDeleted('orders', ['id' => $second->id]);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(11, $addOn->refresh()->stock_quantity);
        $this->assertSame(12, $direct->refresh()->stock_quantity);
        $this->assertSame('cancelled', $project->refresh()->status);
        $this->assertSame('cancelled', $run->refresh()->status);
        $this->assertNull($run->active_project_id);
        $this->assertDatabaseHas('production_projects', ['id' => $project->id, 'order_id' => $first->id]);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'checkout.deleted', 'subject_id' => $first->id]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertDontSee('GROUP-MULTI');

        $salesReport = $this->actingAs($this->admin)
            ->get(route('admin.sales-report.index'))
            ->assertOk()
            ->viewData('report');
        $this->assertSame(0, $salesReport['summary']['checkouts']);
        $this->assertSame(0, $salesReport['summary']['order_records']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['view' => 'trash']))
            ->assertOk()
            ->assertSee('GROUP-MULTI');

        $this->actingAs($this->admin)
            ->post(route('admin.orders.groups.restore', $first->id))
            ->assertRedirect(route('admin.orders.groups.show', $first->id));

        $this->assertNull($first->refresh()->deleted_at);
        $this->assertNull($second->refresh()->deleted_at);
        $this->assertSame(10, $addOn->refresh()->stock_quantity);
        $this->assertSame(10, $direct->refresh()->stock_quantity);
        $this->assertSame('cancelled', $project->refresh()->status);
        $this->assertSame('cancelled', $run->refresh()->status);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'checkout.restored']);
    }

    public function test_individual_story_deletion_moves_direct_products_and_only_releases_linked_stock(): void
    {
        [$first, $second, $addOn, $direct] = $this->checkoutFixture();
        $directItem = $first->items()->where('item_type', 'product')->firstOrFail();
        $addOnItem = $first->items()->where('item_type', 'product_add_on')->firstOrFail();

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.destroy', $first), [
                'deletion_reason' => 'القصة أضيفت إلى الطلب بالخطأ',
                'confirmation' => $first->order_number,
            ])
            ->assertRedirect(route('admin.orders.groups.show', $first->id));

        $this->assertSoftDeleted('orders', ['id' => $first->id]);
        $this->assertDatabaseHas('order_items', ['id' => $directItem->id, 'order_id' => $second->id, 'stock_released_at' => null]);
        $this->assertDatabaseHas('order_items', ['id' => $addOnItem->id, 'order_id' => $first->id]);
        $this->assertSame(11, $addOn->refresh()->stock_quantity);
        $this->assertSame(10, $direct->refresh()->stock_quantity);

        $partialGroup = app(AdminOrderGroupService::class)->findByRepresentative($first->id);
        $this->assertSame($second->id, $partialGroup['representative_id']);
        $this->assertSame($second->id, $partialGroup['direct_order_id']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $first->id))
            ->assertOk()
            ->assertSee(route('admin.orders.show', $second), false);

        $this->actingAs($this->admin)
            ->post(route('admin.orders.restore', $first->id))
            ->assertRedirect(route('admin.orders.groups.show', $first->id));

        $this->assertNull($first->refresh()->deleted_at);
        $this->assertSame(10, $addOn->refresh()->stock_quantity);
        $this->assertSame($second->id, $directItem->refresh()->order_id);
    }

    public function test_delete_requires_permission_reason_and_exact_confirmation_and_restore_checks_stock(): void
    {
        [$first, , $addOn] = $this->checkoutFixture();
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::whereIn('key', ['orders.view'])->pluck('id'));
        $limited->unsetRelation('permissions');

        $this->actingAs($limited)
            ->delete(route('admin.orders.groups.destroy', $first->id), [
                'deletion_reason' => 'سبب واضح',
                'confirmation' => 'GROUP-MULTI',
            ])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->from(route('admin.orders.groups.show', $first->id))
            ->delete(route('admin.orders.groups.destroy', $first->id), [
                'deletion_reason' => 'سبب واضح للحذف',
                'confirmation' => 'WRONG',
            ])
            ->assertSessionHasErrors('confirmation');

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.groups.destroy', $first->id), [
                'deletion_reason' => 'طلب تم إنشاؤه بالخطأ',
                'confirmation' => 'GROUP-MULTI',
            ]);

        $addOn->update(['stock_quantity' => 0]);

        $this->actingAs($this->admin)
            ->from(route('admin.orders.index', ['view' => 'trash']))
            ->post(route('admin.orders.groups.restore', $first->id))
            ->assertSessionHasErrors('restore');

        $this->assertSoftDeleted('orders', ['id' => $first->id]);
        $this->assertSame(0, $addOn->refresh()->stock_quantity);
    }

    public function test_last_story_with_independent_products_cannot_be_deleted_individually(): void
    {
        [$first] = $this->checkoutFixture(singleStory: true);

        $this->actingAs($this->admin)
            ->from(route('admin.orders.groups.show', $first->id))
            ->delete(route('admin.orders.destroy', $first), [
                'deletion_reason' => 'محاولة حذف القصة الأخيرة',
                'confirmation' => $first->order_number,
            ])
            ->assertSessionHasErrors('delete');

        $this->assertNull($first->fresh()->deleted_at);
    }

    public function test_legacy_and_product_only_orders_remain_single_manageable_checkouts(): void
    {
        $product = Product::create([
            'name_ar' => 'هدية مباشرة',
            'slug' => 'legacy-direct-product',
            'price_cents' => 12_000,
            'is_active' => true,
        ]);
        $order = Order::create([
            'order_number' => 'HK-PRODUCT-ONLY',
            'parent_name' => 'عميل منتج مباشر',
            'story_id' => null,
            'child_name' => null,
            'child_age' => null,
            'child_gender' => null,
            'language' => null,
            'delivery_details' => ['phone' => '01012345678', 'delivery_fee' => 30],
            'status' => 'new',
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => 'هدية مباشرة',
            'sku' => 'GIFT-DIRECT',
            'unit_price_cents' => 12_000,
            'quantity' => 1,
            'total_price_cents' => 12_000,
        ]);

        $this->assertSame('ORDER-'.$order->id, $order->refresh()->checkout_group_key);

        $response = $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'products',
            'lifecycle' => 'active',
        ]));
        $response->assertOk()->assertSee('HK-PRODUCT-ONLY')->assertSee('هدية مباشرة');
        $this->assertSame(1, $response->viewData('stats')['checkouts']);
        $this->assertSame(0, $response->viewData('stats')['stories']);
        $this->assertSame(1, $response->viewData('stats')['products']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order->id))
            ->assertOk()
            ->assertSee('data-order-page-section="overview"', false)
            ->assertSee('data-order-page-section="items"', false)
            ->assertSee('data-order-page-section="follow-up"', false)
            ->assertSee('المنتجات المباشرة')
            ->assertSee('ملاحظات فريق العمل')
            ->assertSee('مرفقات الطلب')
            ->assertDontSee('لا توجد قصص نشطة في هذه العملية.')
            ->assertSee('GIFT-DIRECT');
    }

    public function test_order_tabs_separate_story_product_active_finished_and_cancelled_checkouts(): void
    {
        [$storyWithProducts] = $this->checkoutFixture(singleStory: true);

        $product = Product::create([
            'name_ar' => 'منتج مباشر للتبويبات',
            'slug' => 'tabs-direct-product',
            'price_cents' => 12_000,
            'is_active' => true,
        ]);
        $productOnly = Order::create([
            'order_number' => 'HK-TABS-PRODUCT',
            'checkout_group_key' => 'GROUP-TABS-PRODUCT',
            'parent_name' => 'عميل منتج',
            'delivery_details' => ['phone' => '01012345678', 'delivery_fee' => 30],
            'status' => 'new',
        ]);
        $productOnly->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 12_000,
            'quantity' => 1,
            'total_price_cents' => 12_000,
        ]);

        $finished = $this->createStoryOrder('HK-TABS-FINISHED', 'GROUP-TABS-FINISHED', 'تميم', 'delivered');
        $finished->update([
            'payment_status' => 'paid_in_full',
            'printing_status' => 'completed',
            'shipping_status' => 'delivered',
        ]);
        $finished->items()->create([
            'item_type' => 'story',
            'story_id' => $finished->story_id,
            'title' => $finished->story->title,
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $cancelled = $this->createStoryOrder('HK-TABS-CANCELLED', 'GROUP-TABS-CANCELLED', 'سليم', 'cancelled');
        $cancelled->items()->create([
            'item_type' => 'story',
            'story_id' => $cancelled->story_id,
            'title' => $cancelled->story->title,
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $supersededActive = $this->createStoryOrder(
            'HK-TABS-SUPERSEDED-ACTIVE',
            $storyWithProducts->checkout_group_key,
            'نسخة قديمة نشطة',
            'cancelled',
        );
        $supersededActive->delete();

        $supersededFinished = $this->createStoryOrder(
            'HK-TABS-SUPERSEDED-FINISHED',
            $finished->checkout_group_key,
            'نسخة قديمة منتهية',
            'cancelled',
        );
        $supersededFinished->delete();

        $cancelledShipment = $this->createStoryOrder(
            'HK-TABS-SHIPMENT-CANCELLED',
            'GROUP-TABS-SHIPMENT-CANCELLED',
            'إعادة حجز الشحنة',
            'under_review',
        );
        $cancelledShipment->update(['shipping_status' => 'cancelled']);
        $cancelledShipment->items()->create([
            'item_type' => 'story',
            'story_id' => $cancelledShipment->story_id,
            'title' => $cancelledShipment->story->title,
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
        ]);

        $deletedProduct = Order::create([
            'order_number' => 'HK-TABS-DELETED-PRODUCT',
            'checkout_group_key' => 'GROUP-TABS-DELETED-PRODUCT',
            'parent_name' => 'عميل محذوف',
            'delivery_details' => ['phone' => '01087654321'],
            'status' => 'new',
        ]);
        $deletedProduct->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 12_000,
            'quantity' => 1,
            'total_price_cents' => 12_000,
        ]);
        $deletedProduct->delete();

        $storyActive = $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'stories',
            'lifecycle' => 'active',
        ]));
        $storyActive->assertOk()
            ->assertSee('طلبات القصص')
            ->assertSee('طلبات المنتجات')
            ->assertSee('الطلبات النشطة')
            ->assertSee('الطلبات المنتهية')
            ->assertSee('ملغاة / محذوفة')
            ->assertSee($storyWithProducts->checkout_group_key)
            ->assertSee('GROUP-TABS-SHIPMENT-CANCELLED')
            ->assertDontSee('GROUP-TABS-PRODUCT')
            ->assertDontSee('GROUP-TABS-FINISHED')
            ->assertDontSee('GROUP-TABS-CANCELLED');

        $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'products',
            'lifecycle' => 'active',
        ]))->assertOk()
            ->assertSee('GROUP-TABS-PRODUCT')
            ->assertDontSee($storyWithProducts->checkout_group_key);

        $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'stories',
            'lifecycle' => 'finished',
        ]))->assertOk()
            ->assertSee('GROUP-TABS-FINISHED')
            ->assertDontSee('HK-TABS-SUPERSEDED-FINISHED')
            ->assertDontSee($storyWithProducts->checkout_group_key);

        $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'stories',
            'lifecycle' => 'cancelled',
        ]))->assertOk()
            ->assertSee('GROUP-TABS-CANCELLED')
            ->assertDontSee('GROUP-TABS-FINISHED')
            ->assertDontSee($storyWithProducts->checkout_group_key)
            ->assertDontSee('GROUP-TABS-SHIPMENT-CANCELLED');

        $this->actingAs($this->admin)->get(route('admin.orders.index', [
            'catalog_type' => 'products',
            'lifecycle' => 'cancelled',
        ]))->assertOk()
            ->assertSee('GROUP-TABS-DELETED-PRODUCT')
            ->assertDontSee('GROUP-TABS-PRODUCT');

        $csv = $this->actingAs($this->admin)->get(route('admin.orders.export', [
            'catalog_type' => 'products',
            'lifecycle' => 'active',
        ]))->streamedContent();
        $this->assertStringContainsString('GROUP-TABS-PRODUCT', $csv);
        $this->assertStringNotContainsString($storyWithProducts->checkout_group_key, $csv);
        $this->assertStringNotContainsString('GROUP-TABS-DELETED-PRODUCT', $csv);
    }

    public function test_group_catalog_query_count_does_not_grow_with_checkout_contents(): void
    {
        $this->checkoutFixture(singleStory: true);
        $service = app(AdminOrderGroupService::class);

        DB::enableQueryLog();
        $service->paginate(Request::create('/admin/orders'));
        $oneGroupQueries = count(DB::getQueryLog());
        DB::flushQueryLog();

        for ($index = 1; $index <= 8; $index++) {
            $this->createStoryOrder('HK-Q-'.$index, 'QUERY-'.$index, 'طفل '.$index, 'new');
        }

        DB::flushQueryLog();
        $service->paginate(Request::create('/admin/orders'));
        $manyGroupQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual($oneGroupQueries + 1, $manyGroupQueries);
    }

    private function checkoutFixture(bool $singleStory = false): array
    {
        $first = $this->createStoryOrder('HK-MULTI-1', 'GROUP-MULTI', 'رنا', 'new');
        $second = $singleStory ? null : $this->createStoryOrder('HK-MULTI-2', 'GROUP-MULTI', 'آدم', 'shipped');
        $addOn = Product::create([
            'name_ar' => 'ملصق',
            'slug' => 'group-addon-'.uniqid(),
            'price_cents' => 5_000,
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        $direct = Product::create([
            'name_ar' => 'كتاب تلوين',
            'slug' => 'group-direct-'.uniqid(),
            'price_cents' => 7_500,
            'inventory_mode' => 'track_stock',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $storyItem = $first->items()->create([
            'item_type' => 'story',
            'story_id' => $first->story_id,
            'title' => 'مغامرة رنا',
            'unit_price_cents' => 29_900,
            'quantity' => 1,
            'total_price_cents' => 29_900,
            'item_snapshot' => ['price_cents' => 29_900],
        ]);
        $first->items()->create([
            'item_type' => 'product_add_on',
            'product_id' => $addOn->id,
            'linked_order_item_id' => $storyItem->id,
            'title' => 'ملصق باسم الطفل',
            'sku' => 'ADD-POSTER',
            'unit_price_cents' => 5_000,
            'quantity' => 1,
            'total_price_cents' => 5_000,
            'item_snapshot' => ['name_ar' => 'ملصق باسم الطفل', 'sku' => 'ADD-POSTER'],
        ]);
        $first->items()->create([
            'item_type' => 'product',
            'product_id' => $direct->id,
            'title' => 'كتاب تلوين مباشر',
            'sku' => 'DIRECT-BOOK',
            'unit_price_cents' => 7_500,
            'quantity' => 2,
            'total_price_cents' => 15_000,
            'item_snapshot' => ['name_ar' => 'كتاب تلوين مباشر', 'sku' => 'DIRECT-BOOK'],
        ]);

        if ($second) {
            $second->items()->create([
                'item_type' => 'story',
                'story_id' => $second->story_id,
                'title' => 'رحلة آدم',
                'unit_price_cents' => 39_900,
                'quantity' => 1,
                'total_price_cents' => 39_900,
                'item_snapshot' => ['price_cents' => 39_900],
            ]);
        }

        return [$first, $second, $addOn, $direct];
    }

    private function createStoryOrder(string $number, string $group, string $child, string $status): Order
    {
        $story = Story::create([
            'title' => 'قصة '.$child,
            'slug' => strtolower(str_replace([' ', '_'], '-', $number)).'-'.uniqid(),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 299,
            'active' => true,
        ]);

        return Order::create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'parent_name' => 'والدة الأطفال',
            'story_id' => $story->id,
            'child_name' => $child,
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => [
                'checkout_group' => $group,
                'phone' => '01000000000',
                'country' => 'مصر',
                'governorate' => 'القاهرة',
                'city' => 'مدينة نصر',
                'street' => 'شارع الاختبار',
                'delivery_fee' => 40,
            ],
            'uploaded_photos' => ['orders/photos/reference.jpg'],
            'status' => $status,
        ]);
    }

    private function recordPaymentEvent(Order $order, int $previousPaid, int $newPaid): OrderPaymentEvent
    {
        return OrderPaymentEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'checkout_group_key' => $order->checkoutGroupKey(),
            'order_id' => $order->id,
            'actor_user_id' => $this->admin->id,
            'event_type' => $newPaid >= $previousPaid ? 'payment_received' : 'payment_reversed',
            'source' => 'admin_payment_update',
            'previous_status' => $previousPaid > 0 ? 'partially_paid' : 'unpaid',
            'new_status' => 'partially_paid',
            'previous_paid_amount_cents' => $previousPaid,
            'new_paid_amount_cents' => $newPaid,
            'amount_delta_cents' => $newPaid - $previousPaid,
            'affects_collection_stats' => true,
            'payment_method' => 'انستاباي',
            'occurred_at' => now(),
        ]);
    }
}
