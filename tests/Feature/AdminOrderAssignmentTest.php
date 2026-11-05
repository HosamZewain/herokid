<?php

namespace Tests\Feature;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\AutomationAssignment;
use App\Services\Orders\OrderAutomationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminOrderAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function assignAs(User $actor, Order $order, ?User $target, int $version = 0, ?int $previous = null)
    {
        return $this->actingAs($actor)->post(route('admin.orders.workspace.assign'), ['target_id' => $target?->id, 'reason' => 'synthetic change', 'orders' => [['id' => $order->id, 'assignee' => $previous, 'version' => $version]]]);
    }

    public function test_assignment_during_automation_is_independent_and_conflicting_version_is_rejected(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'admin', 'name' => 'Assigned Employee']);
        [$order,$sibling] = $this->group();
        RoboDeskIntegrationEvent::create(['event_id' => 'synthetic-auto', 'direction' => 'outbound', 'event_type' => 'order.pending_confirmation', 'checkout_group_key' => $order->checkoutGroupKey(), 'order_id' => $order->id, 'status' => 'processing', 'payload' => ['confirmation' => ['eligible_at_creation' => true]]]);
        $this->assignAs($manager, $order, $employee)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(app(AutomationAssignment::class)->owns($sibling));
        $this->assignAs($manager, $order, $manager)->assertStatus(409);
        $this->assertSame($employee->id, OrderGroupAssignment::first()->assigned_to_user_id);
        $this->get(route('admin.orders.index', ['assignment' => 'user:'.$employee->id]))->assertOk()->assertSee('Assigned Employee')->assertSee('جارٍ الإرسال');
        if (getenv('HK_WORKSPACE_RENDER')) {
            file_put_contents('/app/storage/framework/testing/workspace-preview.html', $this->get(route('admin.orders.index'))->getContent());
        }
        $this->assignAs($manager, $order, null, 1, $employee->id)->assertRedirect();
        $this->assignAs($manager, $order, $manager, 0)->assertStatus(409); // ABA after removal
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'order.assignment_changed', 'subject_id' => $order->id]);
    }

    public function test_employee_cannot_self_assign_and_agent_accounts_are_not_assignment_targets(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'admin']);
        $employee->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.assign'])->pluck('id'));
        $employee->unsetRelation('permissions');
        [$order] = $this->group();
        $this->assignAs($employee, $order, $employee)->assertForbidden();
        $this->actingAs($employee)->post(route('admin.orders.groups.assignment.acquire', $order))->assertForbidden();
        $agent = User::factory()->create(['role' => 'admin', 'agent_api_enabled' => true]);
        $this->assignAs($manager, $order, $agent)->assertStatus(422);
        $this->assignAs($agent, $order, $manager)->assertForbidden();
        $inactive = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $this->assignAs($manager, $order, $inactive)->assertStatus(422);
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_bulk_assignment_is_atomic_on_conflict_and_covers_whole_checkout(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'admin']);
        [$first,$sibling] = $this->group();
        $second = $this->order('SECOND', 'SECOND');
        $payload = ['target_id' => $target->id, 'reason' => 'distribution', 'orders' => [['id' => $first->id, 'version' => 0], ['id' => $second->id, 'version' => 9]]];
        $this->actingAs($manager)->post(route('admin.orders.workspace.assign'), $payload)->assertStatus(409);
        $this->assertDatabaseCount('order_group_assignments', 0);
        $payload['orders'][1]['version'] = 0;
        $this->post(route('admin.orders.workspace.assign'), $payload)->assertRedirect();
        $this->assertDatabaseCount('order_group_assignments', 2);
        $this->assertSame($target->id, $sibling->fresh()->groupAssignment->assigned_to_user_id);
    }

    public function test_default_all_and_assigned_only_protect_lists_counts_export_links_media_and_bulk(): void
    {
        Http::preventStrayRequests();
        $manager = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'admin']);
        $employee->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.statistics.view', 'orders.photos.view', 'orders.delete'])->pluck('id'));
        $employee->unsetRelation('permissions');
        [$mine,$sibling] = $this->group();
        $foreign = $this->order('SECRET-GROUP', 'SECRET-ORDER');
        $this->assignAs($manager, $mine, $employee)->assertRedirect();
        $this->actingAs($employee)->get(route('admin.orders.index'))->assertOk()->assertViewHas('groups', fn ($g) => $g->total() === 2);
        $this->actingAs($manager)->post(route('admin.orders.workspace.visibility'), ['mode' => 'assigned', 'expected' => 'all'])->assertRedirect();
        $this->actingAs($employee)->get(route('admin.orders.index'))->assertOk()->assertDontSee('SECRET-ORDER')->assertViewHas('groups', fn ($g) => $g->total() === 1)->assertViewHas('workspaceCounts', fn ($c) => $c['all'] === 1);
        $this->get(route('admin.orders.index', ['q' => 'SECRET-ORDER']))->assertOk()->assertViewHas('groups', fn ($g) => $g->total() === 0);
        $this->get(route('admin.orders.groups.show', $foreign))->assertNotFound();
        foreach (['workflow', 'messages'] as $panel) {
            $this->getJson(route('admin.orders.groups.list-panel', [$mine, $panel]))->assertOk();
            $this->getJson(route('admin.orders.groups.list-panel', [$foreign, $panel]))->assertNotFound();
        }
        $this->patch(route('admin.orders.update', $foreign), ['status' => 'new_confirmed'])->assertNotFound();
        $this->get(route('admin.orders.photo', [$foreign, 0]))->assertNotFound();
        $attachment = OrderAttachment::create(['order_id' => $foreign->id, 'disk' => 's3_private', 'path' => 'synthetic/secret.png', 'original_name' => 'secret.png', 'mime_type' => 'image/png', 'size' => 10]);
        $this->get(route('admin.orders.attachments.show', $attachment))->assertNotFound();
        $this->post(route('admin.orders.bulk-actions'), ['action' => 'update_status', 'status' => 'new_confirmed', 'representative_ids' => [$mine->id, $foreign->id]])->assertNotFound();
        $csv = $this->get(route('admin.orders.export'))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('SECRET-ORDER', $csv);
        $this->assertStringContainsString('ASSIGN-1', $csv);
        $this->getJson(route('admin.orders.workspace.progress', ['visible' => [$mine->id, $foreign->id]]))->assertOk()->assertJsonPath('revoked.0', $foreign->id)->assertJsonCount(1, 'rows');
        // Transfer while employee keeps a stale page open revokes every subsequent call.
        $this->assignAs($manager, $mine, $manager, 1, $employee->id)->assertRedirect();
        $this->actingAs($employee)->get(route('admin.orders.groups.show', $sibling))->assertNotFound();
        foreach (['workflow', 'messages'] as $panel) {
            $this->getJson(route('admin.orders.groups.list-panel', [$sibling, $panel]))->assertNotFound();
        }
        $this->getJson(route('admin.orders.workspace.progress', ['visible' => [$mine->id]]))->assertOk()->assertJsonCount(0, 'rows');
        $this->actingAs($manager)->get(route('admin.orders.index'))->assertOk()->assertViewHas('groups', fn ($g) => $g->total() === 2);
    }

    public function test_pause_resume_preserve_employee_and_require_control_of_own_order(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'admin']);
        $employee->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.automation.control'])->pluck('id'));
        $employee->unsetRelation('permissions');
        [$order] = $this->group();
        $other = $this->order('OTHER', 'OTHER');
        $this->assignAs($manager, $order, $employee)->assertRedirect();
        $this->actingAs($employee)->post(route('admin.orders.workspace.control', $other), ['action' => 'pause', 'version' => 1, 'reason' => 'manual change'])->assertForbidden();
        $this->post(route('admin.orders.workspace.control', $order), ['action' => 'pause', 'version' => 1, 'reason' => 'manual change'])->assertRedirect();
        $this->assertDatabaseHas('order_automation_controls', ['checkout_key' => $order->checkoutGroupKey(), 'state' => 'paused']);
        $this->assertSame($employee->id, OrderGroupAssignment::first()->assigned_to_user_id);
        $this->post(route('admin.orders.workspace.control', $order), ['action' => 'resume', 'version' => 1])->assertStatus(409);
        $version = DB::table('order_automation_controls')->where('checkout_key', $order->checkoutGroupKey())->value('version');
        $this->post(route('admin.orders.workspace.control', $order), ['action' => 'resume', 'version' => $version])->assertRedirect();
        $this->assertDatabaseHas('order_automation_controls', ['checkout_key' => $order->checkoutGroupKey(), 'state' => 'running']);
        $this->assertSame($employee->id, OrderGroupAssignment::first()->assigned_to_user_id);
    }

    public function test_mixed_summary_keeps_failure_and_parallel_progress_with_batched_queries(): void
    {
        [$order, $sibling] = $this->group();
        $base = ['checkout_key' => $order->checkoutGroupKey(), 'previous_status' => 'new_confirmed', 'fingerprint' => str_repeat('a', 64), 'snapshot' => [], 'scene_count' => 13, 'production_kind' => 'story'];
        CodexSceneRun::create($base + ['id' => (string) Str::uuid(), 'order_id' => $order->id, 'status' => 'needs_manual', 'error_code' => 'partial_scenes', 'scenes' => [1 => ['path' => 'synthetic.png', 'sha256' => str_repeat('b', 64)]]]);
        CodexSceneRun::create($base + ['id' => (string) Str::uuid(), 'order_id' => $sibling->id, 'status' => 'running', 'scenes' => [1 => ['path' => 'other.png', 'sha256' => str_repeat('c', 64)]]]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $single = (new OrderAutomationSummary)->forKeys([$order->checkoutGroupKey()]);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        $summary = $single[$order->checkoutGroupKey()];
        $this->assertSame('intervention', $summary['state']);
        $this->assertTrue($summary['running']);
        $this->assertCount(2, collect($summary['parts'])->where('stage', 'story_pages'));
        $keys = [$order->checkoutGroupKey()];
        for ($i = 0; $i < 12; $i++) {
            $keys[] = $this->order('BATCH-'.$i, 'BATCH-'.$i)->checkoutGroupKey();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        (new OrderAutomationSummary)->forKeys($keys);
        $this->assertSame($count, count(DB::getQueryLog()), 'Summary query count must not grow per checkout within a batch.');
        DB::disableQueryLog();
    }

    public function test_polling_preserves_filtered_membership_counts_and_first_live_representative_without_hydrating_cards(): void
    {
        $manager = User::factory()->create(['role' => 'admin']);
        [$first, $later] = $this->group();
        $first->updateQuietly(['status' => 'preview_uploaded']);
        $other = $this->order('OTHER', 'OTHER');
        $archived = $this->order('ARCHIVED', 'ARCHIVED');
        $archived->delete();
        $this->actingAs($manager);
        foreach ([[], ['status' => ['new']], ['q' => 'ASSIGN-2'], ['workspace' => 'team'],
            ['workspace' => 'attention'], ['automation_state' => 'waiting'], ['view' => 'all', 'q' => 'ARCHIVED'], ['view' => 'trash']] as $filters) {
            $index = $this->get(route('admin.orders.index', $filters))->assertOk();
            $expected = $index->viewData('groups')->getCollection()->pluck('representative_id')->values()->all();
            DB::enableQueryLog();
            DB::flushQueryLog();
            $poll = $this->getJson(route('admin.orders.workspace.progress', $filters + ['visible' => [$first->id, $other->id]]))->assertOk();
            $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
            DB::disableQueryLog();
            $poll->assertJsonPath('ids', $expected)->assertJsonPath('total', $index->viewData('groups')->total())
                ->assertJsonPath('counts', $index->viewData('workspaceCounts'));
            $this->assertStringNotContainsString('production_prompt_template', $queries);
            $this->assertStringNotContainsString('full_story', $queries);
            $this->assertStringNotContainsString('marketing_carts', $queries);
        }
        $ids = $this->getJson(route('admin.orders.workspace.progress', ['status' => ['new']]))->json('ids');
        $this->assertContains($first->id, $ids);
        $this->assertNotContains($later->id, $ids);
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
