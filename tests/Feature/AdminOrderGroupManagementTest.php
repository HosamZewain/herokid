<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionAutomationRun;
use App\Models\ProductionProject;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Support\Phone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('GROUP-MULTI')
            ->assertSee(route('admin.orders.show', $first), false)
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
        $this->assertSame(['checkouts' => 1, 'stories' => 2, 'products' => 3], $stats);
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
        }

        $response = $this->actingAs($this->admin)
            ->get(route('admin.dashboard.index'));

        $response->assertOk()
            ->assertSee('عمليات شراء جديدة تنتظر المراجعة')
            ->assertSee('تتضمن 2 سجل طلب')
            ->assertSee('GROUP-DASHBOARD')
            ->assertSee('2 قصة');

        $this->assertSame(1, $response->viewData('newOrders'));
        $this->assertSame(1, $response->viewData('totalOrders'));
        $this->assertSame(2, $response->viewData('orderRecordCounts')['new']);
        $this->assertCount(1, $response->viewData('recentOrders'));
        $this->assertSame(2, $response->viewData('recentOrders')->first()['story_count']);

        $groups = $this->actingAs($this->admin)
            ->get(route('admin.orders.index', ['status' => 'new']))
            ->assertOk()
            ->viewData('groups');

        $this->assertSame(1, $groups->total());
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
            ['from' => now()->toDateString(), 'to' => now()->toDateString()],
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

    public function test_multi_story_checkout_opens_first_production_order_and_keeps_sibling_navigation(): void
    {
        [$first, $second] = $this->checkoutFixture();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $first->id))
            ->assertRedirect(route('admin.orders.show', $first));

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $first))
            ->assertOk()
            ->assertSee('قصص أخرى في نفس عملية الشراء')
            ->assertSee('مغامرة رنا')
            ->assertSee('ملصق باسم الطفل')
            ->assertSee(route('admin.orders.show', $second), false);
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

    public function test_single_order_opens_production_details_without_the_group_intermediate_page(): void
    {
        [$order] = $this->checkoutFixture(singleStory: true);

        $index = $this->actingAs($this->admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee(route('admin.orders.show', $order), false);

        $group = $index->viewData('groups')->items()[0];
        $this->assertSame($order->id, $group['direct_order_id']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order->id))
            ->assertRedirect(route('admin.orders.show', $order));

        $this->actingAs($this->admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('العودة لعملية الشراء');
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

    public function test_order_details_show_the_unified_status_panel_without_group_page(): void
    {
        [$first] = $this->checkoutFixture();
        $route = route('admin.orders.groups.workflow-statuses', $first->id);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $first->id))
            ->assertRedirect(route('admin.orders.show', $first));

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
            ->assertRedirect(route('admin.orders.show', $second));

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

        $response = $this->actingAs($this->admin)->get(route('admin.orders.index'));
        $response->assertOk()->assertSee('HK-PRODUCT-ONLY')->assertSee('هدية مباشرة');
        $this->assertSame(1, $response->viewData('stats')['checkouts']);
        $this->assertSame(0, $response->viewData('stats')['stories']);
        $this->assertSame(1, $response->viewData('stats')['products']);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order->id))
            ->assertOk()
            ->assertSee('المنتجات المباشرة')
            ->assertSee('GIFT-DIRECT');
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
}
