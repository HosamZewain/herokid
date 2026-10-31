<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\User;
use App\Services\Orders\EmployeePerformanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmployeePerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $name): User
    {
        $user = User::factory()->create(['role' => 'admin', 'name' => $name]);
        $user->permissions()->sync([]);
        $user->adminRoles()->sync([]);

        return $user->fresh();
    }

    private function shipment(User $user, string $key, string $status = 'shipped', ?string $date = '2026-10-29 21:30:00', string $title = 'Synthetic Product'): Order
    {
        $order = Order::create(['order_number' => 'PERF-'.fake()->uuid(), 'checkout_group_key' => $key,
            'parent_name' => 'Synthetic Parent', 'child_name' => 'Synthetic Child', 'status' => 'new',
            'shipping_status' => $status, 'discount_cents' => 1000, 'delivery_details' => ['delivery_fee' => 90]]);
        $order->items()->create(['item_type' => 'product', 'title' => $title, 'sku' => $key,
            'quantity' => 2, 'unit_price_cents' => 10000, 'total_price_cents' => 20000]);
        OrderGroupAssignment::updateOrCreate(['checkout_group_key' => $key], ['assigned_to_user_id' => $user->id, 'assigned_at' => now()]);
        if ($date) {
            $order->statusLogs()->create(['status_type' => 'shipping', 'status' => 'shipped', 'created_at' => $date, 'updated_at' => $date]);
        }

        return $order;
    }

    private function url(array $filters = []): string
    {
        return route('admin.employee-performance.index', $filters + ['from' => '2026-10-29', 'to' => '2026-10-30']);
    }

    public function test_full_admin_can_compare_all_one_or_multiple_including_zero_employees(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $first = $this->employee('Synthetic First');
        $second = $this->employee('Synthetic Second');
        $empty = $this->employee('Synthetic Empty');
        $this->shipment($first, 'FIRST');
        $this->shipment($first, 'FIRST-DONE', 'delivered');
        $this->shipment($second, 'SECOND', 'delivered');
        $this->shipment($second, 'RETURNED', 'returned');
        $this->shipment($second, 'OUTSIDE', 'shipped', '2026-10-28 10:00:00');
        $this->shipment($second, 'UNDATED', 'delivered', null);
        $this->shipment($second, 'UNASSIGNED', 'delivered');
        OrderGroupAssignment::where('checkout_group_key', 'UNASSIGNED')->delete();

        $all = $this->actingAs($owner)->get($this->url())->assertOk()->assertSee('تقرير أداء الموظفين')->viewData('report');
        $this->assertTrue($all['canCompare']);
        $this->assertSame(3, $all['summary']['shipments']);
        $this->assertSame(2, $all['summary']['delivered']);
        $this->assertSame(1, $all['summary']['in_transit']);
        $this->assertSame(57000, $all['summary']['items_net_cents']);
        $this->assertSame(1, $all['undated']);
        $this->assertSame(0, $all['teams']->firstWhere('id', (string) $empty->id)['shipments']);
        $this->assertSame(3, (int) $all['daily']->sum('shipments'));

        $one = $this->get($this->url(['employees' => [$first->id]]))->assertOk()->assertSee('تجميع المنتجات والقصص')->viewData('report');
        $this->assertCount(1, $one['teams']);
        $this->assertSame(2, $one['summary']['shipments']);
        $this->assertSame(50.0, $one['teams']->first()['delivery_rate']);
        $this->assertSame(0, $one['undated']);
        $this->assertSame(38000, (int) $one['sku_breakdown']->sum('net_cents'));

        $two = $this->get($this->url(['employees' => [$first->id, $second->id]]))->assertOk()->viewData('report');
        $this->assertCount(2, $two['teams']);
        $this->assertSame($all['summary'], $two['summary']);
    }

    public function test_ordinary_employee_cannot_leak_another_employee_through_filters_totals_warnings_or_html(): void
    {
        $own = $this->employee('Own Employee');
        $other = $this->employee('Hidden Employee');
        $this->shipment($own, 'OWN-SKU', 'delivered', title: 'Own Product');
        $this->shipment($other, 'HIDDEN-SKU', title: 'Hidden Product');
        $this->shipment($other, 'HIDDEN-UNDATED', 'shipped', null);

        $response = $this->actingAs($own)->get($this->url())->assertOk()
            ->assertSee('تقريرك الشخصي فقط')->assertSee('Own Product')->assertDontSee('Hidden Employee')
            ->assertDontSee('Hidden Product')->assertDontSee('HIDDEN-SKU')->assertDontSee('name="employees[]"', false)
            ->assertDontSee('القيمة الصافية')->assertDontSee('اختيار موظف أو أكثر');
        $report = $response->viewData('report');
        $this->assertFalse($report['canCompare']);
        $this->assertFalse($report['canViewValues']);
        $this->assertSame(1, $report['summary']['shipments']);
        $this->assertSame(0, $report['undated']);
        $this->assertCount(1, $report['employees']);
        $this->assertArrayNotHasKey('net_cents', $report['sku_breakdown']->first());
        $this->get($this->url(['employees' => [$other->id]]))->assertForbidden();
        $this->get($this->url(['employees' => [$own->id, $other->id]]))->assertForbidden();
        $this->get($this->url(['employee' => $other->id, 'user_id' => $other->id, 'scope' => 'all']))
            ->assertOk()->assertDontSee('Hidden Employee')->assertDontSee('Hidden Product');

        $own->permissions()->sync(Permission::where('key', 'orders.statistics.view')->pluck('id'));
        $financial = $this->actingAs($own->fresh())->get($this->url())->assertOk()->viewData('report');
        $this->assertFalse($financial['canCompare']);
        $this->assertTrue($financial['canViewValues']);
        $this->assertSame(19000, $financial['summary']['items_net_cents']);
    }

    public function test_guest_customer_and_inactive_admin_are_denied_and_direct_service_enforces_admin(): void
    {
        $this->get($this->url())->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'customer']))->get($this->url())->assertForbidden();
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->actingAs($inactive)->get($this->url())->assertForbidden();
        $request = Request::create($this->url());
        $request->setUserResolver(fn () => $inactive);
        $this->expectException(HttpException::class);
        app(EmployeePerformanceReportService::class)->report($request);
    }

    public function test_owner_with_paid_automation_withheld_can_compare_but_inactive_role_or_label_cannot(): void
    {
        $owner = $this->employee('Scoped Owner');
        $role = AdminRole::where('key', 'owner')->firstOrFail();
        $role->permissions()->detach(Permission::whereIn('key', ['order_automation.activate', 'order_automation.execute', 'studio.covers.generate'])->pluck('id'));
        $owner->adminRoles()->attach($role);
        $r = $this->actingAs($owner->fresh())->get($this->url())->assertOk()->viewData('report');
        $this->assertTrue($r['canCompare']);
        $role->update(['is_active' => false]);
        $r = $this->actingAs($owner->fresh())->get($this->url())->assertOk()->viewData('report');
        $this->assertFalse($r['canCompare']);
    }

    public function test_current_assignment_and_mixed_checkout_statuses_are_respected(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $first = $this->employee('Old Assignee');
        $second = $this->employee('New Assignee');
        $this->shipment($first, 'MIXED', 'delivered');
        $this->shipment($second, 'MIXED', 'shipped');
        $this->shipment($second, 'NOT-COMPLETE', 'shipped');
        $this->shipment($second, 'NOT-COMPLETE', 'pending');
        $r = $this->actingAs($owner)->get($this->url())->assertOk()->viewData('report');
        $this->assertSame(1, $r['summary']['shipments']);
        $this->assertSame(0, $r['summary']['delivered']);
        $this->assertSame(1, $r['summary']['in_transit']);
        $this->assertSame(0, $r['teams']->firstWhere('id', (string) $first->id)['shipments']);
        $this->assertSame(1, $r['teams']->firstWhere('id', (string) $second->id)['shipments']);
    }

    public function test_filters_are_validated_and_empty_employee_has_zero_filled_days(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $this->actingAs($owner);
        foreach ([['employees' => 'all'], ['employees' => [999999]], ['employees' => [$owner->id, $owner->id]],
            ['from' => 'bad'], ['from' => '2026-10-31', 'to' => '2026-10-01'], ['from' => '2024-01-01', 'to' => '2026-10-31']] as $invalid) {
            $this->getJson($this->url($invalid))->assertUnprocessable();
        }
        $empty = $this->employee('Empty Employee');
        $r = $this->actingAs($empty)->get($this->url())->assertOk()->assertSee('لا توجد شحنات')->viewData('report');
        $this->assertSame(0, $r['summary']['shipments']);
        $this->assertNull($r['teams']->first()['delivery_rate']);
        $this->assertCount(2, $r['daily']);
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-30 22:30:00', 'UTC'));
        $default = $this->get(route('admin.employee-performance.index'))->assertOk()->viewData('report');
        $this->assertSame('2026-10-01', $default['from']);
        $this->assertSame('2026-10-31', $default['to']);
    }
}
