<?php

namespace Tests\Feature;

use App\Models\{Order, OrderAutomation, OrderAutomationRevision, OrderProcessEvent, OrderProcessRun, OrderPaymentReview, Permission, User, WhatsAppConversation};
use App\Services\OrderAutomation\Process\{ProcessDefinition, ProcessLivePolicy, ProcessPaymentReview, ProcessTemplates};
use App\Services\RoboDesk\Conversations\ActiveConversationRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class ProcessPaymentReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Order $order;
    private OrderProcessRun $run;
    private WhatsAppConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->travelTo(now()->utc()->startOfDay());
        config(['order_process.enabled' => true, 'order_process.payment_review_enabled' => true,
            'order_process.after_order_id' => 0, 'order_process.enrollment_mode' => 'all_new']);
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->actor->permissions()->sync(Permission::whereIn('key', ['orders.view', 'orders.update', 'orders.photos.view',
            'order_automation.view', 'order_automation.manage', 'order_automation.activate', 'order_automation.execute', 'robodesk.review_payments'])->pluck('id'));
        $this->order = Order::create(['order_number' => 'SIGNAL-TEST', 'parent_name' => 'Synthetic', 'status' => 'costomer_confirmed',
            'delivery_details' => ['phone' => '01000000001'], 'payment_status' => 'unpaid']);
        $d = ProcessDefinition::defaults('identity');
        $a = OrderAutomation::create(['name' => 'Synthetic', 'draft' => $d, 'enabled' => true, 'process_activated_by' => $this->actor->id]);
        $r = OrderAutomationRevision::create(['automation_id' => $a->id, 'definition' => $d, 'checksum' => hash('sha256', json_encode($d)), 'published_by' => $this->actor->id, 'created_at' => now()]);
        $a->update(['revision_id' => $r->id]);
        $e = OrderProcessEvent::create(['event_key' => 'synthetic-event', 'type' => 'status', 'order_id' => $this->order->id, 'checkout_key' => $this->order->checkoutGroupKey(), 'data' => [], 'status' => 'processed']);
        $this->run = OrderProcessRun::create(['automation_id' => $a->id, 'revision_id' => $r->id, 'event_id' => $e->id,
            'order_id' => $this->order->id, 'checkout_key' => $this->order->checkoutGroupKey(), 'account_key' => 'primary',
            'phone_hash' => hash('sha256', '+201000000001'), 'enrollment_key' => hash('sha256', 'synthetic'), 'status' => 'waiting_staff', 'node_id' => 'review', 'context' => []]);
        $this->run->steps()->create(['node_id' => 'preview', 'type' => 'whatsapp', 'status' => 'completed', 'started_at' => now(),
            'receipt' => ['message_id' => 'wamid.preview', 'interaction_id' => 'interaction-one']]);
        $this->conversation = WhatsAppConversation::create(['account_key' => 'primary', 'phone_hash' => $this->run->phone_hash, 'phone' => '+201000000001']);
        $this->message('template', ['direction' => 'outbound', 'attachments' => [], 'provider_metadata' => ['interactionId' => 'interaction-one', 'channelId' => 'wamid.preview', 'templateName' => ProcessTemplates::IDENTITY]]);
        $this->travel(2)->seconds();
    }

    private function message(string $id, array $changes = [])
    {
        return $this->conversation->messages()->create($changes + ['remote_id' => $id, 'remote_hash' => hash('sha256', $id),
            'fingerprint' => hash('sha256', $id), 'direction' => 'inbound', 'kind' => 'image', 'attachments' => [['name' => 'synthetic.png']],
            'sent_at' => now(), 'provider_metadata' => ['interactionId' => 'interaction-one', 'channelId' => 'wamid.'.$id]]);
    }

    private function poll(): void
    {
        app(ProcessPaymentReview::class)->process($this->conversation);
    }

    public function test_any_attachment_after_preview_marks_review_once_without_money_or_order_effects(): void
    {
        $before = $this->order->fresh()->getRawOriginal();
        $this->message('attachment');
        $this->poll();
        $this->poll();
        $this->assertDatabaseCount('order_payment_reviews', 1);
        $this->assertDatabaseCount('order_payment_review_signals', 1);
        $this->assertSame('pending', OrderPaymentReview::first()->status);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->assertSame('waiting_staff', $this->run->fresh()->status);
        $this->assertDatabaseCount('order_payment_proofs', 0);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_review_preserves_existing_deposit_and_does_not_require_identity_reply_first(): void
    {
        $this->order->updateQuietly(['payment_status' => 'partially_paid', 'paid_amount_cents' => 12000]);
        $this->run->update(['status' => 'waiting_response', 'node_id' => 'response']);
        $before = $this->order->fresh()->getRawOriginal();
        $this->message('attachment');
        $this->poll();
        $this->assertDatabaseCount('order_payment_review_signals', 1);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->assertSame('waiting_response', $this->run->fresh()->status);
    }

    public function test_plain_text_outbound_and_wrong_interaction_do_not_mark_review(): void
    {
        $this->message('text', ['attachments' => [], 'kind' => 'text', 'body' => 'وصلني إثبات التحويل']);
        $this->message('out', ['direction' => 'outbound']);
        $this->message('other', ['provider_metadata' => ['interactionId' => 'other']]);
        $this->message('earlier', ['sent_at' => now()->subMinutes(2)]);
        $this->message('same-second', ['sent_at' => now()->subSeconds(2)]);
        $this->poll();
        $this->assertDatabaseCount('order_payment_reviews', 0);
    }

    public function test_missing_template_evidence_does_not_guess_from_phone(): void
    {
        $this->conversation->messages()->where('direction', 'outbound')->delete();
        $this->message('attachment');
        $this->poll();
        $this->assertDatabaseCount('order_payment_reviews', 0);
    }

    public function test_two_checkouts_in_same_interaction_are_ambiguous(): void
    {
        $other = $this->run->replicate(['active_key']);
        $other->enrollment_key = hash('sha256', 'other');
        $other->checkout_key = 'other-checkout';
        $other->save();
        $other->steps()->create(['node_id' => 'preview', 'type' => 'whatsapp', 'status' => 'completed', 'receipt' => ['message_id' => 'wamid.other', 'interaction_id' => 'interaction-one']]);
        $this->message('attachment');
        $this->poll();
        $this->assertDatabaseCount('order_payment_reviews', 0);
    }

    public function test_trial_scope_contact_change_paused_workflow_and_disabled_feature_are_respected(): void
    {
        $this->message('attachment');
        config(['order_process.payment_review_enabled' => false]);
        $this->poll();
        config(['order_process.payment_review_enabled' => true, 'order_process.enrollment_mode' => 'trial', 'order_process.trial_order_id' => $this->order->id + 1, 'order_process.trial_automation_id' => $this->run->automation_id]);
        $this->poll();
        config(['order_process.enrollment_mode' => 'all_new']);
        $this->run->automation->update(['enabled' => false]);
        $this->poll();
        $this->run->automation->update(['enabled' => true]);
        $this->order->update(['delivery_details' => ['phone' => '01000000002']]);
        $this->poll();
        $this->assertDatabaseCount('order_payment_reviews', 0);
    }

    public function test_waiting_staff_keeps_full_read_until_review_closes_without_advancing_process(): void
    {
        $this->assertTrue(app(ProcessPaymentReview::class)->needsFullWindow('+201000000001'));
        $this->assertTrue(app(ActiveConversationRefreshService::class)->ordersToRefresh()->whereKey($this->order->id)->exists());
        $this->message('attachment');
        $this->poll();
        $review = OrderPaymentReview::firstOrFail();
        $before = $this->order->fresh()->getRawOriginal();
        app(ProcessPaymentReview::class)->close($this->order, $review->id, $this->actor, 'Reviewed manually, not a payment', $review->signals()->max('id'));
        $this->assertFalse(app(ProcessPaymentReview::class)->needsFullWindow('+201000000001'));
        $this->message('late');
        $this->poll();
        $this->assertDatabaseCount('order_payment_review_signals', 1);
        $this->assertSame('closed', $review->fresh()->status);
        $this->assertSame($before, $this->order->fresh()->getRawOriginal());
        $this->assertSame('waiting_staff', $this->run->fresh()->status);
        $this->assertStringNotContainsString('Reviewed manually', $review->fresh()->getRawOriginal('review_note'));
    }

    public function test_background_full_read_keeps_waiting_staff_and_ingests_delayed_attachments(): void
    {
        \App\Models\RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'synthetic']);
        $this->conversation->update(['sync_cursor' => 'later-message', 'last_full_synced_at' => now()]);
        Http::fake(['hero-kid.robodesk.ai/*' => Http::response(['phone' => '201000000001', 'messages' => [
            ['id' => 'delayed-attachment', 'direction' => 'in', 'type' => 'image', 'date' => now()->toISOString(), 'text' => '',
                'channelId' => 'wamid.delayed', 'replyTo' => null, 'templateName' => null, 'interactionId' => 'interaction-one', 'attachments' => [['file' => 'not-necessarily-a-receipt.png']]],
        ]])]);
        app(\App\Services\RoboDesk\Conversations\OrderConversationService::class)->sync($this->order);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r['limit'] === 50 && ! isset($r['after']));
        $this->assertDatabaseCount('order_payment_review_signals', 1);
        $this->assertSame('waiting_staff', $this->run->fresh()->status);
    }

    public function test_review_panel_renders_no_amount_and_close_route_rejects_foreign_checkout(): void
    {
        $this->message('attachment');
        $this->poll();
        $review = OrderPaymentReview::with('reviewer')->withCount('signals')->withMax('signals', 'id')->firstOrFail();
        $this->actingAs($this->actor);
        $html = view('admin.orders._payment-review', ['messageHistoryOrder' => $this->order, 'orderPaymentReviews' => collect([$review])])->render();
        $this->assertStringContainsString('بانتظار مراجعة الدفع', $html);
        $this->assertStringContainsString('إنهاء المراجعة فقط', $html);
        $this->assertStringNotContainsString('synthetic.png', $html);
        $other = Order::create(['order_number' => 'FOREIGN', 'status' => 'new']);
        $this->postJson(route('admin.orders.payment-review.close', $other), ['review_id' => $review->id, 'seen_signal_id' => $review->signals_max_id, 'note' => 'Reviewed', 'confirm' => 1])->assertNotFound();
        $this->postJson(route('admin.orders.payment-review.close', $this->order), ['review_id' => $review->id, 'seen_signal_id' => $review->signals_max_id, 'note' => 'Reviewed, no money', 'confirm' => 1])->assertRedirect();
        $this->assertSame('closed', $review->fresh()->status);
        $this->assertSame(0, (int) $this->order->fresh()->paid_amount_cents);
    }

    public function test_stale_review_form_cannot_close_a_new_attachment_and_ordinary_staff_cannot_close(): void
    {
        $this->message('first');
        $this->poll();
        $review = OrderPaymentReview::firstOrFail();
        $seen = $review->signals()->max('id');
        $this->message('second');
        $this->poll();
        $payload = ['review_id' => $review->id, 'seen_signal_id' => $seen, 'note' => 'Reviewed', 'confirm' => 1];
        $this->actingAs($this->actor)->postJson(route('admin.orders.payment-review.close', $this->order), $payload)->assertConflict();
        $staff = User::factory()->create(['role' => 'admin']);
        $staff->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->actingAs($staff)->postJson(route('admin.orders.payment-review.close', $this->order), $payload)->assertForbidden();
        $this->assertSame('pending', $review->fresh()->status);
    }
}
