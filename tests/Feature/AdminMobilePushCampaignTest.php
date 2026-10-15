<?php

namespace Tests\Feature;

use App\Jobs\DispatchMobilePushCampaign;
use App\Jobs\SendMobilePushNotification;
use App\Models\DeviceInstallation;
use App\Models\MobilePushCampaign;
use App\Models\Permission;
use App\Models\User;
use App\Services\Mobile\MobilePushAudience;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminMobilePushCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function operator(array $permissions = ['settings.mobile.view', 'settings.mobile.manage']): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->permissions()->sync(Permission::whereIn('key', $permissions)->pluck('id'));

        return $user;
    }

    private function device(User $user, bool $marketing = true, array $extra = []): DeviceInstallation
    {
        return DeviceInstallation::create([...[
            'user_id' => $user->id,
            'platform' => 'ios', 'push_token' => 'ExponentPushToken[synthetic-test]', 'push_token_hash' => hash('sha256', (string) $user->id),
            'marketing_notifications' => $marketing, 'operational_notifications' => true, 'last_seen_at' => now(),
        ], ...$extra]);
    }

    private function draft(array $extra = []): array
    {
        return [...['audience' => 'all', 'category' => 'marketing', 'title' => 'عرض تجريبي', 'body' => 'نص تجريبي', 'destination' => 'catalog'], ...$extra];
    }

    public function test_permissions_sidebar_preview_confirmation_and_idempotent_submission(): void
    {
        Queue::fake();
        $viewer = $this->operator(['settings.mobile.view']);
        $this->actingAs($viewer)->get(route('admin.mobile-push.index'))->assertOk()->assertSee('إشعارات التطبيق');
        $this->post(route('admin.mobile-push.preview'), [])->assertForbidden();
        $admin = $this->operator();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->device($customer);
        $this->actingAs($admin)->get(route('admin.mobile-push.index', ['q' => $customer->name]))->assertOk()->assertSee($customer->name);
        $this->post(route('admin.mobile-push.preview'), $this->draft())->assertOk()->assertSee('راجع قبل الإرسال')->assertSee('1 عميل مؤهل');
        $this->assertDatabaseCount('mobile_push_campaigns', 0);
        Queue::assertNothingPushed();
        $token = session('mobile_push_draft.token');
        $this->post(route('admin.mobile-push.store'), ['token' => $token])->assertSessionHasErrors('confirmed');
        $this->post(route('admin.mobile-push.store'), ['token' => $token, 'confirmed' => 1, 'title' => 'tampered'])->assertRedirect();
        $campaign = MobilePushCampaign::sole();
        $this->assertSame('عرض تجريبي', $campaign->title);
        $this->assertSame(['screen' => 'catalog'], $campaign->data);
        $this->post(route('admin.mobile-push.store'), ['token' => $token, 'confirmed' => 1])->assertRedirect(route('admin.mobile-push.show', $campaign));
        $this->assertDatabaseCount('mobile_push_campaigns', 1);
        Queue::assertPushed(DispatchMobilePushCampaign::class, 1);
        $this->get(route('admin.mobile-push.show', $campaign))->assertOk()->assertSee('قبول الخدمة ليس إثبات وصول');
    }

    public function test_audience_respects_opt_out_revocation_active_accounts_and_single_customer(): void
    {
        Queue::fake();
        $allowed = User::factory()->create(['role' => 'customer']);
        $optOut = User::factory()->create(['role' => 'customer']);
        $revoked = User::factory()->create(['role' => 'customer']);
        $inactive = User::factory()->create(['role' => 'customer', 'is_active' => false]);
        $this->device($allowed);
        $this->device($optOut, false);
        $this->device($revoked, true, ['revoked_at' => now()]);
        $this->device($inactive);
        $campaign = $this->campaign();
        $job = new DispatchMobilePushCampaign($campaign->id);
        $job->handle(new MobilePushAudience);
        $job->handle(new MobilePushAudience);
        $this->assertSame([$allowed->id], $campaign->notifications()->pluck('user_id')->all());
        Queue::assertPushed(SendMobilePushNotification::class, 1);
        $single = $this->campaign(['audience' => 'customer', 'target_user_id' => $optOut->id, 'category' => 'operational']);
        (new DispatchMobilePushCampaign($single->id))->handle(new MobilePushAudience);
        $this->assertSame([$optOut->id], $single->notifications()->pluck('user_id')->all());
        $deletedTarget = $this->campaign(['audience' => 'customer', 'target_user_id' => null]);
        (new DispatchMobilePushCampaign($deletedTarget->id))->handle(new MobilePushAudience);
        $this->assertSame('failed', $deletedTarget->fresh()->status);
        $this->assertSame(0, $deletedTarget->notifications()->count());
    }

    public function test_sender_only_queues_with_a_live_server_preview_and_whitelisted_destination(): void
    {
        Queue::fake();
        $this->actingAs($this->operator());
        $this->post(route('admin.mobile-push.preview'), $this->draft(['destination' => 'https://evil.example']))->assertSessionHasErrors('destination');
        $this->post(route('admin.mobile-push.preview'), $this->draft())->assertSessionHasErrors('audience');
        $this->post(route('admin.mobile-push.store'), ['token' => (string) Str::uuid(), 'confirmed' => 1])->assertStatus(419);
        $this->assertDatabaseCount('mobile_push_campaigns', 0);
        Http::assertNothingSent();
    }

    public function test_push_results_record_provider_acceptance_failure_opt_out_and_prevent_repeat_send(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $user = User::factory()->create(['role' => 'customer']);
        $device = $this->device($user);
        $campaign = $this->campaign();
        (new DispatchMobilePushCampaign($campaign->id))->handle(new MobilePushAudience);
        $notification = $campaign->notifications()->sole();
        Http::fake(['exp.host/*' => Http::sequence()
            ->push(['data' => ['status' => 'ok', 'id' => 'synthetic-ticket']])
            ->push(['data' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]])]);
        $job = new SendMobilePushNotification($notification->id);
        $job->handle();
        $job->handle();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['data'] === ['screen' => 'home', 'notification_id' => $notification->uuid]);
        $this->assertSame('sent', $notification->deliveries()->sole()->status);
        $second = $this->campaign();
        (new DispatchMobilePushCampaign($second->id))->handle(new MobilePushAudience);
        (new SendMobilePushNotification($second->notifications()->sole()->id))->handle();
        $this->assertSame('failed', $second->notifications()->sole()->deliveries()->sole()->status);
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_receipts_record_later_failure_without_revoking_a_rotated_token_or_resending(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $user = User::factory()->create(['role' => 'customer']);
        $device = $this->device($user);
        $campaign = $this->campaign();
        (new DispatchMobilePushCampaign($campaign->id))->handle(new MobilePushAudience);
        $notification = $campaign->notifications()->sole();
        $delivery = $notification->deliveries()->create([
            'device_installation_id' => $device->id, 'status' => 'sent', 'provider_ticket_id' => 'qa-ticket',
            'receipt_status' => 'pending', 'sent_at' => now()->subMinutes(20), 'sent_token_hash' => $device->push_token_hash,
        ]);
        $device->update(['push_token_hash' => hash('sha256', 'rotated')]);
        Http::fake(['exp.host/--/api/v2/push/getReceipts' => Http::response(['data' => ['qa-ticket' => [
            'status' => 'error', 'details' => ['error' => 'DeviceNotRegistered'],
        ]]])]);
        $this->artisan('mobile-push:check-receipts')->assertSuccessful();
        $this->assertSame('failed', $delivery->fresh()->receipt_status);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNull($device->fresh()->revoked_at);
        Http::assertSentCount(1);
        Queue::assertPushed(SendMobilePushNotification::class, 1);
        $this->actingAs($this->operator())->get(route('admin.mobile-push.show', $campaign))
            ->assertOk()->assertSee('فشل لاحق لدى المزود')->assertDontSee('ExponentPushToken');
    }

    public function test_receipts_expire_to_unknown_and_opt_out_before_send_prevents_http(): void
    {
        Queue::fake();
        Http::fake();
        $user = User::factory()->create(['role' => 'customer']);
        $device = $this->device($user);
        $campaign = $this->campaign();
        (new DispatchMobilePushCampaign($campaign->id))->handle(new MobilePushAudience);
        $notification = $campaign->notifications()->sole();
        $device->update(['marketing_notifications' => false]);
        (new SendMobilePushNotification($notification->id))->handle();
        Http::assertNothingSent();
        $delivery = $notification->deliveries()->create([
            'device_installation_id' => $device->id, 'status' => 'sent', 'provider_ticket_id' => 'expired-ticket',
            'receipt_status' => 'pending', 'sent_at' => now()->subDays(2),
        ]);
        $this->artisan('mobile-push:check-receipts')->assertSuccessful();
        $this->assertSame('unknown', $delivery->fresh()->receipt_status);
        Http::assertNothingSent();
    }

    public function test_preview_expires_and_unauthorized_customer_cannot_view_results(): void
    {
        Queue::fake();
        $admin = $this->operator();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->device($customer);
        $this->actingAs($admin)->post(route('admin.mobile-push.preview'), $this->draft(['audience' => 'customer', 'user_id' => $customer->id]))->assertOk();
        $token = session('mobile_push_draft.token');
        $this->travel(16)->minutes();
        $this->post(route('admin.mobile-push.store'), ['token' => $token, 'confirmed' => 1])->assertStatus(419);
        $this->assertDatabaseCount('mobile_push_campaigns', 0);
        $this->actingAs($customer)->get(route('admin.mobile-push.show', $this->campaign()))->assertForbidden();
    }

    public function test_transient_send_failure_is_recorded_and_retry_accepts_only_once(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'customer']);
        $this->device($user);
        $campaign = $this->campaign();
        (new DispatchMobilePushCampaign($campaign->id))->handle(new MobilePushAudience);
        Http::fake(['exp.host/*' => Http::sequence()->push([], 503)->push(['data' => ['status' => 'ok', 'id' => 'retry-ticket']])]);
        $notification = $campaign->notifications()->sole();
        $job = new SendMobilePushNotification($notification->id);
        try {
            $job->handle();
            $this->fail('Transient failure must reach the queue retry handler.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Push service temporarily unavailable.', $exception->getMessage());
        }
        $this->assertSame('failed', $notification->deliveries()->sole()->status);
        $job->handle();
        $job->handle();
        $this->assertSame('sent', $notification->deliveries()->sole()->status);
        $this->assertSame(2, $notification->deliveries()->sole()->attempts);
        Http::assertSentCount(2);
    }

    public function test_receipt_acceptance_and_matching_unregistered_device_are_recorded(): void
    {
        Queue::fake();
        $user = User::factory()->create(['role' => 'customer']);
        $device = $this->device($user);
        $campaign = $this->campaign();
        (new DispatchMobilePushCampaign($campaign->id))->handle(new MobilePushAudience);
        $notification = $campaign->notifications()->sole();
        $delivery = $notification->deliveries()->create(['device_installation_id' => $device->id, 'status' => 'sent',
            'provider_ticket_id' => 'receipt-ticket', 'receipt_status' => 'pending', 'sent_at' => now()->subMinutes(20), 'sent_token_hash' => $device->push_token_hash]);
        Http::fake(['exp.host/*' => Http::sequence()->push(['data' => ['receipt-ticket' => ['status' => 'ok']]])
            ->push(['data' => ['receipt-ticket' => ['status' => 'error', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
        $this->artisan('mobile-push:check-receipts')->assertSuccessful();
        $this->assertSame('provider_accepted', $delivery->fresh()->receipt_status);
        $delivery->refresh()->update(['receipt_status' => 'pending', 'receipt_checked_at' => null]);
        $this->artisan('mobile-push:check-receipts')->assertSuccessful();
        $this->assertSame('failed', $delivery->fresh()->receipt_status);
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertNull($device->fresh()->push_token);
    }

    private function campaign(array $extra = []): MobilePushCampaign
    {
        return MobilePushCampaign::create([...[
            'uuid' => (string) Str::uuid(), 'audience' => 'all', 'category' => 'marketing',
            'title' => 'اختبار', 'body' => 'اختبار', 'data' => ['screen' => 'home'], 'status' => 'queued',
        ], ...$extra]);
    }
}
