<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_acquires_group_once_and_name_appears_in_order_list_and_details(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'مسؤول هيروكد']);
        [$first, $second] = $this->group();

        $this->actingAs($admin)
            ->post(route('admin.orders.groups.assignment.acquire', $first))
            ->assertRedirect()
            ->assertSessionHas('success');

        $assignment = OrderGroupAssignment::query()->firstOrFail();
        $this->assertSame('ASSIGN-GROUP', $assignment->checkout_group_key);
        $this->assertSame($admin->id, $assignment->assigned_to_user_id);
        $this->assertSame(1, OrderGroupAssignment::query()->count());

        $this->actingAs($admin)->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('مسؤول هيروكد')
            ->assertSee('(أنت)')
            ->assertSee('ترك الطلب');

        $this->actingAs($admin)->get(route('admin.orders.show', $second))
            ->assertOk()
            ->assertSee('مسؤول تنفيذ عملية الشراء')
            ->assertSee('مسؤول هيروكد');

        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'order.assignment_acquired',
            'subject_id' => $first->id,
        ]);
    }

    public function test_second_admin_cannot_acquire_an_assigned_order_without_manager_takeover(): void
    {
        $firstAdmin = User::factory()->create(['role' => 'admin', 'name' => 'الأول']);
        $secondAdmin = User::factory()->create(['role' => 'admin', 'name' => 'الثاني']);
        [$order] = $this->group();

        $this->actingAs($firstAdmin)->post(route('admin.orders.groups.assignment.acquire', $order));
        $this->actingAs($secondAdmin)
            ->post(route('admin.orders.groups.assignment.acquire', $order))
            ->assertSessionHasErrors('assignment');

        $this->assertSame($firstAdmin->id, OrderGroupAssignment::query()->value('assigned_to_user_id'));
    }

    public function test_admin_can_release_own_order_and_manager_can_take_over_another_assignment(): void
    {
        $firstAdmin = User::factory()->create(['role' => 'admin', 'name' => 'الأول']);
        $manager = User::factory()->create(['role' => 'admin', 'name' => 'المشرف']);
        [$order] = $this->group();

        $this->actingAs($firstAdmin)->post(route('admin.orders.groups.assignment.acquire', $order));
        $this->actingAs($manager)
            ->post(route('admin.orders.groups.assignment.takeover', $order))
            ->assertSessionHas('success');
        $this->assertSame($manager->id, OrderGroupAssignment::query()->value('assigned_to_user_id'));

        $this->actingAs($manager)
            ->delete(route('admin.orders.groups.assignment.release', $order))
            ->assertSessionHas('success');
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertSame(1, AdminActivityLog::query()->where('action', 'order.assignment_taken_over')->count());
        $this->assertSame(1, AdminActivityLog::query()->where('action', 'order.assignment_released')->count());
    }

    public function test_assignment_filters_return_mine_assigned_and_unassigned_checkouts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$assigned] = $this->group();
        $unassigned = $this->order('OTHER-GROUP', 'OTHER-ORDER');
        $this->actingAs($admin)->post(route('admin.orders.groups.assignment.acquire', $assigned));

        $mine = $this->actingAs($admin)->get(route('admin.orders.index', ['assignment' => 'mine']))->viewData('groups');
        $this->assertSame(['ASSIGN-GROUP'], collect($mine->items())->pluck('key')->all());

        $unassignedGroups = $this->actingAs($admin)->get(route('admin.orders.index', ['assignment' => 'unassigned']))->viewData('groups');
        $this->assertSame([$unassigned->checkout_group_key], collect($unassignedGroups->items())->pluck('key')->all());

        $assignedGroups = $this->actingAs($admin)->get(route('admin.orders.index', ['assignment' => 'assigned']))->viewData('groups');
        $this->assertSame(['ASSIGN-GROUP'], collect($assignedGroups->items())->pluck('key')->all());
    }

    public function test_assignment_routes_require_their_permissions(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $limited->permissions()->sync(Permission::query()->where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');
        [$order] = $this->group();

        $this->actingAs($limited)
            ->post(route('admin.orders.groups.assignment.acquire', $order))
            ->assertForbidden();
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    private function group(): array
    {
        return [
            $this->order('ASSIGN-GROUP', 'ASSIGN-1'),
            $this->order('ASSIGN-GROUP', 'ASSIGN-2'),
        ];
    }

    private function order(string $group, string $number): Order
    {
        $story = Story::create([
            'title' => 'قصة '.$number,
            'slug' => strtolower($number).'-'.uniqid(),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);

        $order = Order::create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'parent_name' => 'ولي الأمر',
            'story_id' => $story->id,
            'child_name' => 'طفل',
            'child_age' => 7,
            'child_gender' => 'boy',
            'language' => 'ar',
            'delivery_details' => ['checkout_group' => $group, 'phone' => '01000000000', 'delivery_fee' => 0],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $order->items()->create([
            'item_type' => 'story',
            'story_id' => $story->id,
            'title' => $story->title,
            'unit_price_cents' => 34_900,
            'quantity' => 1,
            'total_price_cents' => 34_900,
        ]);

        return $order;
    }
}
