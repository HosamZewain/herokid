<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\OrderStatusDefinition;
use App\Models\OrderTag;
use App\Models\Permission;
use App\Models\User;
use App\Services\Orders\OrderFinancialStatistics;
use App\Support\OrderStatusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminOrderOperationalStatisticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_staff_see_counts_and_active_tags_without_financial_statistics(): void
    {
        $staff = $this->staff();
        $first = $this->order('OPS-NEW', 'new');
        $this->order('OPS-NEW', 'new');
        $this->order('OPS-ASSIGNED', 'printing');
        $this->order('OPS-MIXED', 'new');
        $this->order('OPS-MIXED', 'printing');
        $this->order('OPS-CANCELLED', 'cancelled');
        $this->order('OPS-DONE', 'delivered', ['shipping_status' => 'delivered', 'printing_status' => 'completed', 'payment_status' => 'paid_in_full']);
        OrderGroupAssignment::create(['checkout_group_key' => 'OPS-ASSIGNED', 'assigned_to_user_id' => $staff->id, 'assigned_by_user_id' => $staff->id, 'assigned_at' => now()]);
        $tag = OrderTag::create(['name' => 'متابعة تجريبية', 'normalized_name' => 'متابعة تجريبية']);
        $first->checkoutReference->tags()->attach($tag);
        $this->mock(OrderFinancialStatistics::class)->shouldNotReceive('summarize');

        $response = $this->actingAs($staff)->get(route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all']))
            ->assertOk()->assertViewHas('stats', null)
            ->assertSee('إجمالي الطلبات النشطة')->assertSee('الطلبات غير المستلمة')
            ->assertSee('العلامات النشطة')->assertSee('#متابعة تجريبية')
            ->assertDontSee('إحصائيات الطلبات المطابقة للفلاتر', false)
            ->assertDontSee('إجمالي قيمة الطلبات')->assertDontSee('متوسط الطلب بدون الشحن')
            ->assertDontSee('الرصيد المدفوع للطلبات المختارة')->assertDontSee('قيمة الطلبات المدفوعة كليًا');
        $counts = $response->viewData('operationalStats');
        $this->assertSame(3, $counts['active_checkouts']);
        $this->assertSame(2, $counts['unassigned_checkouts']);
        $statuses = collect($counts['statuses'])->keyBy('key');
        foreach (['new' => 2, 'printing' => 2, 'mixed' => 1, 'cancelled' => 1, 'delivered' => 1] as $status => $expected) {
            $this->assertSame($expected, $statuses[$status]['count']);
        }
        $this->assertSame(5, $response->viewData('groups')->total());
        $this->assertSame(1, (int) $response->viewData('activeTags')->sole()->checkouts_count);
        $this->assertSame(['active_checkouts', 'unassigned_checkouts', 'statuses'], array_keys($counts));
        $this->assertFalse($staff->fresh()->hasPermission('orders.statistics.view'));
    }

    public function test_counts_respect_filters_and_do_not_turn_partial_matches_into_duplicate_checkouts(): void
    {
        $staff = $this->staff();
        $first = $this->order('OPS-A', 'new');
        $this->order('OPS-A', 'printing');
        $this->order('OPS-B', 'new');
        $tag = OrderTag::create(['name' => 'فلتر تجريبي', 'normalized_name' => 'فلتر تجريبي']);
        $first->checkoutReference->tags()->attach($tag);
        OrderGroupAssignment::create(['checkout_group_key' => 'OPS-A', 'assigned_to_user_id' => $staff->id, 'assigned_by_user_id' => $staff->id, 'assigned_at' => now()]);
        foreach ([['q' => 'OPS-A'], ['assignment' => 'mine'], ['tag_id' => $tag->id], ['status' => 'printing'], ['lifecycle' => 'cancelled']] as $filter) {
            $params = $filter + ['catalog_type' => 'all', 'page' => 2];
            $response = $this->actingAs($staff)->get(route('admin.orders.index', $params))->assertOk();
            $counts = $response->viewData('operationalStats');
            $activeParams = array_merge(array_diff_key($params, array_flip(['page', 'view', 'lifecycle'])), ['lifecycle' => 'active']);
            $unassignedParams = array_merge(array_diff_key($params, array_flip(['page', 'view', 'lifecycle', 'assignment'])), ['lifecycle' => 'active', 'assignment' => 'unassigned']);
            foreach ([[$activeParams, $counts['active_checkouts']], [$unassignedParams, $counts['unassigned_checkouts']]] as [$query, $expected]) {
                $url = route('admin.orders.index', $query);
                $response->assertSee('href="'.e($url).'"', false);
                $this->get($url)->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === $expected);
            }
            foreach ($counts['statuses'] as $status) {
                $query = array_merge(array_diff_key($params, array_flip(['page', 'status'])), ['status' => [$status['key']]]);
                $url = route('admin.orders.index', $query);
                $response->assertSee('href="'.e($url).'"', false);
                $this->get($url)->assertOk()->assertViewHas('groups', fn ($groups) => $groups->total() === $status['count']);
            }
        }
        $empty = $this->actingAs($staff)->get(route('admin.orders.index', ['q' => 'NO-MATCH', 'catalog_type' => 'all']))->assertOk()->viewData('operationalStats');
        $this->assertSame(0, $empty['active_checkouts']);
        $this->assertSame(0, $empty['unassigned_checkouts']);
        $this->assertSame(0, collect($empty['statuses'])->sum('count'));
        $this->assertContains('cancelled', array_column($empty['statuses'], 'key'));
    }

    public function test_retired_and_custom_statuses_and_deleted_parts_match_visible_groups(): void
    {
        $staff = $this->staff(['orders.delete']);
        OrderStatusDefinition::create(['type' => 'order', 'key' => 'custom_ops', 'label_ar' => 'مراجعة تشغيل', 'color' => 'indigo', 'behavior' => 'standard', 'is_active' => true, 'sort_order' => 20]);
        OrderStatusDefinition::create(['type' => 'order', 'key' => 'retired_ops', 'label_ar' => 'حالة قديمة', 'color' => 'slate', 'behavior' => 'standard', 'is_active' => false, 'sort_order' => 21]);
        OrderStatusRegistry::clearCache();
        $this->order('OPS-CUSTOM', 'custom_ops');
        $this->order('OPS-RETIRED', 'retired_ops');
        $this->order('OPS-LIVE', 'new');
        $this->order('OPS-LIVE', 'printing')->delete();
        $this->order('OPS-DELETED', 'cancelled')->delete();
        $response = $this->actingAs($staff)->get(route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all']))->assertOk();
        $statuses = collect($response->viewData('operationalStats')['statuses'])->keyBy('key');
        foreach (['custom_ops', 'retired_ops', 'new', 'cancelled'] as $key) {
            $this->assertSame(1, $statuses[$key]['count']);
        }
        $this->assertSame(0, $statuses['mixed']['count']);
        $this->assertSame(4, $statuses->sum('count'));
        $this->assertSame(3, $response->viewData('operationalStats')['active_checkouts']);
        $viewer = $this->staff();
        $this->actingAs($viewer)->get(route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'all']))
            ->assertOk()->assertViewHas('operationalStats', fn ($v) => collect($v['statuses'])->sum('count') === 3);
    }

    public function test_active_cancelled_catalog_and_owner_views_keep_existing_boundaries(): void
    {
        $staff = $this->staff();
        $this->order('OPS-PRODUCT', 'new');
        $this->order('OPS-CANCELLED', 'cancelled');
        $this->actingAs($staff)->get(route('admin.orders.index', ['catalog_type' => 'all']))->assertOk()
            ->assertViewHas('operationalStats', fn ($v) => $v['active_checkouts'] === 1 && collect($v['statuses'])->firstWhere('key', 'cancelled')['count'] === 0);
        $this->actingAs($staff)->get(route('admin.orders.index', ['catalog_type' => 'all', 'lifecycle' => 'cancelled']))->assertOk()
            ->assertViewHas('operationalStats', fn ($v) => $v['active_checkouts'] === 1 && $v['unassigned_checkouts'] === 1 && collect($v['statuses'])->firstWhere('key', 'cancelled')['count'] === 1);
        $this->actingAs($staff)->get(route('admin.orders.index', ['catalog_type' => 'stories']))->assertOk()
            ->assertViewHas('operationalStats', fn ($v) => collect($v['statuses'])->sum('count') === 0);
        $owner = $this->staff(['orders.statistics.view']);
        $this->actingAs($owner)->get(route('admin.orders.index', ['catalog_type' => 'all']))->assertOk()
            ->assertSee('متابعة أعداد الطلبات', false)->assertSee('إجمالي قيمة الطلبات')
            ->assertViewHas('stats', fn ($v) => is_array($v));
        $outsider = User::factory()->create(['role' => 'customer']);
        $this->actingAs($outsider)->get(route('admin.orders.index'))->assertForbidden();
    }

    private function staff(array $extra = []): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->permissions()->sync(Permission::whereIn('key', ['orders.view', ...$extra])->pluck('id'));

        return $user->refresh();
    }

    private function order(string $key, string $status, array $extra = []): Order
    {
        $order = Order::create($extra + ['order_number' => 'OPS-'.fake()->unique()->numerify('######'), 'checkout_group_key' => $key, 'parent_name' => 'Synthetic operations fixture', 'status' => $status, 'shipping_status' => 'not_ready', 'printing_status' => 'not_started', 'payment_status' => 'unpaid']);
        $order->items()->create(['item_type' => 'product', 'title' => 'منتج تجريبي', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);

        return $order;
    }
}
