<?php

namespace Tests\Feature;

use App\Jobs\RefreshActiveOrderConversation;
use App\Models\Order;
use App\Models\RoboDeskConversationSetting;
use App\Models\WhatsAppConversation;
use App\Services\RoboDesk\Conversations\ActiveConversationRefreshService;
use App\Services\RoboDesk\Conversations\ConversationPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

class ActiveOrderConversationRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        Cache::flush();
        Http::preventStrayRequests();
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'integration@example.test', 'password' => 'synthetic-secret']);
    }

    private function order(int $index = 0, array $changes = []): Order
    {
        return Order::create($changes + ['order_number' => 'BACKGROUND-'.fake()->uuid(), 'parent_name' => 'عميل اختبار',
            'status' => 'new', 'delivery_details' => ['phone' => sprintf('01012345%03d', $index)]]);
    }

    private function job(Order $order): RefreshActiveOrderConversation
    {
        return new RefreshActiveOrderConversation($order->id, 'primary', hash('sha256', ConversationPhone::canonical(data_get($order->delivery_details, 'phone'))));
    }

    private function fake(Order $order, array $messages = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['hero-kid.robodesk.ai/*' => Http::response(['phone' => ltrim(ConversationPhone::canonical(data_get($order->delivery_details, 'phone')), '+'), 'messages' => $messages])]);
    }

    public function test_dispatch_is_bounded_deduplicates_contacts_and_does_not_call_provider(): void
    {
        Queue::fake();
        $first = $this->order();
        $this->order();
        $second = $this->order(1);
        $this->order(2);
        config(['robodesk.conversations.background_batch_size' => 2]);
        $this->assertSame(2, app(ActiveConversationRefreshService::class)->dispatchDue());
        Queue::assertPushed(RefreshActiveOrderConversation::class, 2);
        Queue::assertPushed(RefreshActiveOrderConversation::class, fn ($job) => $job->orderId === $first->id && $job->queue === 'robodesk-conversations');
        Queue::assertPushed(RefreshActiveOrderConversation::class, fn ($job) => $job->orderId === $second->id);
        Http::assertNothingSent();
        $this->assertSame(1, app(ActiveConversationRefreshService::class)->dispatchDue());
    }

    public function test_finished_cancelled_deleted_and_invalid_phone_orders_are_excluded(): void
    {
        Queue::fake();
        $active = $this->order();
        $this->order(1, ['status' => 'cancelled']);
        $this->order(2, ['status' => 'delivered', 'payment_status' => 'paid_in_full', 'printing_status' => 'completed', 'shipping_status' => 'delivered']);
        $this->order(3)->delete();
        $this->order(4, ['delivery_details' => ['phone' => 'invalid']]);
        $this->assertSame(1, app(ActiveConversationRefreshService::class)->dispatchDue());
        Queue::assertPushed(RefreshActiveOrderConversation::class, 1);
        Queue::assertPushed(RefreshActiveOrderConversation::class, fn ($job) => $job->orderId === $active->id);
    }

    public function test_disabled_integration_and_sync_queue_never_dispatch_or_call_provider(): void
    {
        Queue::fake();
        $this->order();
        RoboDeskConversationSetting::find(1)->update(['enabled' => false]);
        $this->assertSame(0, app(ActiveConversationRefreshService::class)->dispatchDue());
        RoboDeskConversationSetting::find(1)->update(['enabled' => true]);
        config(['queue.default' => 'sync']);
        $this->assertSame(0, app(ActiveConversationRefreshService::class)->dispatchDue());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_worker_refreshes_new_messages_using_cursor_without_order_mutations(): void
    {
        $order = $this->order();
        $before = $order->refresh()->getRawOriginal();
        $message = ['id' => 'first', 'date' => '2026-10-09T10:20:00.001Z', 'direction' => 'in', 'type' => 'text', 'text' => 'نص اختبار'];
        $this->fake($order, [$message]);
        app()->call([$this->job($order), 'handle']);
        $this->assertSame('first', WhatsAppConversation::first()->sync_cursor);
        $this->travel(10)->minutes();
        $this->fake($order, [array_replace($message, ['id' => 'second'])]);
        app()->call([$this->job($order), 'handle']);
        Http::assertSent(fn ($request) => ($request['after'] ?? null) === 'first');
        $this->assertDatabaseCount('whatsapp_conversation_messages', 2);
        $this->assertSame($before, $order->refresh()->getRawOriginal());
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_recent_sync_is_not_queued_or_refetched_until_ten_minutes(): void
    {
        Queue::fake();
        $order = $this->order();
        $this->fake($order);
        app()->call([$this->job($order), 'handle']);
        $this->assertSame(0, app(ActiveConversationRefreshService::class)->dispatchDue());
        app()->call([$this->job($order), 'handle']);
        Http::assertSentCount(1);
        $this->travel(11)->minutes();
        $this->assertSame(1, app(ActiveConversationRefreshService::class)->dispatchDue());
        Queue::assertPushed(RefreshActiveOrderConversation::class, 1);
    }

    public function test_worker_rechecks_phone_lifecycle_account_and_activation(): void
    {
        $order = $this->order();
        $job = $this->job($order);
        $order->update(['status' => 'cancelled']);
        app()->call([$job, 'handle']);
        $order->update(['status' => 'new', 'delivery_details' => ['phone' => '01112345678']]);
        app()->call([$job, 'handle']);
        config(['robodesk.conversations.account_key' => 'other-account']);
        app()->call([$job, 'handle']);
        config(['robodesk.conversations.account_key' => 'primary']);
        RoboDeskConversationSetting::find(1)->update(['enabled' => false]);
        app()->call([$this->job($order), 'handle']);
        Http::assertNothingSent();
        $this->assertDatabaseCount('whatsapp_conversations', 0);
    }

    public function test_authentication_failure_pauses_account_without_logging_provider_body_or_credentials(): void
    {
        Log::spy();
        Queue::fake();
        $order = $this->order();
        Http::fake(['hero-kid.robodesk.ai/*' => Http::response(['message' => 'PRIVATE_PROVIDER_BODY'], 401)]);
        app()->call([$this->job($order), 'handle']);
        Log::shouldHaveReceived('warning')->once()->with('RoboDesk background conversation refresh failed.', ['reason' => 'provider_authentication', 'order_id' => $order->id]);
        app()->call([$this->job($order), 'handle']);
        $this->assertSame(0, app(ActiveConversationRefreshService::class)->dispatchDue());
        Http::assertSentCount(1);
        $this->assertDatabaseCount('whatsapp_conversations', 0);
    }

    public function test_background_rate_and_worker_lock_limit_requests_across_contacts(): void
    {
        config(['robodesk.conversations.background_requests_per_minute' => 1]);
        $first = $this->order();
        $second = $this->order(1);
        $lock = Cache::lock('robodesk-background-worker:'.hash('sha256', 'primary'), 30);
        $lock->get();
        app()->call([$this->job($first), 'handle']);
        Http::assertNothingSent();
        $lock->release();
        $this->fake($first);
        app()->call([$this->job($first), 'handle']);
        app()->call([$this->job($second), 'handle']);
        Http::assertSentCount(1);
        $this->assertSame($this->job($first)->uniqueId(), $this->job($this->order())->uniqueId());
        $this->assertStringNotContainsString('01012345', json_encode($this->job($first)));
    }

    public function test_scheduler_scans_every_minute_without_overlapping_and_only_queues_background_work(): void
    {
        $event = collect(Schedule::events())->first(fn ($event) => str_contains($event->command ?? '', 'robodesk:sync-active-conversations'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        Queue::fake();
        $this->order();
        $this->artisan('robodesk:sync-active-conversations')->assertExitCode(0);
        Queue::assertPushed(RefreshActiveOrderConversation::class, 1);
        Http::assertNothingSent();
    }
}
