<?php

namespace Tests\Feature;

use App\Jobs\SendOrderConfirmationJob;
use App\Models\CheckoutCustomerWorkflow;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RoboDeskConversationSetting;
use App\Models\RoboDeskIntegrationEvent;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Services\RoboDesk\Conversations\OrderConversationService;
use App\Services\RoboDesk\RoboDeskOutbox;
use App\Support\OrderStatusRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private array $messages = [];

    private array $interactions = [];

    private array $sendResponses = [];

    private mixed $closeResponse = null;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->utc()->startOfDay());
        Cache::flush();
        Queue::fake();
        config(['queue.default' => 'redis', 'robodesk.enabled' => false,
            'robodesk.inbound_secret' => '', 'robodesk.outbound_secret' => '',
            'robodesk.confirmation.enabled' => true, 'robodesk.confirmation.contract_ready' => true,
            'robodesk.confirmation.after_order_id' => 0, 'robodesk.confirmation.audience' => 'all_new', 'robodesk.confirmation_only' => true]);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only', 'conversation_limit' => 50]);
        Http::preventStrayRequests();
        Http::fake(['https://hero-kid.robodesk.ai/*' => function ($request) {
            if (str_contains($request->url(), '/messagesByPhone')) {
                return Http::response(['phone' => '201000000001', 'interactions' => $this->interactions, 'messages' => $this->messages]);
            }
            if (str_ends_with($request->url(), '/close')) {
                return $this->closeResponse ?? Http::response(['success' => true]);
            }
            if (str_ends_with($request->url(), '/start/sendMsg')) {
                return array_shift($this->sendResponses) ?? Http::response(['channelId' => 'wamid.synthetic.'.++$this->sequence, '_id' => 'interaction-synthetic']);
            }
            throw new \RuntimeException('Unexpected test HTTP request');
        }]);
    }

    private function order(string $key = 'CHK-SYNTHETIC', string $status = 'new'): Order
    {
        return Order::create(['order_number' => 'HK-'.Str::random(8), 'checkout_group_key' => $key,
            'parent_name' => 'عميل تجريبي', 'status' => $status, 'uploaded_photos' => [],
            'delivery_details' => ['checkout_group' => $key, 'phone' => '01000000001', 'delivery_fee' => 50]]);
    }

    private function request(Order $order): RoboDeskIntegrationEvent
    {
        return RoboDeskIntegrationEvent::where('event_type', 'order.pending_confirmation')
            ->where('checkout_group_key', $order->checkout_group_key)->firstOrFail();
    }

    private function send(RoboDeskIntegrationEvent $event): void
    {
        app()->call([new SendOrderConfirmationJob($event->id), 'handle']);
    }

    private function message(string $id, array $changes = []): array
    {
        return $changes + ['id' => $id, 'direction' => 'in', 'text' => 'تأكيد الطلب', 'type' => 'text',
            'date' => now()->utc()->addSeconds(2)->toISOString(), 'channel' => 'WhatsApp', 'attachments' => [],
            'interactionId' => 'interaction-synthetic', 'channelId' => 'wamid.inbound.'.$id,
            'replyTo' => null, 'templateName' => null];
    }

    private function template(RoboDeskIntegrationEvent $event, array $changes = []): array
    {
        return $this->message('template-'.$event->id, $changes + ['direction' => 'out', 'text' => 'Synthetic template',
            'date' => now()->utc()->addSecond()->toISOString(),
            'channelId' => $event->fresh()->response_payload['message_id'], 'templateName' => 'herokid_order_confirmation']);
    }

    private function sync(Order $order): void
    {
        $this->travel(20)->seconds();
        app(OrderConversationService::class)->sync($order);
    }

    public function test_group_sends_once_exact_template_without_hmac_or_legacy_event_api(): void
    {
        $one = $this->order();
        $this->order();
        $event = $this->request($one);
        Queue::assertPushed(SendOrderConfirmationJob::class, 1);
        $this->send($event);
        $this->send($event->fresh());
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/start/sendMsg')
            && $r['language'] === 'ar-EG' && $r['to'] === '201000000001' && ! isset($r['procedureId'])
            && $r['template']['data'][1] === $one->checkoutReference->short_reference
            && count($r['template']['data']) === 4);
        $this->assertCount(2, $event->fresh()->payload['order_numbers']);
        $this->assertSame('طلب جديد غير مؤكد', OrderStatusRegistry::label('order', 'new'));
        $this->assertSame('طلب جديد مؤكد', OrderStatusRegistry::label('order', 'new_confirmed'));
        $this->assertFalse(app('router')->getRoutes()->hasNamedRoute('api.robodesk.order-confirmations.store'));
    }

    public function test_open_conversations_use_id_and_close_success_before_send(): void
    {
        $this->interactions = [['id' => 'open-agent', 'closed' => false], ['id' => 'closed-ai', 'closed' => true]];
        $event = $this->request($this->order());
        $this->send($event);
        $this->assertSame('succeeded', $event->fresh()->status);
        $requests = Http::recorded()->map(fn ($pair) => $pair[0])->values();
        $this->assertStringContainsString('/messagesByPhone', $requests[0]->url());
        $this->assertStringEndsWith('/close', $requests[1]->url());
        $this->assertSame(['closeData' => ['conversations' => [['_id' => 'open-agent']], 'isBulk' => true], 'note' => null], $requests[1]->data());
        $this->assertStringEndsWith('/start/sendMsg', $requests[2]->url());
    }

    public function test_close_failure_and_legacy_underscore_id_never_send(): void
    {
        $this->interactions = [['id' => 'open', 'closed' => false]];
        $this->closeResponse = Http::response(['success' => false]);
        $one = $this->request($this->order('CHK-CLOSE'));
        $this->send($one);
        $this->assertSame('unknown', $one->fresh()->status);
        $this->interactions = [['_id' => 'wrong-field', 'closed' => false]];
        $two = $this->request($this->order('CHK-ID'));
        $this->send($two);
        $this->assertSame('unknown', $two->fresh()->status);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/start/sendMsg'));
    }

    public function test_exact_501_is_retried_once_only_with_fresh_lookup_and_close(): void
    {
        $this->interactions = [['id' => 'open', 'closed' => false]];
        $this->sendResponses = [Http::response('Customer already have another interaction', 501)];
        $one = $this->request($this->order('CHK-RACE-ONE'));
        $this->send($one);
        $this->assertSame('succeeded', $one->fresh()->status);
        Http::assertSentCount(6);
        $this->sendResponses = array_fill(0, 2, Http::response('Customer already have another interaction', 501));
        $two = $this->request($this->order('CHK-RACE-TWO'));
        $this->send($two);
        $this->send($two->fresh());
        $this->assertSame('blocked', $two->fresh()->status);
        $this->assertSame('conversation_race_retry_exhausted', $two->fresh()->last_error);
        Http::assertSentCount(12);
    }

    public function test_other_errors_missing_receipts_and_timeouts_are_never_resent(): void
    {
        foreach ([Http::response('private-do-not-log', 500), Http::failedConnection(), Http::response(['_id' => 'no-wamid']),
            Http::response('different-501', 501), Http::response('disabled', 409)] as $i => $response) {
            $this->sendResponses = [$response];
            $event = $this->request($this->order('CHK-FAIL-'.$i));
            $this->send($event);
            $this->send($event->fresh());
            $this->assertSame($i === 4 ? 'blocked' : 'unknown', $event->fresh()->status);
            $this->assertSame(1, $event->fresh()->attempts);
            $this->assertStringNotContainsString('private-do-not-log', $event->fresh()->last_error);
        }
        $this->expectException(HttpException::class);
        app(RoboDeskOutbox::class)->release($event->fresh());
    }

    public function test_same_phone_send_waits_for_contact_lock_without_claim_or_external_call(): void
    {
        $event = $this->request($this->order());
        $lock = Cache::lock('robodesk-history:'.hash('sha256', 'primary'.hash('sha256', '+201000000001')), 130);
        $lock->get();
        $this->send($event);
        Http::assertNothingSent();
        $this->assertSame(0, $event->fresh()->attempts);
        Queue::assertPushed(SendOrderConfirmationJob::class, 2);
        $lock->release();
        $this->send($event);
        $this->assertSame('succeeded', $event->fresh()->status);
    }

    public function test_reply_confirms_all_parts_across_interactions_once_without_payment_or_ai(): void
    {
        $one = $this->order();
        $two = $this->order();
        $event = $this->request($one);
        $this->send($event);
        $this->messages = [$this->message('click', ['replyTo' => $event->fresh()->response_payload['message_id'], 'interactionId' => 'new-conversation'])];
        $this->sync($one);
        $confirmed = CheckoutCustomerWorkflow::firstOrFail()->confirmed_at->toISOString();
        $this->messages = [];
        $this->sync($one);
        $this->assertSame($confirmed, CheckoutCustomerWorkflow::firstOrFail()->confirmed_at->toISOString());
        foreach ([$one, $two] as $order) {
            $this->assertSame('new_confirmed', $order->fresh()->status);
            $this->assertSame('unpaid', $order->fresh()->payment_status);
            $this->assertSame(1, $order->statusLogs()->where('status', 'new_confirmed')->count());
        }
        $this->assertDatabaseCount('order_automation_runs', 0);
        Queue::assertPushed(SendOrderConfirmationJob::class, 1);
        $one->updateQuietly(['status' => 'generating']);
        $this->sync($one);
        $this->assertSame('generating', $one->fresh()->status);
    }

    public function test_two_orders_reply_uses_exact_wamid_and_typed_uses_latest_prior_template(): void
    {
        $one = $this->order('CHK-ONE');
        $two = $this->order('CHK-TWO');
        $first = $this->request($one);
        $second = $this->request($two);
        $this->send($first);
        $a = $this->template($first);
        $this->travel(5)->seconds();
        $this->send($second);
        $b = $this->template($second);
        $this->messages = [$a, $b, $this->message('typed', ['text' => "  تأكيد الطلب \n"])];
        $this->sync($one);
        $this->assertSame('new', $one->fresh()->status);
        $this->assertSame('new_confirmed', $two->fresh()->status);
        $this->messages = [$this->message('click-older', ['replyTo' => $first->fresh()->response_payload['message_id'], 'interactionId' => 'other-interaction'])];
        $this->sync($one);
        $this->assertSame('new_confirmed', $one->fresh()->status);
    }

    public function test_typed_before_second_template_matches_first_and_later_template_does_not_steal_it(): void
    {
        $one = $this->order('CHK-ONE');
        $first = $this->request($one);
        $this->send($first);
        $a = $this->template($first);
        $typed = $this->message('typed-before-second');
        $this->travel(5)->seconds();
        $two = $this->order('CHK-TWO');
        $second = $this->request($two);
        $this->send($second);
        $this->messages = [$a, $typed, $this->template($second)];
        $this->sync($one);
        $this->assertSame('new_confirmed', $one->fresh()->status);
        $this->assertSame('new', $two->fresh()->status);
    }

    public function test_missing_fields_outbound_messages_and_wrong_text_cannot_confirm(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $missing = $this->message('old-contract');
        unset($missing['replyTo']);
        $this->messages = [$this->template($event), $missing,
            $this->message('other-text', ['text' => 'تمام']),
            $this->message('outbound', ['direction' => 'out', 'replyTo' => $event->fresh()->response_payload['message_id']]),
            $this->message('wrong-interaction', ['interactionId' => 'other'])];
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseCount('checkout_customer_workflows', 0);
    }

    public function test_quoting_an_unrelated_message_falls_back_to_latest_prior_template_in_same_interaction(): void
    {
        $one = $this->order('CHK-QUOTE-ONE');
        $two = $this->order('CHK-QUOTE-TWO');
        $first = $this->request($one);
        $second = $this->request($two);
        $this->send($first);
        $a = $this->template($first);
        $this->travel(5)->seconds();
        $this->send($second);
        $this->messages = [$a, $this->template($second),
            $this->message('quoted-other', ['replyTo' => 'wamid.old-normal-message'])];
        $this->sync($one);
        $this->assertSame('new', $one->fresh()->status);
        $this->assertSame('new_confirmed', $two->fresh()->status);
        $this->assertSame('typed', CheckoutCustomerWorkflow::firstOrFail()->metadata['confirmation_method']);
    }

    public function test_unknown_reply_without_same_interaction_template_does_not_confirm(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $this->messages = [$this->template($event),
            $this->message('quote-wrong-interaction', ['replyTo' => 'wamid.unrelated', 'interactionId' => 'different-interaction'])];
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_typed_requires_actual_outbound_template_and_untracked_latest_template_blocks_fallback(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $typed = $this->message('typed');
        $this->messages = [$typed];
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
        $this->messages = [$this->template($event, ['date' => now()->subSeconds(19)->toISOString()]),
            $this->message('untracked-template', ['direction' => 'out', 'channelId' => 'wamid.untracked',
                'templateName' => 'herokid_order_confirmation', 'date' => now()->subMilliseconds(18500)->toISOString()]),
            $this->message('typed-new')];
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_saved_message_is_reprocessed_when_receipt_arrives_after_cursor_advanced(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $event = $event->fresh();
        $receipt = $event->response_payload;
        $event->update(['status' => 'processing', 'response_payload' => null]);
        $this->messages = [$this->message('early-click', ['replyTo' => $receipt['message_id']])];
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertSame('early-click', WhatsAppConversation::firstOrFail()->sync_cursor);
        $event->update(['status' => 'succeeded', 'response_payload' => $receipt]);
        $this->messages = [];
        $this->sync($order);
        $this->assertSame('new_confirmed', $order->fresh()->status);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'after=early-click'));
        $raw = DB::table('whatsapp_conversation_messages')->first();
        $this->assertStringNotContainsString($receipt['message_id'], $raw->provider_metadata);
        $this->assertArrayNotHasKey('provider_metadata', WhatsAppConversationMessage::firstOrFail()->toArray());
    }

    public function test_cancelled_advanced_deleted_or_changed_phone_never_confirms(): void
    {
        foreach (['cancelled', 'generating', 'shipped', 'deleted', 'phone'] as $state) {
            $order = $this->order('CHK-'.$state);
            $event = $this->request($order);
            $this->send($event);
            $this->messages = [$this->message('click-'.$state, ['replyTo' => $event->fresh()->response_payload['message_id']])];
            if ($state === 'deleted') {
                $order->delete();
            } elseif ($state === 'phone') {
                $order->updateQuietly(['delivery_details' => ['phone' => '01000000002']]);
            } else {
                $order->updateQuietly(['status' => $state]);
            }
            // Another order on the same phone drives the normal contact polling.
            $driver = $this->order('CHK-DRIVER-'.$state);
            $this->sync($driver);
        }
        $this->assertDatabaseCount('checkout_customer_workflows', 0);
    }

    public function test_historical_orders_boundary_kill_switch_and_contract_gate_prevent_sends(): void
    {
        config(['robodesk.confirmation.enabled' => false]);
        $old = $this->order('CHK-OLD');
        config(['robodesk.confirmation.enabled' => true, 'robodesk.confirmation.after_order_id' => $old->id]);
        $this->order('CHK-OLD');
        $this->send($this->request($old));
        $new = $this->order('CHK-NEW');
        config(['robodesk.confirmation.enabled' => false]);
        $this->send($this->request($new));
        foreach ([['robodesk.confirmation.after_order_id' => null], ['robodesk.confirmation.contract_ready' => false],
            ['queue.default' => 'sync'], ['robodesk.confirmation.language' => 'ar']] as $i => $bad) {
            config(['robodesk.confirmation.enabled' => true, 'robodesk.confirmation.after_order_id' => 0,
                'robodesk.confirmation.contract_ready' => true, 'queue.default' => 'redis', 'robodesk.confirmation.language' => 'ar-EG']);
            config($bad);
            $this->send($this->request($this->order('CHK-GATE-'.$i)));
        }
        Http::assertNothingSent();
    }

    public function test_kill_switch_stops_confirmation_and_old_events_are_not_eligible(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $this->messages = [$this->message('click', ['replyTo' => $event->fresh()->response_payload['message_id']])];
        config(['robodesk.confirmation.enabled' => false]);
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
        config(['robodesk.confirmation.enabled' => true]);
        $payload = $event->fresh()->payload;
        $payload['confirmation']['eligible_at_creation'] = false;
        $event->update(['payload' => $payload]);
        $this->sync($order);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_trial_requires_explicit_phone_allowlist_and_does_not_capture_other_customers(): void
    {
        config(['robodesk.confirmation.audience' => 'trial', 'robodesk.confirmation.trial_phone_hashes' => []]);
        $old = $this->order('CHK-EMPTY-TRIAL');
        $this->assertSame('held', $this->request($old)->status);
        config(['robodesk.confirmation.trial_phone_hashes' => [hash('sha256', '+201000000002')]]);
        $other = $this->order('CHK-NOT-IN-TRIAL');
        $this->send($this->request($other));
        Http::assertNothingSent();
        config(['robodesk.confirmation.trial_phone_hashes' => [hash('sha256', '+201000000001')]]);
        $this->send($this->request($old));
        $this->send($this->request($other));
        Http::assertNothingSent();
        $new = $this->request($this->order('CHK-APPROVED-TRIAL'));
        $this->send($new);
        $this->assertSame('succeeded', $new->fresh()->status);
    }

    public function test_ambiguous_typed_template_timestamp_never_guesses(): void
    {
        $one = $this->order('CHK-TIE-ONE');
        $two = $this->order('CHK-TIE-TWO');
        $first = $this->request($one);
        $second = $this->request($two);
        $this->send($first);
        $this->send($second);
        $this->messages = [$this->template($first), $this->template($second), $this->message('typed-tie')];
        $this->sync($one);
        $this->assertSame('new', $one->fresh()->status);
        $this->assertSame('new', $two->fresh()->status);
    }

    public function test_one_inbound_message_cannot_confirm_two_orders_after_provider_history_changes(): void
    {
        $one = $this->order('CHK-CONSUME-ONE');
        $two = $this->order('CHK-CONSUME-TWO');
        $first = $this->request($one);
        $second = $this->request($two);
        $this->send($first);
        $this->send($second);
        $this->messages = [$this->message('same-inbound', ['replyTo' => $first->fresh()->response_payload['message_id']])];
        $this->sync($one);
        $this->assertSame('new_confirmed', $one->fresh()->status);
        $this->messages[0]['replyTo'] = $second->fresh()->response_payload['message_id'];
        $this->sync($one);
        $this->assertSame('new', $two->fresh()->status);
        $this->assertDatabaseCount('checkout_customer_workflows', 1);
    }

    public function test_typed_waits_for_missing_second_template_evidence(): void
    {
        $one = $this->order('CHK-MISSING-ONE');
        $two = $this->order('CHK-MISSING-TWO');
        $first = $this->request($one);
        $second = $this->request($two);
        $this->send($first);
        $a = $this->template($first);
        $this->travel(5)->seconds();
        $this->send($second);
        $b = $this->template($second);
        $this->messages = [$a, $this->message('typed-before-history-complete')];
        $this->sync($one);
        $this->assertSame('new', $one->fresh()->status);
        $this->assertSame('new', $two->fresh()->status);
        $this->messages = [$b];
        $this->sync($one);
        $this->assertSame('new', $one->fresh()->status);
        $this->assertSame('new_confirmed', $two->fresh()->status);
    }

    public function test_invalid_poll_metadata_rolls_back_without_advancing_cursor(): void
    {
        $order = $this->order();
        $event = $this->request($order);
        $this->send($event);
        $this->messages = [$this->message('invalid', ['replyTo' => ['bad-type']])];
        try {
            $this->sync($order);
            $this->fail('Malformed metadata was accepted');
        } catch (\RuntimeException $exception) {
            $this->assertSame('invalid_provider_response', $exception->getMessage());
        }
        $this->assertDatabaseCount('whatsapp_conversations', 0);
        $this->assertSame('new', $order->fresh()->status);
    }

    public function test_template_total_is_after_discount_including_shipping(): void
    {
        $order = $this->order();
        $order->items()->create(['item_type' => 'product', 'title' => 'كتاب تلوين', 'quantity' => 2,
            'unit_price_cents' => 10000, 'total_price_cents' => 20000]);
        $order->updateQuietly(['discount_cents' => 3000]);
        $this->send($this->request($order));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/start/sendMsg')
            && $r['template']['data'][2] === 'كتاب تلوين × 2' && $r['template']['data'][3] === '220.00');
    }

    public function test_automation_page_preserves_permissions_and_explains_typed_polling(): void
    {
        $order = $this->order();
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $admin->permissions()->sync(Permission::whereIn('key', ['orders.view', 'order_automation.view'])->pluck('id'));
        $this->actingAs($admin)->get('/admin/order-automation')->assertOk()->assertSee('تأكيد الطلب عبر واتساب')
            ->assertSee('كتابة نفس العبارة')->assertSee('قراءة التأكيدات دورية')->assertSee($order->order_number);
        $admin->permissions()->sync(Permission::where('key', 'order_automation.view')->pluck('id'));
        $admin->unsetRelation('permissions');
        $this->get('/admin/order-automation')->assertOk()->assertDontSee($order->order_number);
        $admin->permissions()->detach();
        $admin->unsetRelation('permissions');
        $this->get('/admin/order-automation')->assertForbidden();
    }
}
