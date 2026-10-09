<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\RoboDeskConversationSetting;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Models\WhatsAppConversationReply;
use App\Services\RoboDesk\Conversations\ConversationHistoryPage;
use App\Services\RoboDesk\Conversations\OrderConversationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationReplyAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09T14:00:00Z'));
        Cache::flush();
        Http::preventStrayRequests();
        $this->employee = User::factory()->create(['role' => 'admin', 'name' => 'موظف اختبار']);
        $this->order = Order::create(['order_number' => 'SYNTHETIC-'.Str::uuid(), 'parent_name' => 'عميل اختبار', 'status' => 'new', 'delivery_details' => ['phone' => '01012345678']]);
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'synthetic@example.test', 'password' => 'test-only', 'conversation_limit' => 20]);
        Http::fake(['*/messagesByPhone?*' => Http::response(['phone' => '201012345678', 'messages' => [$this->remote('customer', 'in')]])]);
        app(OrderConversationService::class)->sync($this->order);
        $this->actingAs($this->employee);
    }

    private function remote(string $id, string $direction = 'out', array $changes = []): array
    {
        return $changes + ['id' => $id, 'direction' => $direction, 'channel' => 'WhatsApp', 'type' => 'text', 'text' => 'نص تجريبي 😊',
            'date' => now()->subHour()->toISOString(), 'status' => 'sent', 'attachments' => [], 'senderType' => $direction === 'out' ? 'integration' : null];
    }

    private function reply(array $data = [])
    {
        return $this->postJson(route('admin.orders.conversation.reply', $this->order), $data + ['request_id' => (string) Str::uuid(), 'text' => 'رد تجريبي 😊']);
    }

    private function accept(array $messages = []): void
    {
        Http::fake(['*/messagesByPhone/reply' => Http::response(['phone' => '201012345678', 'messages' => $messages ?: [$this->remote('reply-one')]])]);
    }

    private function assigned(User $employee, ?Order $order = null): void
    {
        OrderGroupAssignment::updateOrCreate(['checkout_group_key' => ($order ?? $this->order)->checkout_group_key], ['assigned_to_user_id' => $employee->id, 'assigned_by_user_id' => $employee->id, 'assigned_at' => now()]);
    }

    private function ownOnly(User $employee): void
    {
        $employee->permissions()->detach(Permission::where('key', 'orders.conversations.view-all')->value('id'));
        $employee->unsetRelation('permissions');
    }

    public function test_accepted_text_and_emoji_record_employee_without_changing_order_or_assignment(): void
    {
        $before = $this->order->refresh()->getRawOriginal();
        $cursor = WhatsAppConversation::first()->sync_cursor;
        $this->accept();
        $this->reply()->assertOk()->assertJsonPath('reply_state', 'accepted')->assertJsonPath('messages.1.employee_name', $this->employee->name);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://hero-kid.robodesk.ai/api/conversation/messagesByPhone/reply'
            && $r['phone'] === '201012345678' && $r['text'] === 'رد تجريبي 😊' && $r['channel'] === 'WhatsApp' && ! isset($r['attachment']));
        $this->assertSame($before, $this->order->refresh()->getRawOriginal());
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->assertSame($cursor, WhatsAppConversation::first()->sync_cursor);
        $row = WhatsAppConversationMessage::where('direction', 'outbound')->first();
        $this->assertSame($this->employee->id, $row->employee_id);
        $this->assertNotSame($this->employee->name, DB::table('whatsapp_conversation_messages')->where('id', $row->id)->value('employee_name'));
        $this->assertDatabaseCount('whatsapp_conversation_reads', 0);
    }

    public function test_same_request_is_not_sent_twice_and_different_payload_is_rejected(): void
    {
        $this->accept();
        $id = (string) Str::uuid();
        $this->reply(['request_id' => $id])->assertOk();
        $this->reply(['request_id' => $id])->assertOk();
        $this->reply(['request_id' => $id, 'text' => 'رسالة مختلفة'])->assertStatus(409)->assertJsonPath('code', 'REQUEST_CONFLICT');
        Http::assertSentCount(1); // fake() resets recorded requests; exactly one reply.
        $this->assertDatabaseCount('whatsapp_conversation_replies', 1);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 2);
    }

    public function test_image_only_uses_private_disk_and_expiring_public_signed_stream(): void
    {
        Storage::fake('s3_private');
        config(['media.private_disk' => 's3_private']);
        URL::forceScheme('https');
        $this->accept([$this->remote('reply-image', 'out', ['type' => 'image', 'attachments' => [['file' => 'image.jpg', 'url' => 'https://cdn.example.test/signed-secret']]])]);
        $this->reply(['text' => '', 'image' => UploadedFile::fake()->image('private-child.jpg')])->assertOk()->assertJsonPath('messages.1.kind', 'image');
        $row = WhatsAppConversationReply::first();
        $this->assertSame('s3_private', $row->attachment_disk);
        Storage::disk('s3_private')->assertExists($row->attachment_path);
        $url = URL::temporarySignedRoute('conversation-reply-media', $row->attachment_expires_at, ['reply' => $row->request_id]);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['attachment']['type'] === 'image' && $r['attachment']['url'] === $url);
        $this->get(route('conversation-reply-media', $row->request_id))->assertForbidden();
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(str_replace($row->request_id, (string) Str::uuid(), $url))->assertForbidden();
        $this->assertStringNotContainsString('signed-secret', DB::table('whatsapp_conversation_messages')->where('direction', 'outbound')->value('attachments'));
        Storage::disk('s3_private')->put('admin/media-library/files/keep.jpg', 'persistent');
        Storage::disk('s3_private')->put('order-photos/keep.jpg', 'persistent');
        $this->travel(25)->hours();
        $this->get($url)->assertForbidden();
        $this->artisan('robodesk:cleanup-reply-media')->assertSuccessful();
        Storage::disk('s3_private')->assertMissing($row->attachment_path);
        Storage::disk('s3_private')->assertExists('admin/media-library/files/keep.jpg');
        Storage::disk('s3_private')->assertExists('order-photos/keep.jpg');
    }

    public function test_invalid_input_and_oversized_image_never_send(): void
    {
        $this->reply(['text' => ''])->assertUnprocessable();
        $this->reply(['text' => str_repeat('x', 4097)])->assertUnprocessable();
        $this->reply(['request_id' => 'invalid'])->assertUnprocessable();
        $this->reply(['phone' => '201099999999'])->assertUnprocessable();
        $this->reply(['attachment' => ['url' => 'http://127.0.0.1']])->assertUnprocessable();
        $this->reply(['image' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')])->assertUnprocessable();
        $this->reply(['image' => UploadedFile::fake()->image('large.jpg')->size(5121)])->assertUnprocessable();
        $this->reply(['image' => UploadedFile::fake()->create('fake.png', 1, 'image/png')])->assertUnprocessable();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('whatsapp_conversation_replies', 0);
    }

    public function test_reply_window_expires_at_exactly_24_hours_and_outbound_or_reaction_does_not_reopen_it(): void
    {
        WhatsAppConversationMessage::where('direction', 'inbound')->first()->update(['sent_at' => now()->subDay()]);
        $this->reply()->assertStatus(409)->assertJsonPath('code', 'WINDOW_CLOSED');
        $conversation = WhatsAppConversation::first();
        app(OrderConversationService::class)->validate(new ConversationHistoryPage('201012345678', [$this->remote('reaction', 'in', ['type' => 'reaction'])]), '+201012345678');
        $reaction = WhatsAppConversationMessage::where('direction', 'inbound')->first()->replicate();
        $reaction->remote_id = 'reaction';
        $reaction->remote_hash = hash('sha256', 'reaction');
        $reaction->kind = 'reaction';
        $reaction->sent_at = now();
        $reaction->save();
        $this->reply()->assertStatus(409)->assertJsonPath('code', 'WINDOW_CLOSED');
        $this->assertDatabaseCount('whatsapp_conversation_replies', 0);
        Http::assertSentCount(1);
    }

    public static function refusals(): array
    {
        return [[400, 'INVALID_PHONE'], [400, 'EMPTY_REPLY'], [400, 'TEXT_TOO_LONG'], [400, 'INVALID_ATTACHMENT'],
            [409, 'WINDOW_CLOSED'], [409, 'NO_OPEN_CONVERSATION'], [502, 'SEND_FAILED']];
    }

    #[DataProvider('refusals')]
    public function test_provider_refusal_is_shown_and_never_added_to_history(int $status, string $code): void
    {
        Http::fake(['*/messagesByPhone/reply' => Http::response(['code' => $code, 'message' => 'سبب الرفض من واتساب'], $status)]);
        $id = (string) Str::uuid();
        $this->reply(['request_id' => $id])->assertStatus($status)->assertJsonPath('code', $code)->assertJsonPath('message', 'سبب الرفض من واتساب');
        $this->reply(['request_id' => $id])->assertStatus($status);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
        $this->assertSame('failed', WhatsAppConversationReply::first()->state);
    }

    public function test_timeout_is_uncertain_and_retry_does_not_send_again(): void
    {
        Http::fake(['*/messagesByPhone/reply' => fn () => throw new ConnectionException('private provider failure')]);
        $id = (string) Str::uuid();
        $this->reply(['request_id' => $id])->assertStatus(502)->assertJsonPath('code', 'SEND_UNCERTAIN')->assertDontSee('private provider failure');
        $this->reply(['request_id' => $id])->assertStatus(502)->assertJsonPath('code', 'SEND_UNCERTAIN');
        $this->assertSame('unknown', WhatsAppConversationReply::first()->state);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
    }

    public function test_database_failure_after_acceptance_does_not_repeat_remote_send(): void
    {
        $this->accept();
        Event::listen('eloquent.creating: '.WhatsAppConversationMessage::class, fn () => throw new \RuntimeException('synthetic DB failure'));
        $id = (string) Str::uuid();
        $this->reply(['request_id' => $id])->assertStatus(502)->assertJsonPath('code', 'SEND_UNCERTAIN');
        $this->reply(['request_id' => $id])->assertStatus(502);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
        $this->assertSame('unknown', WhatsAppConversationReply::first()->state);
    }

    public function test_malformed_or_wrong_contact_acceptance_is_not_persisted(): void
    {
        Http::fake(['*/messagesByPhone/reply' => Http::response(['phone' => '201099999999', 'messages' => [$this->remote('wrong')]])]);
        $this->reply()->assertStatus(502)->assertJsonPath('code', 'SEND_UNCERTAIN');
        $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
    }

    public function test_failed_image_send_removes_temporary_copy_and_creates_no_message(): void
    {
        Storage::fake('local');
        URL::forceScheme('https');
        Http::fake(['*/messagesByPhone/reply' => Http::response(['code' => 'SEND_FAILED', 'message' => 'رفض الصورة'], 502)]);
        $this->reply(['image' => UploadedFile::fake()->image('test.jpg')])->assertStatus(502)->assertJsonPath('code', 'SEND_FAILED');
        $this->assertSame([], Storage::disk('local')->allFiles('robodesk/replies'));
        $this->assertNull(WhatsAppConversationReply::first()->attachment_path);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
    }

    public function test_employee_metadata_survives_later_provider_status_updates(): void
    {
        $this->accept();
        $this->reply()->assertOk();
        $this->travel(20)->seconds();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['phone' => '201012345678', 'messages' => [$this->remote('reply-one', 'out', ['status' => 'read', 'agentName' => 'Integration'])]])]);
        app(OrderConversationService::class)->sync($this->order);
        $row = WhatsAppConversationMessage::where('direction', 'outbound')->first();
        $this->assertSame($this->employee->name, $row->employee_name);
        $this->assertSame($this->employee->id, $row->employee_id);
        $this->assertSame('read', $row->delivery_status);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 2);
    }

    public function test_disabled_integration_cannot_send_and_all_customer_scope_does_not_require_assignment(): void
    {
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertOk();
        RoboDeskConversationSetting::find(1)->update(['enabled' => false]);
        $this->reply()->assertStatus(422)->assertJsonPath('code', 'SETUP_REQUIRED');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('whatsapp_conversation_replies', 0);
    }

    public function test_notification_auth_permission_and_cross_customer_read_cursor_are_enforced(): void
    {
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 999])->assertUnprocessable();
        $this->employee->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->employee->unsetRelation('permissions');
        $this->getJson(route('admin.orders.conversation.notifications'))->assertForbidden();
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 1])->assertForbidden();
        $this->assertDatabaseCount('whatsapp_conversation_reads', 0);
        Http::assertSentCount(1);
    }

    public function test_reply_permission_is_separate_from_read_and_requires_order_access(): void
    {
        $this->employee->permissions()->detach(Permission::where('key', 'orders.conversations.reply')->value('id'));
        $this->employee->unsetRelation('permissions');
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertOk();
        $this->reply()->assertForbidden();
        $this->ownOnly($this->employee);
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertForbidden();
        $this->postJson(route('admin.orders.conversation.sync', $this->order))->assertForbidden();
        $this->assigned($this->employee);
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertOk();
        $this->employee->update(['is_active' => false]);
        $this->reply()->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_reassignment_revokes_own_only_read_sync_reply_and_mark_read(): void
    {
        $this->ownOnly($this->employee);
        $this->assigned($this->employee);
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertOk();
        $other = User::factory()->create(['role' => 'admin']);
        $this->assigned($other);
        foreach (['sync', 'reply', 'read'] as $action) {
            $this->postJson(route('admin.orders.conversation.'.$action, $this->order))->assertForbidden();
        }
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertForbidden();
        $this->getJson(route('admin.orders.conversation.notifications'))->assertJsonPath('count', 0);
    }

    public function test_notifications_count_contacts_not_orders_and_get_does_not_mark_read(): void
    {
        $this->assigned($this->employee);
        $second = $this->order->replicate();
        $second->order_number = 'SYNTHETIC-'.Str::uuid();
        $second->checkout_group_key = 'SYNTHETIC-GROUP-'.Str::uuid();
        $second->save();
        $this->assigned($this->employee, $second);
        $url = route('admin.orders.conversation.notifications');
        $this->getJson($url)->assertOk()->assertJsonPath('count', 1)->assertJsonCount(1, 'items');
        $this->getJson(route('admin.orders.conversation.show', $this->order))->assertOk();
        $this->getJson($url)->assertJsonPath('count', 1);
        $this->assertDatabaseCount('whatsapp_conversation_reads', 0);
        Http::assertSentCount(1);
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 1])->assertOk();
        $this->getJson($url)->assertJsonPath('count', 0);
        $this->assertDatabaseCount('whatsapp_conversation_reads', 1);
    }

    public function test_read_acknowledgement_is_per_employee_monotonic_and_does_not_clear_later_message(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $this->assigned($this->employee);
        $conversation = WhatsAppConversation::first();
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 1])->assertOk();
        $conversation->update(['inbound_version' => 2]);
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 1])->assertOk();
        $this->getJson(route('admin.orders.conversation.notifications'))->assertJsonPath('count', 1);
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 3])->assertUnprocessable();
        $this->assigned($other);
        $this->actingAs($other);
        $this->getJson(route('admin.orders.conversation.notifications'))->assertJsonPath('count', 1);
        $this->assertSame(1, DB::table('whatsapp_conversation_reads')->where('user_id', $this->employee->id)->value('inbound_version'));
    }

    public function test_finished_cancelled_deleted_and_unassigned_orders_do_not_notify(): void
    {
        $url = route('admin.orders.conversation.notifications');
        $this->getJson($url)->assertJsonPath('count', 0);
        $this->assigned($this->employee);
        $this->getJson($url)->assertJsonPath('count', 1);
        $this->order->update(['status' => 'cancelled']);
        $this->getJson($url)->assertJsonPath('count', 0);
        $this->order->update(['status' => 'delivered', 'payment_status' => 'paid_in_full', 'printing_status' => 'completed', 'shipping_status' => 'delivered']);
        $this->getJson($url)->assertJsonPath('count', 0);
        $this->order->delete();
        $this->getJson($url)->assertJsonPath('count', 0);
    }

    public function test_inbound_delta_marks_unread_but_duplicate_and_outbound_status_updates_do_not(): void
    {
        $this->assigned($this->employee);
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 1])->assertOk();
        $this->travel(20)->seconds();
        Http::fake(['*/messagesByPhone?*' => Http::response(['phone' => '201012345678', 'messages' => [$this->remote('customer', 'in'), $this->remote('new-customer', 'in')]])]);
        // Swap the factory so the initial GET fake cannot shadow this delta.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['phone' => '201012345678', 'messages' => [$this->remote('customer', 'in'), $this->remote('new-customer', 'in')]])]);
        app(OrderConversationService::class)->sync($this->order);
        $this->assertSame(2, (int) WhatsAppConversation::first()->inbound_version);
        $this->getJson(route('admin.orders.conversation.notifications'))->assertJsonPath('count', 1);
        $this->postJson(route('admin.orders.conversation.read', $this->order), ['version' => 2])->assertOk();
        $this->travel(20)->seconds();
        app(OrderConversationService::class)->sync($this->order);
        $this->assertSame(2, (int) WhatsAppConversation::first()->inbound_version);
        $this->getJson(route('admin.orders.conversation.notifications'))->assertJsonPath('count', 0);
    }
}
