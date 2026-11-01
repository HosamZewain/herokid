<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRun;
use App\Models\OrderProcessSimulation;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessSimulator;
use App\Services\OrderAutomation\WorkflowDefinition;
use App\Services\OrderAutomation\WorkflowRunner;
use App\Services\OrderAutomation\WorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderProcessBuilderTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private OrderAutomation $workflow;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->manager = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->manager->permissions()->sync(Permission::whereIn('key', ['order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute', 'orders.view', 'orders.photos.view'])->pluck('id'));
        $this->actingAs($this->manager);
        $this->workflow = OrderAutomation::create(['name' => 'تأكيد تجريبي', 'draft' => ProcessDefinition::defaults('confirmation')]);
        $this->workflow->refresh();
    }

    private function publish(?array $definition = null): void
    {
        if ($definition) {
            $this->workflow->update(['draft' => $definition]);
        }
        $this->post(route('admin.order-automation.process.publish', $this->workflow), ['confirmed' => true, 'draft_version' => $this->workflow->draft_version])->assertSessionHasNoErrors()->assertRedirect();
        $this->workflow->refresh();
    }

    private function fixture(array $overrides = []): array
    {
        return array_replace(['event' => 'checkout_created', 'status' => 'new', 'items' => [['type' => 'story', 'catalog_id' => 1]]], $overrides);
    }

    private function start(array $overrides = [], ?string $key = null): OrderProcessSimulation
    {
        return app(ProcessSimulator::class)->start($this->workflow, $this->manager, $key ?? (string) Str::uuid(), $this->fixture($overrides));
    }

    public function test_manager_builds_versions_and_opens_a_synthetic_run_without_customer_data(): void
    {
        $this->post(route('admin.order-automation.process.create'), ['name' => 'مسار جديد', 'preset' => 'blank'])->assertRedirect();
        $this->assertDatabaseHas('order_automations', ['name' => 'مسار جديد', 'enabled' => false]);
        $this->get(route('admin.order-automation.process.edit', $this->workflow))->assertOk()->assertSee('بيئة بناء ومحاكاة')->assertDontSee('تشغيل مسار يولّد الهوية');
        $d = $this->workflow->draft;
        $d['nodes'][0]['label'] = 'قالب تأكيد';
        $this->put(route('admin.order-automation.process.save', $this->workflow), ['name' => 'مسار تأكيد', 'draft_version' => 1, 'definition_json' => json_encode($d)])->assertSessionHasNoErrors()->assertRedirect();
        $this->workflow->refresh();
        $this->assertSame(2, $this->workflow->draft_version);
        $this->publish();
        $this->post(route('admin.order-automation.process.simulate', $this->workflow), ['request_id' => (string) Str::uuid(), 'fixture' => $this->fixture()])->assertRedirect();
        $run = OrderProcessSimulation::firstOrFail();
        $this->get(route('admin.order-automation.process.simulation', $run))->assertOk()->assertSee('تأكيد الطلب')->assertSee('محاكاة انتهاء المهلة');
        $this->get(route('admin.order-automation.index'))->assertOk()->assertSee('مسار تأكيد')->assertSee('تشغيله ولا يعيد إرسال');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('robodesk_integration_events', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_index_distinguishes_general_live_trial_paused_and_draft_states(): void
    {
        $this->publish();
        $this->workflow->update(['enabled' => true]);
        config(['order_process.enabled' => true, 'order_process.after_order_id' => 0, 'order_process.enrollment_mode' => 'all_new']);
        $this->get(route('admin.order-automation.index'))->assertOk()->assertSee('مفعّل تلقائيًا')->assertSee('تشغيلات فعلية: 0')->assertDontSee('نسخة للمحاكاة');
        config(['order_process.enrollment_mode' => 'trial', 'order_process.trial_order_id' => 1, 'order_process.trial_automation_id' => $this->workflow->id]);
        $this->get(route('admin.order-automation.index'))->assertOk()->assertSee('مفعّل للتجربة المحددة');
        config(['order_process.enabled' => false]);
        $this->get(route('admin.order-automation.index'))->assertOk()->assertSee('التشغيل متوقف من الإعدادات')->assertDontSee('مفعّل تلقائيًا');
        $this->workflow->update(['enabled' => false]);
        $this->get(route('admin.order-automation.index'))->assertOk()->assertSee('غير مفعّل');
        Http::assertNothingSent();
    }

    public function test_confirmation_waits_durably_then_completes_once_without_touching_real_orders(): void
    {
        $order = Order::create(['order_number' => 'DO-NOT-TOUCH', 'status' => 'new']);
        $before = $order->fresh()->getAttributes();
        $payments = DB::table('order_payment_events')->count();
        $this->publish();
        $run = $this->start();
        $this->assertSame('waiting_response', $run->status);
        $this->assertCount(2, $run->trace);
        $this->assertSame('450', $run->trace[0]['variables'][3]);
        $this->assertNotNull($run->fresh()->wait_until);
        $run = app(ProcessSimulator::class)->respond($run->fresh(), 1, 'confirm');
        $this->assertSame('completed', $run->status);
        $this->assertSame('new_confirmed', $run->context['status']);
        $this->postJson(route('admin.order-automation.process.respond', $run), ['version' => 1, 'decision' => 'confirm'])->assertConflict();
        $this->assertCount(5, $run->fresh()->trace);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($payments, DB::table('order_payment_events')->count());
        $this->assertDatabaseCount('order_status_logs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[DataProvider('reviewDecisions')]
    public function test_rejection_and_timeout_route_to_review_without_cancelling_the_order(string $decision): void
    {
        $this->publish();
        $run = app(ProcessSimulator::class)->respond($this->start(), 1, $decision);
        $this->assertSame('waiting_staff', $run->status);
        $this->assertSame('new', $run->context['status']);
        $run = app(ProcessSimulator::class)->respond($run, 2, '__continue');
        $this->assertSame('completed', $run->status);
    }

    public static function reviewDecisions(): array
    {
        return [['reject'], ['__timeout']];
    }

    public function test_stop_and_invalid_decisions_do_not_advance(): void
    {
        $this->publish();
        $run = $this->start();
        $url = route('admin.order-automation.process.respond', $run);
        $this->postJson($url, ['version' => 1, 'decision' => 'fake'])->assertUnprocessable();
        $this->assertSame(1, $run->fresh()->version);
        $this->post($url, ['version' => 1, 'decision' => '__stop'])->assertRedirect();
        $this->assertSame('cancelled', $run->fresh()->status);
        $this->postJson($url, ['version' => 2, 'decision' => 'confirm'])->assertUnprocessable();
    }

    public function test_repeated_request_is_deduplicated_and_changed_fixture_is_rejected(): void
    {
        $this->publish();
        $key = (string) Str::uuid();
        $a = $this->start([], $key);
        $b = $this->start([], $key);
        $this->assertSame($a->id, $b->id);
        $this->assertDatabaseCount('order_process_simulations', 1);
        $this->postJson(route('admin.order-automation.process.simulate', $this->workflow), ['request_id' => $key, 'fixture' => $this->fixture(['status' => 'new_confirmed'])])->assertConflict();
    }

    public function test_waiting_run_retains_its_revision_after_new_publication(): void
    {
        $this->publish();
        $run = $this->start();
        $oldRevision = $run->revision_id;
        $new = ProcessDefinition::defaults();
        $this->publish($new);
        $this->assertNotEquals($oldRevision, $this->workflow->revision_id);
        $run = app(ProcessSimulator::class)->respond($run, 1, 'confirm');
        $this->assertSame('new_confirmed', $run->context['status']);
        $this->assertSame($oldRevision, $run->revision_id);
    }

    public function test_catalog_conditions_and_status_transition_are_enforced_on_mixed_cart(): void
    {
        $story = Story::create(['title' => 'قصة وهمية', 'slug' => 'process-synthetic', 'language' => 'ar', 'gender' => 'both', 'price' => 1, 'active' => true]);
        $d = ProcessDefinition::defaults();
        $d['scope'] = 'story';
        $d['trigger'] = 'status_entered';
        $d['conditions'] = ['type' => 'story', 'status' => 'new_confirmed', 'story_ids' => [$story->id], 'product_ids' => []];
        $this->publish($d);
        $fixture = ['event' => 'status_entered', 'status' => 'new_confirmed', 'previous_status' => 'new', 'items' => [['type' => 'story', 'catalog_id' => $story->id], ['type' => 'product', 'catalog_id' => 900], ['type' => 'story', 'catalog_id' => $story->id + 100]]];
        $run = $this->start($fixture);
        $this->assertSame('completed', $run->status);
        $this->assertCount(1, $run->context['matched_items']);
        $this->assertSame('story', $run->context['type']);
        $this->assertSame('not_matched', $this->start(array_replace($fixture, ['previous_status' => 'new_confirmed']))->status);
        $this->assertSame('not_matched', $this->start(array_replace($fixture, ['previous_status' => null]))->status);
        $this->assertSame('not_matched', $this->start(array_replace($fixture, ['event' => 'checkout_created']))->status);
        $this->assertSame('not_matched', $this->start(array_replace($fixture, ['items' => [['type' => 'product', 'catalog_id' => 900]]]))->status);
    }

    public function test_product_picker_uses_catalog_names_and_filters_selected_products(): void
    {
        $product = Product::create(['name_ar' => 'بازل تجريبي', 'slug' => 'process-puzzle', 'price_cents' => 100]);
        $d = ProcessDefinition::defaults();
        $d['scope'] = 'product';
        $d['conditions'] = ['type' => 'product', 'status' => 'new', 'story_ids' => [], 'product_ids' => [$product->id]];
        $this->publish($d);
        $this->get(route('admin.order-automation.process.edit', $this->workflow))->assertOk()->assertSee('بازل تجريبي');
        $this->assertSame('completed', $this->start(['items' => [['type' => 'product', 'catalog_id' => $product->id]]])->status);
        $this->assertSame('not_matched', $this->start(['items' => [['type' => 'product', 'catalog_id' => $product->id + 1]]])->status);
        $this->assertSame('not_matched', $this->start(['items' => [['type' => 'story', 'catalog_id' => $product->id]]])->status);
    }

    public function test_conditions_branch_and_expected_state_conflicts_block(): void
    {
        $d = ProcessDefinition::defaults();
        $d['nodes'] = [
            ['id' => 'check', 'type' => 'condition', 'label' => 'نوع', 'config' => ['field' => 'type', 'value' => 'story', 'yes' => 'change', 'no' => 'finish']],
            ['id' => 'change', 'type' => 'change_status', 'label' => 'حالة', 'config' => ['from' => 'new_confirmed', 'to' => 'new'], 'next' => 'finish'],
            ['id' => 'finish', 'type' => 'end', 'label' => 'نهاية', 'config' => []],
        ];
        $this->publish($d);
        $run = $this->start();
        $this->assertSame('blocked', $run->status);
        $this->assertSame('state_conflict', $run->trace[1]['result']);
        $product = $this->start(['items' => [['type' => 'product', 'catalog_id' => 1]]]);
        $this->assertSame('completed', $product->status);
        $this->assertSame('no', $product->trace[0]['branch']);
    }

    #[DataProvider('invalidGraphs')]
    public function test_invalid_graphs_are_rejected(string $mutation): void
    {
        $d = ProcessDefinition::defaults('confirmation');
        switch ($mutation) {
            case 'cycle': $d['nodes'][2]['next'] = 'send';
                break;
            case 'dangling': $d['nodes'][2]['next'] = 'missing';
                break;
            case 'duplicate': $d['nodes'][2]['id'] = 'send';
                break;
            case 'unreachable': $d['nodes'][] = ['id' => 'orphan', 'type' => 'end', 'label' => 'Orphan', 'config' => []];
                break;
            case 'unknown_type': $d['nodes'][0]['type'] = 'shell';
                break;
            case 'unknown_config': $d['nodes'][0]['config']['url'] = 'https://example.test';
                break;
            case 'bad_scope': $d['scope'] = 'product';
                break;
            case 'repeated_reply': $d['nodes'][1]['config']['cases'][1]['text'] = ' تأكيد الطلب ';
                break;
            case 'missing_send': $d['nodes'][1]['config']['message_node'] = 'confirmed';
                break;
            case 'bad_variables': $d['nodes'][0]['config']['variables'][3] = 'customer_name';
                break;
            case 'bad_timeout': $d['nodes'][1]['config']['timeout_hours'] = 0;
                break;
            case 'bad_status': $d['conditions']['status'] = 'imaginary';
                break;
            case 'bad_template': $d['nodes'][0]['config']['template'] = '<script>';
                break;
        }
        $this->expectException(ValidationException::class);
        app(ProcessDefinition::class)->validate($d);
    }

    public static function invalidGraphs(): array
    {
        return array_map(fn ($name) => [$name], ['cycle', 'dangling', 'duplicate', 'unreachable', 'unknown_type', 'unknown_config', 'bad_scope', 'repeated_reply', 'missing_send', 'bad_variables', 'bad_timeout', 'bad_status', 'bad_template']);
    }

    public function test_send_must_dominate_wait_across_every_branch(): void
    {
        $d = ProcessDefinition::defaults('confirmation');
        array_unshift($d['nodes'], ['id' => 'start', 'type' => 'condition', 'label' => 'Check', 'config' => ['field' => 'type', 'value' => 'story', 'yes' => 'send', 'no' => 'response']]);
        $this->expectException(ValidationException::class);
        app(ProcessDefinition::class)->validate($d);
    }

    public function test_stale_edits_and_cross_schema_saves_cannot_overwrite_a_process(): void
    {
        $url = route('admin.order-automation.process.save', $this->workflow);
        $this->putJson($url, ['name' => 'bad', 'draft_version' => 99, 'definition_json' => json_encode($this->workflow->draft)])->assertConflict();
        $this->putJson($url, ['name' => 'bad', 'draft_version' => 1, 'definition_json' => 'null'])->assertUnprocessable();
        $this->putJson(route('admin.order-automation.save', $this->workflow), ['name' => 'bad', 'draft_version' => 1, 'definition' => WorkflowDefinition::defaults()])->assertUnprocessable();
        $this->assertSame('تأكيد تجريبي', $this->workflow->fresh()->name);
    }

    public function test_legacy_activation_runner_and_scanner_cannot_execute_schema_two_even_with_enabled_flags(): void
    {
        $this->publish();
        config(['order_automation.enabled' => true]);
        $this->postJson(route('admin.order-automation.activate', $this->workflow), ['enabled' => true, 'confirmed' => true])->assertUnprocessable();
        $this->workflow->update(['enabled' => true]);
        $order = Order::create(['order_number' => 'GUARDED', 'status' => 'new']);
        $this->postJson(route('admin.order-automation.trial', $this->workflow), ['order_id' => $order->id, 'request_id' => (string) Str::uuid(), 'mode' => 'simulate', 'confirmed' => true])->assertUnprocessable();
        $this->assertSame(0, app(WorkflowService::class)->tick());
        $run = OrderAutomationRun::create(['automation_id' => $this->workflow->id, 'revision_id' => $this->workflow->revision_id, 'order_id' => $order->id, 'request_key' => 'guard', 'status' => 'queued', 'steps' => [], 'context' => []]);
        app(WorkflowRunner::class)->advance($run->id);
        $this->assertSame('unsupported_schema', $run->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_builder_access_requires_view_and_manage_not_just_an_admin_login(): void
    {
        $this->publish();
        $run = $this->start();
        $viewer = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $viewer->permissions()->sync(Permission::where('key', 'order_automation.view')->pluck('id'));
        $this->actingAs($viewer);
        $this->get(route('admin.order-automation.index'))->assertOk()->assertDontSee('فتح المسار والتعديل');
        $this->get(route('admin.order-automation.process.edit', $this->workflow))->assertForbidden();
        $this->get(route('admin.order-automation.process.simulation', $run))->assertForbidden();
        $this->postJson(route('admin.order-automation.process.create'), ['name' => 'no', 'preset' => 'blank'])->assertForbidden();
        $this->postJson(route('admin.order-automation.process.respond', $run), ['version' => 1, 'decision' => 'confirm'])->assertForbidden();
    }

    public function test_names_and_template_text_are_escaped_in_server_rendered_pages(): void
    {
        $this->workflow->update(['name' => '<script>alert(1)</script>']);
        $this->get(route('admin.order-automation.process.edit', $this->workflow))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}
