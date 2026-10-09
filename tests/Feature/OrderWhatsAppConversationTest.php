<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\Order;
use App\Models\Permission;
use App\Models\RoboDeskConversationSetting;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppConversationMessage;
use App\Services\RoboDesk\Conversations\ConversationPhone;
use App\Services\RoboDesk\Conversations\RoboDeskConversationHistoryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderWhatsAppConversationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        Http::preventStrayRequests();
        Cache::flush();
    }

    private function order(string $phone = '01012345678'): Order
    {
        return Order::create(['order_number' => 'SYNTHETIC-'.fake()->uuid(), 'parent_name' => 'عميل اختبار',
            'status' => 'new', 'delivery_details' => ['phone' => $phone, 'address' => 'Synthetic private address']]);
    }

    private function enable(): void
    {
        RoboDeskConversationSetting::create(['id' => 1, 'enabled' => true, 'email' => 'integration@example.test', 'password' => 'synthetic-secret', 'conversation_limit' => 20]);
    }

    private function message(string $id = 'remote-one', array $changes = []): array
    {
        return $changes + ['id' => $id, 'direction' => 'out', 'channel' => 'WhatsApp', 'date' => '2026-10-09T10:20:00.001Z',
            'type' => 'text', 'text' => " نص اختبار \nبدون تعديل ", 'status' => 'delivered', 'senderType' => 'agent', 'agentName' => 'موظف اختبار',
            'attachments' => []];
    }

    private function fake(array $messages, string $phone = '201012345678'): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['hero-kid.robodesk.ai/*' => Http::response(['phone' => $phone, 'interactions' => [], 'messages' => $messages])]);
    }

    private function sync(Order $order, array $data = [])
    {
        return $this->actingAs($this->admin)->postJson(route('admin.orders.conversation.sync', $order), $data);
    }

    public function test_get_is_read_only_and_unconfigured_post_does_not_create_records(): void
    {
        $order = $this->order();
        $this->actingAs($this->admin)->getJson(route('admin.orders.conversation.show', $order))->assertOk()->assertJsonPath('state', 'setup_required')
            ->assertJsonPath('contact_title', $order->parent_name)->assertJsonPath('order_reference', $order->checkoutReference->short_reference)
            ->assertJsonPath('order_url', route('admin.orders.groups.show', $order))->assertJsonCount(0, 'messages');
        $this->sync($order)->assertStatus(422)->assertJsonPath('reason', 'setup_required');
        $this->assertDatabaseCount('whatsapp_conversations', 0);
        Http::assertNothingSent();
    }

    public function test_configuration_is_encrypted_password_is_never_rendered_and_empty_password_keeps_it(): void
    {
        $url = route('admin.robodesk.conversation-settings.update');
        $this->actingAs($this->admin)->put($url, ['email' => 'integration@example.test', 'password' => 'synthetic-secret', 'enabled' => 1, 'conversation_limit' => 50])->assertRedirect();
        $stored = DB::table('robodesk_conversation_settings')->first();
        $this->assertNotSame('synthetic-secret', $stored->password);
        $this->assertNotSame('integration@example.test', $stored->email);
        $this->assertSame('synthetic-secret', RoboDeskConversationSetting::find(1)->password);
        $this->get(route('admin.robodesk.conversation-settings.edit'))->assertOk()->assertDontSee('synthetic-secret');
        $this->put($url, ['email' => 'integration@example.test', 'password' => '', 'enabled' => 1, 'conversation_limit' => 20])->assertRedirect();
        $this->assertSame('synthetic-secret', RoboDeskConversationSetting::find(1)->password);
        $logs = AdminActivityLog::where('route_name', 'admin.robodesk.conversation-settings.update')->get()->toJson();
        $this->assertStringNotContainsString('synthetic-secret', $logs);
        $this->assertStringNotContainsString('integration@example.test', $logs);
        $this->assertArrayNotHasKey('password', RoboDeskConversationSetting::find(1)->toArray());
    }

    public function test_credentials_limits_and_activation_are_validated(): void
    {
        $url = route('admin.robodesk.conversation-settings.update');
        $this->actingAs($this->admin)->putJson($url, ['email' => 'invalid', 'enabled' => 1, 'conversation_limit' => 51])->assertUnprocessable()->assertJsonValidationErrors(['email', 'conversation_limit']);
        $this->putJson($url, ['email' => 'integration@example.test', 'enabled' => 1, 'conversation_limit' => 20])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('robodesk_conversation_settings', 0);
    }

    public function test_permissions_authentication_and_disabled_users_are_enforced(): void
    {
        $order = $this->order();
        $this->getJson(route('admin.orders.conversation.show', $order))->assertUnauthorized();
        $this->admin->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->actingAs($this->admin)->getJson(route('admin.orders.conversation.show', $order))->assertForbidden();
        $this->sync($order)->assertForbidden();
        $this->get(route('admin.robodesk.conversation-settings.edit'))->assertForbidden();
        $this->admin->permissions()->sync(Permission::where('key', 'orders.conversations.view')->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->getJson(route('admin.orders.conversation.show', $order))->assertForbidden();
        $this->admin->update(['is_active' => false]);
        $this->getJson(route('admin.orders.conversation.show', $order))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_actual_robodesk_contract_auth_phone_and_channel_are_used(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk()->assertJsonPath('messages.0.direction', 'outbound')->assertJsonPath('messages.0.text', $this->message()['text']);
        Http::assertSent(fn ($request) => $request->url() === 'https://hero-kid.robodesk.ai/api/conversation/messagesByPhone?phone=201012345678&channel=WhatsApp&limit=20'
            && $request->hasHeader('Authorization', RoboDeskConversationHistoryProvider::authorization('integration@example.test', 'synthetic-secret')));
        $this->assertSame('new', $order->refresh()->status);
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_js_legacy_hash_matches_signed_32_bit_and_utf16_surrogates(): void
    {
        $this->assertSame(base64_encode('base64:a:96354'), RoboDeskConversationHistoryProvider::authorization('a', 'abc'));
        $this->assertSame(base64_encode('base64:a:1772899'), RoboDeskConversationHistoryProvider::authorization('a', '😀'));
        $this->assertSame(base64_encode('base64:a:-1876030920'), RoboDeskConversationHistoryProvider::authorization('a', 'synthetic-secret'));
    }

    public function test_production_shape_with_system_logs_keeps_all_29_actual_messages(): void
    {
        $this->enable();
        $order = $this->order();
        $before = $order->refresh()->getRawOriginal();
        $messages = [];
        foreach ([['in', 'text', 15], ['out', 'text', 10], ['in', 'image', 1], ['out', 'reaction', 2], ['in', 'reaction', 1]] as [$direction, $type, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $messages[] = $this->message('synthetic-'.$direction.'-'.$type.'-'.$i, ['direction' => $direction, 'type' => $type,
                    'senderType' => $direction === 'out' ? str_repeat('s', 21) : null]);
            }
        }
        for ($i = 0; $i < 4; $i++) {
            array_splice($messages, $i * 2, 0, [['id' => 'system-'.$i, 'direction' => 'system', 'type' => 'systemLog', 'text' => 'PRIVATE_SYNTHETIC_SYSTEM_LOG']]);
        }
        $this->fake($messages);
        $this->sync($order)->assertOk()->assertJsonCount(29, 'messages')->assertDontSee('PRIVATE_SYNTHETIC_SYSTEM_LOG');
        $this->assertDatabaseCount('whatsapp_conversation_messages', 29);
        $this->assertSame(17, WhatsAppConversationMessage::where('direction', 'inbound')->count());
        $this->assertSame(12, WhatsAppConversationMessage::where('direction', 'outbound')->count());
        $this->assertSame(3, WhatsAppConversationMessage::where('kind', 'reaction')->count());
        $this->assertSame(1, WhatsAppConversationMessage::where('kind', 'image')->count());
        $this->assertSame(0, WhatsAppConversationMessage::where('kind', 'systemLog')->count());
        $this->assertSame(12, WhatsAppConversationMessage::where('sender_type', str_repeat('s', 21))->count());
        $this->assertSame($before, $order->refresh()->getRawOriginal());
        $this->assertDatabaseCount('order_group_assignments', 0);
        $this->getJson(route('admin.orders.conversation.show', $order))->assertOk()->assertJsonCount(29, 'messages')->assertDontSee('PRIVATE_SYNTHETIC_SYSTEM_LOG');
    }

    public function test_sender_type_metadata_up_to_64_characters_is_preserved_verbatim(): void
    {
        $this->enable();
        $order = $this->order();
        $senderType = str_repeat('s', 64);
        $this->fake([$this->message('long-sender', ['senderType' => $senderType]), $this->message('null-sender', ['senderType' => null])]);
        $this->sync($order)->assertOk()->assertJsonCount(2, 'messages')
            ->assertJsonPath('messages.0.sender_type', $senderType)->assertJsonPath('messages.1.sender_type', null);
        $this->assertSame($senderType, WhatsAppConversationMessage::orderBy('id')->first()->sender_type);
        $this->getJson(route('admin.orders.conversation.show', $order))->assertOk()->assertJsonPath('messages.0.sender_type', $senderType);
    }

    public function test_invalid_sender_type_preserves_existing_history_and_cursor_atomically(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk();
        $existing = WhatsAppConversationMessage::first()->getRawOriginal();
        foreach ([str_repeat('s', 65), ['invalid'], 123] as $senderType) {
            $this->travel(15)->seconds();
            $this->fake([$this->message('valid-new'), $this->message('invalid-new', ['senderType' => $senderType])]);
            $this->sync($order)->assertStatus(502)->assertJsonPath('reason', 'invalid_provider_response');
            $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
            $this->assertSame($existing, WhatsAppConversationMessage::first()->getRawOriginal());
            $this->assertSame('remote-one', WhatsAppConversation::first()->sync_cursor);
        }
    }

    public function test_system_only_delta_advances_cursor_without_storing_or_removing_messages(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message(), ['id' => 'system-one', 'direction' => 'system', 'type' => 'systemLog']]);
        $data = $this->sync($order)->assertOk()->assertJsonCount(1, 'messages')->json();
        $this->assertSame('system-one', WhatsAppConversation::first()->sync_cursor);
        $this->travel(15)->seconds();
        $this->fake([['id' => 'system-two', 'direction' => 'system', 'type' => 'systemLog']]);
        $this->sync($order)->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.id', $data['messages'][0]['id']);
        Http::assertSent(fn ($request) => ($request['after'] ?? null) === 'system-one');
        $this->assertSame('system-two', WhatsAppConversation::first()->sync_cursor);
        $this->travel(15)->seconds();
        $this->fake([]);
        $this->sync($order)->assertOk()->assertJsonCount(1, 'messages');
        Http::assertSent(fn ($request) => ($request['after'] ?? null) === 'system-two');
        $this->assertSame('system-two', WhatsAppConversation::first()->sync_cursor);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 1);
    }

    public function test_initial_system_only_response_is_safe_empty_history_with_a_cursor(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([['id' => 'system-one', 'direction' => 'system', 'type' => 'systemLog']]);
        $this->sync($order)->assertOk()->assertJsonCount(0, 'messages');
        $this->assertSame('system-one', WhatsAppConversation::first()->sync_cursor);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 0);
    }

    public function test_system_log_handling_does_not_hide_other_invalid_records_or_unsafe_cursors(): void
    {
        $this->enable();
        $order = $this->order();
        foreach ([['direction' => 'system', 'type' => 'text'], ['direction' => 'in', 'type' => 'systemLog'],
            ['direction' => 'unknown', 'type' => 'text'], ['id' => '', 'direction' => 'system', 'type' => 'systemLog']] as $invalid) {
            $this->fake([$this->message(), $this->message('bad', $invalid)]);
            $this->sync($order)->assertStatus(502)->assertJsonPath('reason', 'invalid_provider_response');
            $this->assertDatabaseCount('whatsapp_conversations', 0);
            $this->assertDatabaseCount('whatsapp_conversation_messages', 0);
        }
    }

    public function test_filtered_system_only_response_still_rejects_another_customers_phone(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([['id' => 'system-one', 'direction' => 'system', 'type' => 'systemLog']], '201112345678');
        $this->sync($order)->assertStatus(502)->assertJsonPath('reason', 'invalid_provider_response');
        $this->assertDatabaseCount('whatsapp_conversations', 0);
    }

    public function test_phone_normalization_deduplicates_same_customer_orders_and_accounts_are_isolated(): void
    {
        $this->enable();
        $first = $this->order('٠١٠١٢٣٤٥٦٧٨');
        $second = $this->order('+20 101-234-5678');
        $this->fake([$this->message()]);
        $one = $this->sync($first)->assertOk()->json();
        $two = $this->sync($second)->assertOk()->json();
        $this->assertSame($one['key'], $two['key']);
        $this->assertDatabaseCount('whatsapp_conversations', 1);
        Http::assertSentCount(1);
        config(['robodesk.conversations.account_key' => 'other-account']);
        $this->sync($second)->assertOk();
        $this->assertDatabaseCount('whatsapp_conversations', 2);
    }

    public function test_unknown_phone_is_rejected_without_provider_request(): void
    {
        $this->enable();
        $this->sync($this->order('123'))->assertUnprocessable()->assertJsonValidationErrors('phone');
        Http::assertNothingSent();
        $this->assertSame('+447911123456', ConversationPhone::canonical('00447911123456'));
        $this->assertNull(ConversationPhone::canonical('invalid'));
    }

    public function test_incremental_after_cursor_new_messages_empty_delta_and_repeat_are_deduplicated(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk();
        $this->travel(15)->seconds();
        $this->fake([$this->message(), $this->message('remote-two', ['direction' => 'in'])]);
        $this->sync($order)->assertOk()->assertJsonCount(2, 'messages');
        Http::assertSent(fn ($request) => ($request['after'] ?? null) === 'remote-one');
        $this->assertSame('remote-two', WhatsAppConversation::first()->sync_cursor);
        $this->travel(15)->seconds();
        $this->fake([]);
        $this->sync($order)->assertOk()->assertJsonCount(2, 'messages');
        $this->assertSame('remote-two', WhatsAppConversation::first()->sync_cursor);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 2);
    }

    public function test_invalid_after_retries_once_without_cursor_and_empty_fallback_clears_it(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk();
        $this->travel(15)->seconds();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['hero-kid.robodesk.ai/*' => Http::sequence()->push(['message' => 'bad cursor'], 400)->push(['phone' => '201012345678', 'messages' => []])]);
        $this->sync($order)->assertOk()->assertJsonCount(1, 'messages');
        $this->assertNull(WhatsAppConversation::first()->sync_cursor);
        $requests = Http::recorded();
        $this->assertArrayNotHasKey('after', $requests[1][0]->data());
    }

    public function test_full_refresh_updates_existing_messages_and_unchanged_rows_keep_timestamp(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk();
        $message = WhatsAppConversationMessage::first();
        $id = $message->id;
        $updated = $message->updated_at->toISOString();
        $this->travel(15)->seconds();
        $this->sync($order, ['full' => 1])->assertOk();
        $this->assertSame($updated, $message->refresh()->updated_at->toISOString());
        $this->travel(15)->seconds();
        $this->fake([$this->message('remote-one', ['text' => 'نص محدّث', 'status' => 'read'])]);
        $this->sync($order, ['full' => 1])->assertOk()->assertJsonPath('messages.0.status', 'read');
        $this->assertSame($id, $message->refresh()->id);
        $this->assertSame('نص محدّث', $message->body);
        Http::assertSent(fn ($request) => ! isset($request['after']));
    }

    public function test_attachment_urls_are_transient_safe_and_never_saved_or_returned_on_cached_get(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message('remote-one', ['attachments' => [
            ['file' => 'test.jpg', 'url' => 'https://signed.example.test/test?secret=short-lived'],
            ['file' => 'bad.html', 'url' => 'javascript:alert(1)'], ['file' => 'null.pdf', 'url' => null],
        ]])]);
        $this->sync($order)->assertOk()->assertJsonPath('messages.0.attachments.0.url', 'https://signed.example.test/test?secret=short-lived')->assertJsonPath('messages.0.attachments.1.url', null);
        $this->assertStringNotContainsString('short-lived', json_encode(WhatsAppConversationMessage::first()->attachments));
        $this->assertNotSame($this->message()['text'], DB::table('whatsapp_conversation_messages')->value('body'));
        $this->assertNotSame('remote-one', DB::table('whatsapp_conversation_messages')->value('remote_id'));
        $this->actingAs($this->admin)->getJson(route('admin.orders.conversation.show', $order))->assertOk()->assertJsonMissingPath('messages.0.attachments.0.url')->assertJsonMissingPath('messages.0.remote_id');
    }

    public function test_cross_customer_and_malformed_responses_are_rejected_atomically(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message()], '201112345678');
        $this->sync($order)->assertStatus(502)->assertJsonPath('reason', 'invalid_provider_response');
        $this->fake([$this->message(), $this->message('bad', ['direction' => 'unknown'])]);
        $this->sync($order)->assertStatus(502);
        $this->assertDatabaseCount('whatsapp_conversation_messages', 0);
        $this->assertDatabaseCount('whatsapp_conversations', 0);
    }

    public function test_provider_failure_preserves_history_and_never_exposes_provider_error_details(): void
    {
        $this->enable();
        $order = $this->order();
        $before = $order->refresh()->getRawOriginal();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk();
        $this->travel(15)->seconds();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['hero-kid.robodesk.ai/*' => Http::response(['message' => 'PRIVATE_PROVIDER_ERROR synthetic-secret'], 401)]);
        $this->sync($order)->assertStatus(502)->assertJsonPath('reason', 'provider_authentication')->assertDontSee('PRIVATE_PROVIDER_ERROR')->assertDontSee('synthetic-secret');
        $this->getJson(route('admin.orders.conversation.show', $order))->assertOk()->assertJsonCount(1, 'messages');
        $this->assertSame($before, $order->refresh()->getRawOriginal());
        $this->assertDatabaseCount('order_group_assignments', 0);
    }

    public function test_saved_history_paginates_and_foreign_cursor_is_rejected(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake(array_map(fn ($i) => $this->message('remote-'.$i), range(1, 65)));
        $data = $this->sync($order)->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true)->json();
        $this->getJson(route('admin.orders.conversation.show', $order).'?before='.$data['older_before'])->assertOk()->assertJsonCount(15, 'messages')->assertJsonPath('has_more', false);
        $this->getJson(route('admin.orders.conversation.show', $this->order('01112345678')).'?before='.$data['older_before'])->assertOk()->assertJsonCount(0, 'messages');
        $this->getJson(route('admin.orders.conversation.show', $order).'?before=999999')->assertUnprocessable();
    }

    public function test_empty_history_and_disabled_configuration_keep_existing_history(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([]);
        $this->sync($order)->assertOk()->assertJsonCount(0, 'messages');
        $this->travel(15)->seconds();
        $this->fake([$this->message()]);
        $this->sync($order)->assertOk();
        RoboDeskConversationSetting::find(1)->update(['enabled' => false]);
        $this->getJson(route('admin.orders.conversation.show', $order))->assertOk()->assertJsonPath('state', 'setup_required')->assertJsonCount(1, 'messages');
    }

    public function test_disappearing_messages_in_full_window_are_not_deleted(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message('one'), $this->message('two')]);
        $this->sync($order)->assertOk();
        $this->travel(15)->seconds();
        $this->fake([$this->message('two')]);
        $this->sync($order, ['full' => 1])->assertOk()->assertJsonCount(2, 'messages');
    }

    public function test_active_sync_lock_defers_without_network(): void
    {
        $this->enable();
        $order = $this->order();
        $key = 'robodesk-history:'.hash('sha256', 'primary'.hash('sha256', '+201012345678'));
        $lock = Cache::lock($key, 30);
        $lock->get();
        try {
            $this->sync($order)->assertOk()->assertJsonPath('refresh_deferred', true);
            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_message_body_html_is_preserved_as_inert_plain_text(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message('html', ['text' => '<script>alert(1)</script>'])]);
        $this->sync($order)->assertOk()->assertJsonPath('messages.0.text', '<script>alert(1)</script>');
        $this->assertSame('<script>alert(1)</script>', WhatsAppConversationMessage::first()->body);
    }

    public function test_persistence_failure_rolls_back_entire_page_and_cursor(): void
    {
        $this->enable();
        $order = $this->order();
        $this->fake([$this->message('one'), $this->message('two')]);
        $event = 'eloquent.creating: '.WhatsAppConversationMessage::class;
        Event::listen($event, function ($message): void {
            if ($message->remote_id === 'two') {
                throw new \RuntimeException('Private database failure');
            }
        });
        try {
            $this->sync($order)->assertStatus(502)->assertDontSee('Private database failure');
            $this->assertDatabaseCount('whatsapp_conversations', 0);
            $this->assertDatabaseCount('whatsapp_conversation_messages', 0);
        } finally {
            Event::forget($event);
        }
    }

    public function test_millisecond_ordering_and_contacts_remain_independent(): void
    {
        $this->enable();
        $first = $this->order();
        $second = $this->order('01112345678');
        $this->fake([$this->message('later', ['date' => '2026-10-09T10:20:00.900Z']), $this->message('earlier', ['date' => '2026-10-09T10:20:00.100Z'])]);
        $this->sync($first)->assertOk()->assertJsonPath('messages.0.date', '2026-10-09T10:20:00.100000Z');
        $this->assertSame('earlier', WhatsAppConversation::first()->messages()->orderBy('sent_at')->first()->remote_id);
        $this->fake([$this->message('other')], '201112345678');
        $data = $this->sync($second)->assertOk()->assertJsonCount(1, 'messages')->json();
        $this->getJson(route('admin.orders.conversation.show', $first).'?before='.$data['messages'][0]['id'])->assertUnprocessable();
        $this->assertDatabaseCount('whatsapp_conversations', 2);
    }

    public function test_missing_and_trashed_orders_cannot_access_history(): void
    {
        $order = $this->order();
        $order->delete();
        $this->actingAs($this->admin)->getJson(route('admin.orders.conversation.show', $order))->assertNotFound();
        $this->postJson(route('admin.orders.conversation.sync', 99999))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_csrf_protection_is_not_bypassed(): void
    {
        $this->enable();
        $order = $this->order();
        $this->app->detectEnvironment(fn () => 'local');
        try {
            $this->sync($order)->assertStatus(419);
            Http::assertNothingSent();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_ui_buttons_are_permission_guarded_and_settings_password_is_not_flashed(): void
    {
        $this->actingAs($this->admin);
        $this->blade('@include("admin.orders._conversation-button", ["conversationOrderId" => 1, "conversationCustomerName" => "عميل"])')->assertSee('data-order-conversation', false);
        $this->admin->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->blade('@include("admin.orders._conversation-button", ["conversationOrderId" => 1, "conversationCustomerName" => "عميل"])')->assertDontSee('data-order-conversation', false);
        $this->admin->permissions()->sync(Permission::pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->put(route('admin.robodesk.conversation-settings.update'), ['email' => 'invalid', 'password' => 'synthetic-secret', 'conversation_limit' => 0])->assertSessionHasErrors();
        $this->assertNull(session()->getOldInput('password'));
    }

    public function test_dock_restoration_metadata_is_employee_scoped_and_permission_guarded(): void
    {
        $this->actingAs($this->admin);
        $key = 'hk-conversations:v1:'.$this->admin->id.':'.hash('sha256', 'primary');
        $this->blade('@include("admin.orders._conversation-dock")')->assertSee($key, false)
            ->assertSee('data-conversation-bubbles', false)->assertSee('data-history-template', false)->assertSee('__ORDER__', false);
        config(['robodesk.conversations.account_key' => 'other-account']);
        $this->blade('@include("admin.orders._conversation-dock")')->assertDontSee($key, false);
        $this->admin->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $this->admin->unsetRelation('permissions');
        $this->blade('@include("admin.orders._conversation-dock")')->assertDontSee('data-conversation-dock', false);
    }
}
