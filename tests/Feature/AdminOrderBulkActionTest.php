<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\OrderBulkActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderBulkActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_orders_index_exposes_checkout_level_bulk_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order('BULK-INDEX', 'BULK-INDEX-1');

        $response = $this->actingAs($admin)
            ->get(route('admin.orders.index'));

        $response
            ->assertOk()
            ->assertSee('إجراءات جماعية')
            ->assertSee('data-order-bulk-actions', false)
            ->assertSee('data-order-bulk-actions-form', false)
            ->assertSee('form="order-bulk-actions"', false)
            ->assertSee('data-bulk-select-all', false)
            ->assertSee('value="'.$order->id.'"', false);

        $html = $response->getContent();
        $advancedStart = strpos($html, 'data-advanced-order-filters');
        $advancedEnd = strpos($html, '</details>', $advancedStart);
        $bulkPanel = strpos($html, 'data-order-bulk-actions>', $advancedStart);

        $this->assertNotFalse($advancedStart);
        $this->assertNotFalse($advancedEnd);
        $this->assertNotFalse($bulkPanel);
        $this->assertGreaterThan($advancedStart, $bulkPanel);
        $this->assertLessThan($advancedEnd, $bulkPanel);
    }

    public function test_admin_can_change_status_for_multiple_checkout_groups(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $first = $this->order('BULK-FIRST', 'BULK-FIRST-1');
        $firstSibling = $this->order('BULK-FIRST', 'BULK-FIRST-2');
        $second = $this->order('BULK-SECOND', 'BULK-SECOND-1');
        $untouched = $this->order('BULK-UNTOUCHED', 'BULK-UNTOUCHED-1');

        $this->actingAs($admin)
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_UPDATE_STATUS,
                'representative_ids' => [$first->id, $second->id],
                'status' => 'generating',
                'admin_notes' => 'بدء الإنتاج جماعيًا',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('generating', $first->fresh()->status);
        $this->assertSame('generating', $firstSibling->fresh()->status);
        $this->assertSame('generating', $second->fresh()->status);
        $this->assertSame('new', $untouched->fresh()->status);
        $this->assertDatabaseHas('order_status_logs', [
            'order_id' => $firstSibling->id,
            'status' => 'generating',
            'notes' => 'بدء الإنتاج جماعيًا',
        ]);
        $this->assertDatabaseHas('admin_activity_logs', [
            'action' => 'orders.bulk_action_completed',
            'user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_release_other_users_assignments_without_changing_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'admin']);
        $first = $this->order('BULK-RELEASE-FIRST', 'BULK-RELEASE-FIRST-1');
        $second = $this->order('BULK-RELEASE-SECOND', 'BULK-RELEASE-SECOND-1');
        $this->assign($first, $assignee);
        $this->assign($second, $assignee);

        $this->actingAs($admin)
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_RELEASE_ASSIGNMENTS,
                'representative_ids' => [$first->id, $second->id],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertSame('new', $first->fresh()->status);
        $this->assertSame('new', $second->fresh()->status);
        $this->assertSame(2, AdminActivityLog::query()->where('action', 'order.assignment_released')->count());
    }

    public function test_admin_can_change_status_and_release_assignments_in_one_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'admin']);
        $first = $this->order('BULK-COMBINED-FIRST', 'BULK-COMBINED-FIRST-1');
        $second = $this->order('BULK-COMBINED-SECOND', 'BULK-COMBINED-SECOND-1');
        $this->assign($first, $assignee);
        $this->assign($second, $assignee);

        $this->actingAs($admin)
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_UPDATE_STATUS_AND_RELEASE,
                'representative_ids' => [$first->id, $second->id],
                'status' => 'under_review',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('under_review', $first->fresh()->status);
        $this->assertSame('under_review', $second->fresh()->status);
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_bulk_actions_enforce_the_specific_status_and_assignment_permissions(): void
    {
        $limited = User::factory()->create(['role' => 'admin']);
        $assignee = User::factory()->create(['role' => 'admin']);
        $order = $this->order('BULK-LIMITED', 'BULK-LIMITED-1');
        $this->assign($order, $assignee);

        $limited->permissions()->sync(Permission::query()->where('key', 'orders.view')->pluck('id'));
        $limited->unsetRelation('permissions');

        $this->actingAs($limited)
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_UPDATE_STATUS,
                'representative_ids' => [$order->id],
                'status' => 'generating',
            ])
            ->assertForbidden();

        $this->actingAs($limited)
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_RELEASE_ASSIGNMENTS,
                'representative_ids' => [$order->id],
            ])
            ->assertForbidden();

        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseHas('order_group_assignments', [
            'checkout_group_key' => $order->checkoutGroupKey(),
            'assigned_to_user_id' => $assignee->id,
        ]);
    }

    public function test_status_change_requires_a_target_status_and_at_least_one_order(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order('BULK-VALIDATION', 'BULK-VALIDATION-1');

        $this->actingAs($admin)
            ->from(route('admin.orders.index'))
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_UPDATE_STATUS,
                'representative_ids' => [$order->id],
            ])
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHasErrors('status');

        $this->actingAs($admin)
            ->from(route('admin.orders.index'))
            ->post(route('admin.orders.bulk-actions'), [
                'action' => OrderBulkActionService::ACTION_RELEASE_ASSIGNMENTS,
                'representative_ids' => [],
            ])
            ->assertRedirect(route('admin.orders.index'))
            ->assertSessionHasErrors('representative_ids');

        $this->assertSame('new', $order->fresh()->status);
    }

    private function assign(Order $order, User $assignee): void
    {
        OrderGroupAssignment::query()->create([
            'checkout_group_key' => $order->checkoutGroupKey(),
            'assigned_to_user_id' => $assignee->id,
            'assigned_by_user_id' => $assignee->id,
            'assigned_at' => now(),
        ]);
    }

    private function order(string $group, string $number): Order
    {
        $story = Story::query()->create([
            'title' => 'قصة '.$number,
            'slug' => strtolower($number).'-'.uniqid(),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);

        $order = Order::query()->create([
            'order_number' => $number,
            'checkout_group_key' => $group,
            'parent_name' => 'ولي الأمر',
            'story_id' => $story->id,
            'child_name' => 'طفل',
            'child_age' => 7,
            'child_gender' => 'boy',
            'language' => 'ar',
            'delivery_details' => [
                'checkout_group' => $group,
                'phone' => '01000000000',
                'delivery_fee' => 0,
            ],
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
