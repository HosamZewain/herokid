<?php

namespace Tests\Feature;

use App\Jobs\AdvanceOrderProcess;
use App\Models\Order;
use App\Models\OrderAutomation;
use App\Models\OrderAutomationRevision;
use App\Models\OrderProcessRun;
use App\Models\Permission;
use App\Models\RoboDeskConversationSetting;
use App\Models\Story;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Services\OrderAutomation\Process\ProcessDefinition;
use App\Services\OrderAutomation\Process\ProcessEvents;
use App\Services\OrderAutomation\Process\ProcessLivePolicy;
use App\Services\OrderAutomation\Process\ProcessMessageRetry;
use App\Services\OrderAutomation\Process\ProcessResponsePoller;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use App\Services\RoboDesk\Conversations\OrderConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderProcessRuntimeTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private OrderAutomation $workflow;

    private Story $story;

    private bool $uncertain = false;

    private bool $sendDisabled = false;

    private string $sendWamid = 'wamid.synthetic';

    private array $historyMessages = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->startOfDay());
        Http::preventStrayRequests();
        Queue::fake();
        Cache::flush();
        config(['order_process.enabled' => true, 'order_process.after_order_id' => 0, 'order_process.confirmation_owner' => 'process',
            'robodesk.enabled' => false, 'robodesk.confirmation.enabled' => true, 'robodesk.confirmation.after_order_id' => 0,
            'robodesk.confirmation.audience' => 'all_new', 'robodesk.confirmation.contract_ready' => true, 'robodesk.confirmation_only' => true]);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actor->permissions()->sync(Permission::whereIn('key', ['order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute', 'orders.view', 'orders.update'])->pluck('id'));
        $this->actingAs($this->actor);
        $this->story = Story::create(['title' => 'قصة وهمية', 'slug' => 'runtime-synthetic', 'language' => 'ar', 'gender' => 'both', 'price' => 100, 'active' => true]);
        $this->workflow = $this->workflow(ProcessDefinition::defaults('confirmation'));
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only', 'conversation_limit' => 50]);
        Http::fake(['https://hero-kid.robodesk.ai/*' => function ($request) {
            if (str_contains($request->url(), '/messagesByPhone')) {
                return Http::response(['phone' => '201000000001', 'interactions' => [], 'messages' => $this->historyMessages]);
            }
            if (str_contains($request->url(), '/start/sendMsg')) {
                return $this->sendDisabled ? Http::response('disabled', 409) : ($this->uncertain ? Http::response('unknown', 500) : Http::response(['_id' => 'interaction-synthetic', 'channelId' => $this->sendWamid]));
            }
            throw new \RuntimeException('Unexpected external request');
        }]);
    }

    private function workflow(array $definition): OrderAutomation
    {
        $definition = app(ProcessDefinition::class)->validate($definition);
        $w = OrderAutomation::create(['name' => 'مسار وهمي', 'draft' => $definition]);
        $r = OrderAutomationRevision::create(['automation_id' => $w->id, 'definition' => $definition, 'checksum' => hash('sha256', json_encode($definition)), 'published_by' => $this->actor->id, 'created_at' => now()]);
        $w->update(['revision_id' => $r->id]);
        app(ProcessRuntime::class)->activate($w, $this->actor, true);

        return $w->fresh();
    }

    private function order(bool $finalize = true, string $status = 'new'): Order
    {
        return DB::transaction(function () use ($finalize, $status) {
            $key = 'SYNTHETIC-'.Str::uuid();
            $o = Order::create(['order_number' => 'TEST-'.Str::random(8), 'checkout_group_key' => $key, 'story_id' => $this->story->id, 'parent_name' => 'عميل وهمي',
                'status' => $status, 'uploaded_photos' => [], 'delivery_details' => ['checkout_group' => $key, 'phone' => '01000000001', 'delivery_fee' => 50]]);
            $o->items()->create(['item_type' => 'story', 'story_id' => $this->story->id, 'title' => 'قصة وهمية', 'quantity' => 1, 'unit_price_cents' => 10000, 'total_price_cents' => 10000]);
            if ($finalize) {
                app(ProcessEvents::class)->checkoutCompleted($o);
            }

            return $o;
        });
    }

    private function enroll(): OrderProcessRun
    {
        $o = $this->order();
        app(ProcessRuntime::class)->tick();

        return OrderProcessRun::where('order_id', $o->id)->where('automation_id', $this->workflow->id)->firstOrFail();
    }

    private function waiting(): OrderProcessRun
    {
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_response', $run->fresh()->status);

        return $run->fresh();
    }

    private function history(OrderProcessRun $run, array $overrides = []): WhatsAppConversation
    {
        $c = WhatsAppConversation::firstOrCreate(['account_key' => $run->account_key, 'phone_hash' => $run->phone_hash], ['phone' => '+201000000001']);
        $base = ['remote_id' => 'template', 'remote_hash' => hash('sha256', 'template'), 'fingerprint' => hash('sha256', 'template'), 'direction' => 'outbound', 'body' => 'قالب تجريبي', 'kind' => 'text', 'sent_at' => now(),
            'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'wamid.synthetic', 'replyTo' => null, 'templateName' => 'herokid_order_confirmation', 'interactionId' => 'interaction-synthetic']];
        $c->messages()->create($base);
        $this->travel(3)->seconds();
        $c->messages()->create(array_replace($base, ['remote_id' => 'inbound', 'remote_hash' => hash('sha256', 'inbound'), 'fingerprint' => hash('sha256', 'inbound'), 'direction' => 'inbound', 'body' => ' تأكيد الطلب ', 'sent_at' => now(),
            'provider_metadata' => ['contract_fields_present' => true, 'channelId' => 'wamid.inbound', 'replyTo' => 'wamid.synthetic', 'templateName' => null, 'interactionId' => 'interaction-synthetic']], $overrides));

        return $c;
    }

    public function test_process_failed_delivery_retry_retains_attempts_and_accepts_old_template_reply_once(): void
    {
        $run = $this->waiting();
        $step = $run->steps()->where('type', 'whatsapp')->sole();
        $receipt = $step->receipt + ['delivery' => ['status' => 'failed']];
        $step->update(['receipt' => $receipt]);
        $step->messageAttempts()->first()->update(['receipt' => $receipt]);
        $service = app(ProcessMessageRetry::class);
        $this->assertTrue($service->eligible($step->fresh()));
        $service->retry($run->order, $step->id, $this->actor, $run->version, 'إعادة المحاولة الفاشلة');
        $attempt = $step->messageAttempts()->latest('number')->first();
        $this->assertFalse($service->eligible($step->fresh()));
        $version = $run->fresh()->version;
        $this->sendWamid = 'wamid.second';
        app(ProcessRuntime::class)->retryMessage($run->id, $step->id, $attempt->id, $version);
        app(ProcessRuntime::class)->retryMessage($run->id, $step->id, $attempt->id, $version);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertSame('wamid.second', data_get($step->fresh()->receipt, 'message_id'));
        $this->assertSame('wamid.synthetic', data_get($step->messageAttempts()->oldest('number')->first()->receipt, 'message_id'));
        $this->assertCount(2, Http::recorded(fn ($r) => str_contains($r->url(), '/start/sendMsg')));
        $conversation = $this->history($run);
        app(ProcessResponsePoller::class)->process($conversation);
        app(ProcessResponsePoller::class)->process($conversation);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertDatabaseCount('order_process_responses', 1);
        $this->assertSame('wamid.second', data_get($step->fresh()->receipt, 'message_id'));
        $this->assertDatabaseCount('order_process_identities', 0);
    }

    public function test_process_retry_blocks_uncertain_and_rechecks_permissions_after_queueing(): void
    {
        $run = $this->waiting();
        $step = $run->steps()->where('type', 'whatsapp')->sole();
        $service = app(ProcessMessageRetry::class);
        $this->assertFalse($service->eligible($step));
        $receipt = $step->receipt + ['delivery' => ['status' => 'failed']];
        $step->update(['receipt' => $receipt]);
        $step->messageAttempts()->first()->update(['receipt' => $receipt]);
        $service->retry($run->order, $step->id, $this->actor, $run->version, 'طلب إعادة المحاولة');
        $attempt = $step->messageAttempts()->latest('number')->first();
        $this->actor->permissions()->detach();
        app(ProcessRuntime::class)->retryMessage($run->id, $step->id, $attempt->id, $run->fresh()->version);
        $this->assertSame('cancelled', $attempt->fresh()->status);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertCount(1, Http::recorded(fn ($r) => str_contains($r->url(), '/start/sendMsg')));
        $step->update(['status' => 'uncertain']);
        $run->update(['status' => 'uncertain']);
        $this->assertFalse($service->eligible($step->fresh()));
    }

    public function test_blocked_process_send_can_be_manually_retried_without_replaying_prior_steps(): void
    {
        $run = $this->enroll();
        $this->sendDisabled = true;
        app(ProcessRuntime::class)->advance($run->id);
        $run->refresh();
        $step = $run->steps()->where('type', 'whatsapp')->sole();
        $this->assertSame('blocked', $run->status);
        app(ProcessMessageRetry::class)->retry($run->order, $step->id, $this->actor, $run->version, 'القالب متاح الآن');
        $attempt = $step->messageAttempts()->latest('number')->first();
        $this->sendDisabled = false;
        $this->sendWamid = 'wamid.retry';
        app(ProcessRuntime::class)->retryMessage($run->id, $step->id, $attempt->id, $run->fresh()->version);
        $this->assertSame('running', $run->fresh()->status);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertSame(2, $step->messageAttempts()->count());
        $this->assertSame('template_disabled', $step->messageAttempts()->oldest('number')->first()->error_code);
    }

    public function test_trial_enrolls_only_one_pinned_checkout_and_workflow(): void
    {
        $otherWorkflow = $this->workflow(ProcessDefinition::defaults('confirmation'));
        $chosen = $this->order(false);
        $other = $this->order(false);
        config(['order_process.enrollment_mode' => 'trial', 'order_process.trial_order_id' => $chosen->id,
            'order_process.trial_automation_id' => $this->workflow->id]);
        DB::transaction(function () use ($chosen, $other): void {
            app(ProcessEvents::class)->checkoutCompleted($chosen);
            app(ProcessEvents::class)->checkoutCompleted($other);
        });
        $this->assertDatabaseCount('order_process_events', 1);
        $this->assertSame(1, app(ProcessRuntime::class)->tick());
        $run = OrderProcessRun::sole();
        $this->assertSame($chosen->id, $run->order_id);
        $this->assertSame($this->workflow->id, $run->automation_id);
        $this->assertFalse(app(ProcessLivePolicy::class)->ownsConfirmation($other));
        Http::assertNothingSent();
    }

    public function test_trial_blocks_an_existing_run_after_scope_changes(): void
    {
        $run = $this->enroll();
        config(['order_process.enrollment_mode' => 'trial', 'order_process.trial_order_id' => $run->order_id + 100,
            'order_process.trial_automation_id' => $run->automation_id]);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('blocked', $run->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_grouped_trial_requires_exact_membership_and_rejects_any_drift(): void
    {
        $chosen = $this->order(false);
        $sibling = $this->order(false);
        $other = $this->order(false);
        $sibling->update(['checkout_group_key' => $chosen->checkout_group_key]);
        config(['order_process.enrollment_mode' => 'trial', 'order_process.trial_order_id' => $chosen->id,
            'order_process.trial_automation_id' => $this->workflow->id,
            'order_process.trial_checkout_order_ids' => $sibling->id.','.$chosen->id]);
        $policy = app(ProcessLivePolicy::class);
        $this->assertTrue($policy->allowsCheckout($chosen->checkoutGroupKey()));
        $this->assertFalse($policy->allowsCheckout($other->checkoutGroupKey()));
        DB::transaction(fn () => app(ProcessEvents::class)->checkoutCompleted($chosen));
        $this->assertSame(1, app(ProcessRuntime::class)->tick());
        $this->assertSame([$chosen->id, $sibling->id], OrderProcessRun::sole()->context['order_ids']);
        $other->update(['checkout_group_key' => $chosen->checkout_group_key]);
        $this->assertFalse($policy->allowsCheckout($chosen->checkoutGroupKey()));
        $other->delete();
        $this->assertFalse($policy->allowsCheckout($chosen->checkoutGroupKey()));
        foreach (['', 'bad', $chosen->id.',', $chosen->id.','.$chosen->id, (string) $sibling->id] as $invalid) {
            config(['order_process.trial_checkout_order_ids' => $invalid]);
            $this->assertFalse($policy->enabled());
            $this->assertFalse($policy->allowsCheckout($chosen->checkoutGroupKey()));
        }
        Http::assertNothingSent();
    }

    public function test_trial_rejects_missing_configuration_and_checkouts_with_added_siblings(): void
    {
        config(['order_process.enrollment_mode' => 'trial']);
        $this->assertFalse(app(ProcessLivePolicy::class)->enabled());
        $chosen = $this->order(false);
        config(['order_process.trial_order_id' => $chosen->id, 'order_process.trial_automation_id' => $this->workflow->id]);
        $this->assertTrue(app(ProcessLivePolicy::class)->enabled());
        $this->assertTrue(app(ProcessLivePolicy::class)->allowsCheckout($chosen->checkoutGroupKey()));
        $sibling = $this->order(false);
        $sibling->update(['checkout_group_key' => $chosen->checkout_group_key]);
        $this->assertFalse(app(ProcessLivePolicy::class)->allowsCheckout($chosen->checkoutGroupKey()));
        $sibling->delete();
        $this->assertFalse(app(ProcessLivePolicy::class)->allowsCheckout($chosen->checkoutGroupKey()));
        config(['order_process.enrollment_mode' => 'typo']);
        $this->assertFalse(app(ProcessLivePolicy::class)->enabled());
        Http::assertNothingSent();
    }

    public function test_disabled_engine_has_no_events_no_jobs_and_preserves_legacy_owner(): void
    {
        config(['order_process.enabled' => false, 'order_process.confirmation_owner' => 'legacy']);
        $o = $this->order();
        $o->update(['status' => 'new_confirmed']);
        $this->assertSame(0, app(ProcessRuntime::class)->tick());
        $this->assertDatabaseCount('order_process_events', 0);
        $this->assertDatabaseCount('order_process_runs', 0);
        $this->assertDatabaseHas('robodesk_integration_events', ['event_type' => 'order.pending_confirmation', 'checkout_group_key' => $o->checkout_group_key]);
        Queue::assertNotPushed(AdvanceOrderProcess::class);
        Http::assertNothingSent();
    }

    public function test_complete_checkout_event_is_unique_and_rolls_back_with_order(): void
    {
        $o = $this->order(false);
        app(ProcessRuntime::class)->tick();
        $this->assertDatabaseCount('order_process_runs', 0);
        DB::transaction(function () use ($o) {
            app(ProcessEvents::class)->checkoutCompleted($o);
            app(ProcessEvents::class)->checkoutCompleted($o);
        });
        $this->assertDatabaseCount('order_process_events', 1);
        try {
            DB::transaction(function () {
                $this->order();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_process_events', 1);
        app(ProcessRuntime::class)->tick();
        app(ProcessRuntime::class)->tick();
        $this->assertDatabaseCount('order_process_runs', 1);
        $this->assertDatabaseCount('robodesk_integration_events', 0);
    }

    public function test_old_orders_and_pre_activation_events_are_not_enrolled(): void
    {
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, false);
        $old = $this->order();
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, true);
        config(['order_process.after_order_id' => $old->id]);
        $old->update(['status' => 'new_confirmed']);
        app(ProcessRuntime::class)->tick();
        $this->assertDatabaseCount('order_process_runs', 0);
        $this->order();
        app(ProcessRuntime::class)->tick();
        $this->assertDatabaseCount('order_process_runs', 1);
    }

    public function test_single_send_durable_wait_and_exact_confirmation_complete_once(): void
    {
        $run = $this->waiting();
        $payments = DB::table('order_payment_events')->get()->toJson();
        $c = $this->history($run);
        app(ProcessResponsePoller::class)->process($c);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('confirmed', $run->fresh()->node_id);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertNull($run->fresh()->active_key);
        $this->assertSame('new_confirmed', $run->order->fresh()->status);
        $this->assertDatabaseCount('order_process_responses', 1);
        $this->assertDatabaseCount('order_status_logs', 1);
        $this->assertSame($payments, DB::table('order_payment_events')->get()->toJson());
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/start/sendMsg') && $r['language'] === 'ar-EG' && is_numeric($r['template']['data'][3]) && ! isset($r['procedureId']));
        $this->assertStringNotContainsString('201000000001', DB::table('order_process_runs')->first()->context);
        $this->assertStringNotContainsString('wamid.', DB::table('order_process_steps')->where('type', 'whatsapp')->first()->receipt);
    }

    #[DataProvider('replyCases')]
    public function test_response_evidence_and_typed_fallback(string $direction, ?string $reply, string $interaction, string $body, bool $accept): void
    {
        $run = $this->waiting();
        $c = $this->history($run, ['direction' => $direction, 'body' => $body, 'provider_metadata' => [
            'contract_fields_present' => true, 'channelId' => 'wamid.inbound', 'replyTo' => $reply, 'templateName' => null, 'interactionId' => $interaction]]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame($accept ? 'running' : 'waiting_response', $run->fresh()->status);
    }

    public static function replyCases(): array
    {
        return [
            ['inbound', null, 'interaction-synthetic', 'تأكيد الطلب', true],
            ['inbound', 'wamid.other', 'interaction-synthetic', 'تأكيد الطلب', true],
            ['inbound', 'wamid.synthetic', 'another-interaction', 'تأكيد الطلب', true],
            ['inbound', null, 'another-interaction', 'تأكيد الطلب', false],
            ['outbound', 'wamid.synthetic', 'interaction-synthetic', 'تأكيد الطلب', false],
            ['inbound', 'wamid.synthetic', 'interaction-synthetic', 'مرحبا', false],
        ];
    }

    public function test_mismatched_template_evidence_does_not_confirm(): void
    {
        $run = $this->waiting();
        $c = $this->history($run);
        $m = $c->messages()->where('direction', 'outbound')->first();
        $meta = $m->provider_metadata;
        $meta['interactionId'] = 'different';
        $m->update(['provider_metadata' => $meta]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('waiting_response', $run->fresh()->status);
    }

    public function test_foreign_phone_and_missing_contract_are_ignored(): void
    {
        $run = $this->waiting();
        $c = $this->history($run, ['provider_metadata' => []]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $c->update(['phone_hash' => hash('sha256', '+201000000002')]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('waiting_response', $run->fresh()->status);
    }

    public function test_uncertain_send_and_expired_worker_lease_never_resend(): void
    {
        $this->uncertain = true;
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('uncertain', $run->fresh()->status);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->tick();
        Http::assertSentCount(2);
        $run->update(['status' => 'sending', 'next_at' => now()->subMinute()]);
        $run->steps()->update(['status' => 'processing', 'lease_until' => now()->subMinute()]);
        app(ProcessRuntime::class)->tick();
        $this->assertSame('uncertain', $run->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_changed_order_or_revoked_authority_blocks_before_send(): void
    {
        $run = $this->enroll();
        $run->order->update(['delivery_details' => ['phone' => '01000000002']]);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('inputs_changed', $run->fresh()->error_code);
        $run2 = $this->enroll();
        $this->actor->update(['is_active' => false]);
        app(ProcessRuntime::class)->advance($run2->id);
        $this->assertSame('actor_revoked', $run2->fresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_wait_timeout_goes_to_staff_review_and_requires_versioned_action(): void
    {
        $run = $this->waiting();
        $this->travel(49)->hours();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('waiting_staff', $run->fresh()->status);
        $this->assertSame('new', $run->order->fresh()->status);
        $this->postJson(route('admin.order-automation.process.action', $run), ['version' => 999, 'action' => 'continue', 'reason' => 'synthetic review'])->assertConflict();
        $this->post(route('admin.order-automation.process.action', $run), ['version' => $run->fresh()->version, 'action' => 'continue', 'reason' => 'synthetic review'])->assertRedirect();
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('completed', $run->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_one_checkout_cannot_send_from_two_workflows(): void
    {
        $other = $this->workflow(ProcessDefinition::defaults('confirmation'));
        $run = $this->enroll();
        $next = OrderProcessRun::where('automation_id', $other->id)->firstOrFail();
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($next->id);
        $this->assertSame('queued', $next->fresh()->status);
        Http::assertSentCount(2);
        $run->refresh()->update(['status' => 'cancelled', 'active_key' => null]);
        app(ProcessRuntime::class)->advance($next->id);
        $this->assertSame('confirmation_already_claimed', $next->fresh()->error_code);
        Http::assertSentCount(2);
    }

    public function test_paused_run_does_not_advance_or_accept_reply(): void
    {
        $run = $this->waiting();
        $c = $this->history($run);
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, false);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $this->assertSame('new', $run->order->fresh()->status);
    }

    public function test_status_event_rolls_back_and_next_workflow_handoff_does_not_repeat(): void
    {
        $d = ProcessDefinition::defaults();
        $d['trigger'] = 'status_entered';
        $d['conditions']['status'] = 'new_confirmed';
        $next = $this->workflow($d);
        $run = $this->enroll();
        try {
            DB::transaction(function () use ($run) {
                $run->order->update(['status' => 'new_confirmed']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('order_process_events', 1);
        $run->order->refresh()->update(['status' => 'new_confirmed']);
        app(ProcessRuntime::class)->tick();
        $this->assertSame(1, OrderProcessRun::where('automation_id', $next->id)->count());
        $run->order->refresh()->update(['status' => 'new']);
        $run->order->update(['status' => 'new_confirmed']);
        app(ProcessRuntime::class)->tick();
        $this->assertSame(1, OrderProcessRun::where('automation_id', $next->id)->count());
    }

    public function test_ui_is_permission_guarded_and_activation_checks_revision_and_live_policy(): void
    {
        $this->get(route('admin.order-automation.process.live', $this->workflow))->assertOk()->assertSee('إحصائيات التنفيذ الفعلي');
        $this->postJson(route('admin.order-automation.process.activate', $this->workflow), ['revision_id' => 999, 'enabled' => false, 'confirmed' => true])->assertConflict();
        $this->postJson(route('admin.order-automation.process.publish', $this->workflow), ['draft_version' => $this->workflow->fresh()->draft_version, 'confirmed' => true])->assertUnprocessable();
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, false);
        config(['order_process.enabled' => false]);
        $this->postJson(route('admin.order-automation.process.activate', $this->workflow), ['revision_id' => $this->workflow->revision_id, 'enabled' => true, 'confirmed' => true])->assertUnprocessable();
        $viewer = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $viewer->permissions()->sync(Permission::whereIn('key', ['order_automation.view', 'order_automation.manage'])->pluck('id'));
        $this->actingAs($viewer);
        $this->get(route('admin.order-automation.process.live', $this->workflow))->assertForbidden();
        $this->postJson(route('admin.order-automation.process.activate', $this->workflow), ['revision_id' => $this->workflow->revision_id, 'enabled' => true, 'confirmed' => true])->assertForbidden();
    }

    public function test_scheduler_dispatches_with_bounded_redelivery_not_every_tick(): void
    {
        $this->enroll();
        app(ProcessRuntime::class)->tick();
        app(ProcessRuntime::class)->tick();
        Queue::assertPushed(AdvanceOrderProcess::class, 1);
        $this->travel(181)->seconds();
        app(ProcessRuntime::class)->tick();
        Queue::assertPushed(AdvanceOrderProcess::class, 2);
    }

    public function test_legacy_pending_contact_is_not_closed_by_new_engine(): void
    {
        config(['order_process.confirmation_owner' => 'legacy']);
        $old = $this->order(false);
        config(['order_process.confirmation_owner' => 'process']);
        $run = $this->enroll();
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame('legacy_confirmation_pending', $run->fresh()->error_code);
        Http::assertNothingSent();
        $this->assertSame('new', $old->fresh()->status);
    }

    public function test_history_sync_uses_full_window_and_catches_late_message_without_after_cursor(): void
    {
        $run = $this->waiting();
        $now = now()->utc();
        $template = ['id' => 't', 'direction' => 'out', 'text' => 'قالب', 'type' => 'text', 'date' => $now->toISOString(), 'channel' => 'WhatsApp', 'attachments' => [],
            'interactionId' => 'interaction-synthetic', 'channelId' => 'wamid.synthetic', 'replyTo' => null, 'templateName' => 'herokid_order_confirmation'];
        $incoming = array_replace($template, ['id' => 'i', 'direction' => 'in', 'text' => 'تأكيد الطلب', 'date' => $now->copy()->addSecond()->toISOString(), 'channelId' => 'wamid.incoming', 'replyTo' => 'wamid.synthetic', 'templateName' => null]);
        WhatsAppConversation::create(['account_key' => $run->account_key, 'phone_hash' => $run->phone_hash, 'phone' => '+201000000001', 'sync_cursor' => 'later-cursor', 'last_full_synced_at' => now()]);
        $this->travel(3)->seconds();
        $this->historyMessages = [$template, $incoming];
        app(OrderConversationService::class)->sync($run->order);
        $this->assertSame('confirmed', $run->fresh()->node_id);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'limit=50') && ! str_contains($r->url(), 'after='));
    }

    public function test_typed_same_second_is_ambiguous_but_exact_reply_is_allowed(): void
    {
        $run = $this->waiting();
        $c = $this->history($run);
        $m = $c->messages()->where('direction', 'inbound')->first();
        $template = $c->messages()->where('direction', 'outbound')->first();
        $m->update(['sent_at' => $template->sent_at, 'provider_metadata' => array_replace($m->provider_metadata, ['replyTo' => null])]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('waiting_response', $run->fresh()->status);
        $m->update(['provider_metadata' => array_replace($m->provider_metadata, ['replyTo' => 'wamid.synthetic'])]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_edit_after_send_blocks_confirmation_without_mutating_order(): void
    {
        $run = $this->waiting();
        $c = $this->history($run);
        $run->order->update(['discount_cents' => 100]);
        app(ProcessResponsePoller::class)->process($c);
        $this->assertSame('blocked', $run->fresh()->status);
        $this->assertSame('new', $run->order->fresh()->status);
        $this->assertDatabaseCount('order_process_responses', 0);
        Http::assertSentCount(2);
    }

    public function test_unsupported_live_actions_stay_available_only_in_simulation(): void
    {
        $d = ProcessDefinition::defaults('confirmation');
        $d['nodes'][2]['config']['to'] = 'cancelled';
        $this->expectException(ValidationException::class);
        app(ProcessLivePolicy::class)->check($d);
    }

    public function test_waiting_run_keeps_immutable_revision_across_republication(): void
    {
        $run = $this->waiting();
        $revision = $run->revision_id;
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, false);
        $this->workflow->update(['draft' => ProcessDefinition::defaults()]);
        $this->post(route('admin.order-automation.process.publish', $this->workflow), ['confirmed' => true, 'draft_version' => $this->workflow->fresh()->draft_version])->assertRedirect();
        $this->workflow->refresh();
        app(ProcessRuntime::class)->activate($this->workflow, $this->actor, true);
        $c = $this->history($run);
        app(ProcessResponsePoller::class)->process($c);
        app(ProcessRuntime::class)->advance($run->id);
        app(ProcessRuntime::class)->advance($run->id);
        $this->assertSame($revision, $run->fresh()->revision_id);
        $this->assertSame('new_confirmed', $run->order->fresh()->status);
    }

    public function test_live_history_hides_raw_receipts_phone_and_customer_messages(): void
    {
        $run = $this->waiting();
        $this->get(route('admin.order-automation.process.live', $this->workflow))->assertOk()->assertSee('ينتظر رد العميل')->assertDontSee('wamid.synthetic')->assertDontSee('201000000001');
    }

    public function test_global_pause_does_not_fall_back_to_legacy_sends_and_stored_ownership_survives_config_rollback(): void
    {
        $run = $this->waiting();
        config(['order_process.enabled' => false]);
        $this->order();
        $this->assertDatabaseCount('robodesk_integration_events', 0);
        config(['order_process.confirmation_owner' => 'legacy', 'order_process.after_order_id' => null]);
        Order::create(['order_number' => 'TEST-ROLLBACK-CHILD', 'checkout_group_key' => $run->checkout_key, 'status' => 'new', 'delivery_details' => ['phone' => '01000000001']]);
        $this->assertDatabaseCount('robodesk_integration_events', 0);
        Http::assertSentCount(2);
    }
}
