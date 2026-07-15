<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationJob;
use App\Models\AdminActivityLog;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\NotificationChannel;
use App\Models\NotificationCredential;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\Order;
use App\Models\Permission;
use App\Models\ProductionProject;
use App\Models\SceneGenerationJob;
use App\Models\Story;
use App\Models\User;
use App\Services\Notifications\AdminNotificationDispatcher;
use App\Services\Notifications\NotificationBudgetMonitor;
use App\Services\Notifications\NotificationCredentialService;
use App\Services\Notifications\TelegramNotificationChannel;
use App\Support\ProductionStudio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminNotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_dispatches_queued_order_notification_after_creation(): void
    {
        Queue::fake();
        $this->configureTelegram(['order.created']);
        $story = $this->story();
        [$country, $governorate] = $this->deliveryZone();

        $this->withSession([
            'cart.items' => [
                'cart-key' => [
                    'key' => 'cart-key',
                    'item_type' => 'story',
                    'story_id' => $story->id,
                    'story_title' => $story->title,
                    'story_slug' => $story->slug,
                    'story_price' => (float) $story->price,
                    'child_name' => 'رينا',
                    'child_age' => 6,
                    'child_gender' => 'girl',
                    'interests' => 'الفضاء',
                    'uploaded_photos' => ['private-child-photo.png'],
                ],
            ],
        ]);

        $this->post(route('checkout.store'), [
            'parent_name' => 'Parent Name',
            'phone' => '201000000000',
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2',
        ])->assertRedirect(route('checkout.success'));

        $order = Order::firstOrFail();
        $delivery = NotificationDelivery::where('event_key', 'order.created')->firstOrFail();

        Queue::assertPushed(SendNotificationJob::class);
        $this->assertStringContainsString($order->order_number, $delivery->payload_json['body']);
        $this->assertStringContainsString(route('admin.orders.show', $order), $delivery->payload_json['body']);
        $this->assertStringNotContainsString('private-child-photo.png', $delivery->payload_json['body']);
    }

    public function test_telegram_token_is_encrypted_and_not_visible_in_html(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.notifications.view',
            'settings.notifications.manage',
            'settings.notifications.manage_credentials',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.settings.notifications.telegram.update'), [
                'is_active' => '1',
                'default_chat_id' => '123456',
                'additional_chat_ids' => '',
                'bot_token' => '123456:telegram-secret-ABCD',
            ])
            ->assertRedirect();

        $credential = NotificationCredential::firstOrFail();
        $raw = DB::table('notification_credentials')->value('encrypted_value');

        $this->assertNotSame('123456:telegram-secret-ABCD', $raw);
        $this->assertSame('123456:telegram-secret-ABCD', $credential->encrypted_value);
        $this->assertSame('ABCD', $credential->last_four);

        $this->actingAs($admin)
            ->get(route('admin.settings.notifications.index'))
            ->assertOk()
            ->assertDontSee('123456:telegram-secret-ABCD')
            ->assertSee('••••••••ABCD');
    }

    public function test_user_without_credential_permission_cannot_configure_token(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.notifications.view',
            'settings.notifications.manage',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.settings.notifications.telegram.update'), [
                'is_active' => '1',
                'default_chat_id' => '123456',
                'bot_token' => '123456:telegram-secret-ABCD',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('notification_credentials', 0);
    }

    public function test_test_telegram_action_uses_http_fake_and_does_not_expose_token(): void
    {
        $admin = $this->adminWithPermissions([
            'settings.notifications.test',
        ]);
        $this->configureTelegram();

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => [
                    'message_id' => 77,
                    'chat' => ['id' => '123456'],
                    'date' => 123,
                ],
            ]),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.settings.notifications.telegram.test'))
            ->assertRedirect()
            ->assertSessionHas('success', 'تم إرسال رسالة الاختبار.');

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bot123456:telegram-secret-ABCD/sendMessage')
            && $request['chat_id'] === '123456');

        $auditPayload = AdminActivityLog::query()->pluck('properties')->map(fn ($value) => json_encode($value))->implode("\n");
        $this->assertStringNotContainsString('telegram-secret-ABCD', $auditPayload);
    }

    public function test_order_notification_payload_contains_admin_link_and_excludes_private_photo_paths(): void
    {
        Queue::fake();
        $this->configureTelegram(['order.created']);
        $order = $this->orderWithStory(['uploaded_photos' => ['orders/photos/private-child.png']]);

        app(AdminNotificationDispatcher::class)->dispatch('order.created', $order, [
            'dedupe_key' => 'order.created:'.$order->id,
        ]);

        $delivery = NotificationDelivery::firstOrFail();
        $this->assertStringContainsString($order->order_number, $delivery->payload_json['body']);
        $this->assertStringContainsString(route('admin.orders.show', $order), $delivery->payload_json['body']);
        $this->assertStringNotContainsString('orders/photos/private-child.png', $delivery->payload_json['body']);
    }

    public function test_production_project_created_and_completed_send_notifications_when_enabled(): void
    {
        Queue::fake();
        $this->configureTelegram(['production.project.created', 'production.project.completed']);
        $admin = $this->adminWithPermissions(['production_studio.manage']);
        $order = $this->orderWithStory();

        $this->actingAs($admin);
        $project = ProductionStudio::createProjectFromOrder($order, $admin);

        $this->assertDatabaseHas('notification_deliveries', [
            'event_key' => 'production.project.created',
            'notifiable_type' => ProductionProject::class,
            'notifiable_id' => $project->id,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.production-studio.update', $project), [
                'status' => 'completed',
                'current_stage' => 'print_ready',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('notification_deliveries', [
            'event_key' => 'production.project.completed',
            'notifiable_type' => ProductionProject::class,
            'notifiable_id' => $project->id,
        ]);
    }

    public function test_ai_generation_failed_sends_sanitized_notification_when_enabled(): void
    {
        Queue::fake();
        $this->configureTelegram(['ai.generation.failed']);
        $project = $this->productionProject();
        $job = $project->generationJobs()->create([
            'job_type' => 'scene_image',
            'generation_mode' => 'scene',
            'status' => 'failed',
            'error_message' => 'Bearer secret-token leaked in provider text',
        ]);

        app(AdminNotificationDispatcher::class)->dispatch('ai.generation.failed', $job, [
            'dedupe_key' => 'ai.generation.failed:'.$job->id,
            'status' => 'failed',
        ]);

        $body = NotificationDelivery::firstOrFail()->payload_json['body'];
        $this->assertStringContainsString('فشل توليد صورة', $body);
        $this->assertStringNotContainsString('secret-token', $body);
        $this->assertStringNotContainsString('Bearer secret-token', $body);
    }

    public function test_disabled_event_rule_does_not_send_notification(): void
    {
        Queue::fake();
        $this->configureTelegram(['order.created']);
        NotificationRule::where('event_key', 'order.created')->update(['is_enabled' => false]);
        $order = $this->orderWithStory();

        app(AdminNotificationDispatcher::class)->dispatch('order.created', $order, [
            'dedupe_key' => 'order.created:'.$order->id,
        ]);

        $this->assertDatabaseCount('notification_deliveries', 0);
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_failed_telegram_send_records_safe_failure_without_throwing(): void
    {
        $this->configureTelegram();
        $order = $this->orderWithStory();
        $delivery = NotificationDelivery::create([
            'event_key' => 'order.created',
            'channel_type' => 'telegram',
            'dedupe_key' => 'manual-test',
            'notifiable_type' => Order::class,
            'notifiable_id' => $order->id,
            'recipient' => '123456',
            'status' => 'pending',
            'payload_json' => [
                'subject' => 'Order',
                'body' => 'Test order '.$order->order_number,
                'severity' => 'info',
            ],
        ]);

        Http::fake([
            'https://api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found for token 123456:telegram-secret-ABCD',
            ], 400),
        ]);

        (new SendNotificationJob($delivery->id))->handle(app(TelegramNotificationChannel::class));

        $delivery = $delivery->fresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertStringNotContainsString('telegram-secret-ABCD', $delivery->error_message);
        $this->assertSame(400, $delivery->response_json['http_status']);
    }

    public function test_stuck_check_detects_old_queued_ai_jobs_and_does_not_spam_duplicates(): void
    {
        Queue::fake();
        $this->configureTelegram(['ai.generation.stuck']);
        $project = $this->productionProject();
        $job = $project->generationJobs()->create([
            'job_type' => 'scene_image',
            'generation_mode' => 'scene',
            'status' => 'queued',
        ]);
        SceneGenerationJob::withoutTimestamps(function () use ($job): void {
            $job->forceFill([
                'created_at' => now()->subMinutes(40),
                'updated_at' => now()->subMinutes(40),
            ])->save();
        });

        Artisan::call('notifications:check-stuck-production');
        Artisan::call('notifications:check-stuck-production');

        $this->assertSame(1, NotificationDelivery::where('event_key', 'ai.generation.stuck')->count());
    }

    public function test_budget_exceeded_sends_once(): void
    {
        Queue::fake();
        $this->configureTelegram(['ai.generation.budget_exceeded', 'production.project.budget_exceeded']);
        $project = $this->productionProject();
        $job = $project->generationJobs()->create([
            'job_type' => 'scene_image',
            'generation_mode' => 'scene',
            'status' => 'completed',
            'actual_cost' => '0.3000',
        ]);

        app(NotificationBudgetMonitor::class)->checkAiJob($job);
        app(NotificationBudgetMonitor::class)->checkAiJob($job);

        $this->assertSame(1, NotificationDelivery::where('event_key', 'ai.generation.budget_exceeded')->count());
    }

    private function configureTelegram(?array $enabledEvents = null): NotificationChannel
    {
        $channel = app(NotificationCredentialService::class)->channel('telegram');
        app(NotificationCredentialService::class)->saveToken($channel, '123456:telegram-secret-ABCD');
        $channel->forceFill([
            'is_active' => true,
            'settings_json' => [
                'default_chat_id' => '123456',
                'additional_chat_ids' => [],
                'last_test_status' => null,
                'last_test_message' => null,
                'last_test_at' => null,
            ],
        ])->save();

        foreach (config('admin_notifications.events', []) as $eventKey => $definition) {
            NotificationRule::query()->updateOrCreate(
                ['event_key' => $eventKey, 'channel_type' => 'telegram'],
                [
                    'is_enabled' => $enabledEvents === null
                        ? (bool) ($definition['default_enabled'] ?? false)
                        : in_array($eventKey, $enabledEvents, true),
                    'severity' => $definition['severity'] ?? 'info',
                ]
            );
        }

        return $channel->fresh();
    }

    private function adminWithPermissions(array $permissionKeys): User
    {
        $admin = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'role' => 'admin',
        ]);

        $admin->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id')->all());

        return $admin->refresh();
    }

    private function story(array $overrides = []): Story
    {
        return Story::create(array_merge([
            'title' => 'رحلة القمر',
            'slug' => 'moon-trip-'.Str::random(6),
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 100,
            'active' => true,
        ], $overrides));
    }

    private function orderWithStory(array $overrides = []): Order
    {
        $story = $this->story();

        return Order::create(array_merge([
            'order_number' => 'HK-2026-'.strtoupper(Str::random(6)),
            'story_id' => $story->id,
            'parent_name' => 'Parent Name',
            'child_name' => 'رينا',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'lesson' => 'الشجاعة',
            'delivery_details' => [
                'phone' => '201000000000',
                'governorate' => 'القاهرة',
                'total' => 140,
            ],
            'uploaded_photos' => [],
            'status' => 'new',
        ], $overrides));
    }

    private function productionProject(): ProductionProject
    {
        $order = $this->orderWithStory();

        return ProductionProject::create([
            'order_id' => $order->id,
            'status' => 'in_progress',
            'current_stage' => 'image_generation',
            'sent_to_studio_at' => now(),
        ])->load('order.story');
    }

    private function deliveryZone(): array
    {
        $country = DeliveryCountry::where('code', 'EG')->firstOrFail();
        $governorate = DeliveryGovernorate::where('delivery_country_id', $country->id)
            ->where('name', 'القاهرة')
            ->firstOrFail();

        return [$country, $governorate];
    }
}
