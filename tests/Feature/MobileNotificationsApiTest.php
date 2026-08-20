<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Story;
use App\Models\User;
use App\Services\Mobile\MobileNotificationService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileNotificationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_token_is_encrypted_and_operational_preferences_are_separate_from_marketing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['mobile']);
        $installation = (string) Str::uuid();
        $token = 'ExponentPushToken[heroKidDevice_123]';

        $this->postJson('/api/v1/devices', [
            'installation_id' => $installation,
            'platform' => 'ios',
            'app_version' => '1.0.0',
            'locale' => 'ar',
            'push_token' => $token,
            'operational_notifications' => true,
            'marketing_notifications' => false,
        ])->assertCreated()->assertJsonPath('data.push_enabled', true)->assertJsonPath('data.marketing_notifications', false);

        $raw = (string) $this->getConnection()->table('device_installations')->where('uuid', $installation)->value('push_token');
        $this->assertStringNotContainsString($token, $raw);
        $this->assertSame(hash('sha256', $token), $this->getConnection()->table('device_installations')->where('uuid', $installation)->value('push_token_hash'));

        $this->patchJson('/api/v1/devices/'.$installation, ['marketing_notifications' => true])
            ->assertOk()->assertJsonPath('data.operational_notifications', true)->assertJsonPath('data.marketing_notifications', true);
        $this->deleteJson('/api/v1/devices/'.$installation)->assertNoContent();
        $this->assertDatabaseHas('device_installations', ['uuid' => $installation, 'push_token_hash' => null]);
    }

    public function test_notifications_are_owner_scoped_readable_and_strip_sensitive_push_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $notification = app(MobileNotificationService::class)->notifyUser($owner, 'preview.ready', 'Ready', 'Review order HK-1', [
            'order_id' => 10,
            'child_name' => 'Private Child',
            'image_url' => 'https://private.test/child.png',
        ]);
        $this->assertSame(['order_id' => 10], $notification->data);

        Sanctum::actingAs($owner, ['mobile']);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 1)->assertJsonMissing(['child_name' => 'Private Child']);
        $this->postJson('/api/v1/notifications/'.$notification->uuid.'/read')->assertOk();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('meta.unread', 0);

        Sanctum::actingAs($other, ['mobile']);
        $this->postJson('/api/v1/notifications/'.$notification->uuid.'/read')->assertNotFound();
    }

    public function test_order_status_changes_create_operational_in_app_notifications(): void
    {
        $user = User::factory()->create();
        $story = Story::create(['title' => 'Status story', 'slug' => 'mobile-notification-status', 'language' => 'ar', 'gender' => 'both', 'price' => 200, 'active' => true]);
        $order = Order::create([
            'order_number' => 'HK-NOTIFY-001',
            'user_id' => $user->id,
            'parent_name' => $user->name,
            'story_id' => $story->id,
            'child_name' => 'Private',
            'delivery_details' => [],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $request = Request::create('/admin/orders/'.$order->id, 'PATCH');
        $request->setUserResolver(fn () => User::factory()->create(['role' => 'admin']));
        app(OrderStatusService::class)->update($order, 'preview_uploaded', null, $request);

        $this->assertDatabaseHas('mobile_notifications', ['user_id' => $user->id, 'event_key' => 'preview.ready', 'category' => 'operational']);
        $stored = $user->mobileNotifications()->firstOrFail();
        $this->assertStringNotContainsString('Private', $stored->body);
    }
}
