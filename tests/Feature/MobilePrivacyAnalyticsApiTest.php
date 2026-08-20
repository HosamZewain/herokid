<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobilePrivacyAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_review_and_revoke_individual_mobile_sessions(): void
    {
        $user = User::factory()->create();
        $current = $user->createToken('iPhone', ['mobile'], now()->addDays(90));
        $other = $user->createToken('Android', ['mobile'], now()->addDays(90));

        $this->withToken($current->plainTextToken)->getJson('/api/v1/sessions')
            ->assertOk()->assertJsonCount(2, 'data');
        $this->withToken($current->plainTextToken)->deleteJson('/api/v1/sessions/'.$other->accessToken->id)
            ->assertOk()->assertJsonPath('data.current', false);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $other->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $current->accessToken->id]);
    }

    public function test_account_deletion_request_requires_password_confirmation_is_encrypted_and_can_be_cancelled(): void
    {
        $user = User::factory()->create(['password' => 'Strong-password-123']);
        Sanctum::actingAs($user, ['mobile']);
        $this->postJson('/api/v1/privacy/requests', [
            'request_type' => 'account_deletion',
            'password' => 'wrong',
            'confirmation' => 'DELETE_MY_ACCOUNT',
        ])->assertUnprocessable();

        $created = $this->postJson('/api/v1/privacy/requests', [
            'request_type' => 'account_deletion',
            'password' => 'Strong-password-123',
            'confirmation' => 'DELETE_MY_ACCOUNT',
            'reason' => 'أريد حذف حساب العائلة وملفات الأطفال.',
        ])->assertCreated()->assertJsonPath('data.status', 'pending');
        $requestId = $created->json('data.id');
        $raw = (string) $this->getConnection()->table('privacy_requests')->where('uuid', $requestId)->value('reason');
        $this->assertStringNotContainsString('ملفات الأطفال', $raw);
        $this->assertNotNull($user->refresh()->deletion_scheduled_for);

        $this->postJson('/api/v1/privacy/requests', [
            'request_type' => 'account_deletion',
            'password' => 'Strong-password-123',
            'confirmation' => 'DELETE_MY_ACCOUNT',
        ])->assertOk()->assertJsonPath('data.id', $requestId);
        $this->assertDatabaseCount('privacy_requests', 1);
        $this->postJson('/api/v1/privacy/requests/'.$requestId.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertNull($user->refresh()->deletion_requested_at);
    }

    public function test_analytics_batch_is_idempotent_and_strips_child_names_images_and_contact_data(): void
    {
        $eventId = (string) Str::uuid();
        $payload = [
            'events' => [[
                'id' => $eventId,
                'name' => 'product_viewed',
                'properties' => [
                    'product_id' => 12,
                    'child_profile_id' => 'internal-child-uuid',
                    'child_name' => 'Private Name',
                    'image_url' => 'https://private.test/image.png',
                    'email' => 'parent@example.test',
                    'source' => 'home',
                ],
                'occurred_at' => now()->subSecond()->toISOString(),
            ]],
            'app_version' => '1.0.0',
            'platform' => 'android',
        ];
        $device = (string) Str::uuid();

        $this->withHeader('X-Device-Installation', $device)->postJson('/api/v1/analytics/events', $payload)
            ->assertAccepted()->assertJsonPath('data.accepted', 1);
        $this->withHeader('X-Device-Installation', $device)->postJson('/api/v1/analytics/events', $payload)
            ->assertAccepted()->assertJsonPath('data.accepted', 0);
        $this->assertDatabaseCount('mobile_analytics_events', 1);
        $properties = json_decode((string) $this->getConnection()->table('mobile_analytics_events')->value('properties'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(['product_id' => 12, 'child_profile_id' => 'internal-child-uuid', 'source' => 'home'], $properties);
        $this->assertStringNotContainsString('Private Name', json_encode($properties));
    }
}
