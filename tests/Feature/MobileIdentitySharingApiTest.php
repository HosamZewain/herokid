<?php

namespace Tests\Feature;

use App\Models\ChildIdentityRequest;
use App\Models\ChildIdentityShare;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileIdentitySharingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_receive_branded_share_payload_and_record_channel_event(): void
    {
        $user = User::factory()->create();
        [$identity, $share] = $this->readyShare($user);
        Sanctum::actingAs($user, ['mobile']);

        $response = $this->postJson("/api/v1/child-identities/{$identity->uuid}/share", [
            'share_consent' => true,
        ])->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.share_id', $share->id)
            ->assertJsonStructure(['data' => ['publicUrl', 'caption', 'whatsapp', 'facebook', 'cards' => ['feed', 'story', 'og']]]);

        $this->assertStringContainsString('utm_source%3Dwhatsapp', $response->json('data.whatsapp'));
        $this->postJson("/api/v1/child-identities/{$identity->uuid}/share/{$share->id}/event", [
            'event_type' => 'share.whatsapp_clicked',
            'channel' => 'whatsapp',
        ])->assertOk()->assertJsonPath('recorded', true);
        $this->assertDatabaseHas('child_identity_share_events', [
            'child_identity_share_id' => $share->id,
            'event_type' => 'share.whatsapp_clicked',
            'channel' => 'whatsapp',
        ]);
    }

    public function test_other_account_cannot_read_or_record_identity_share(): void
    {
        [$identity, $share] = $this->readyShare(User::factory()->create());
        Sanctum::actingAs(User::factory()->create(), ['mobile']);

        $this->postJson("/api/v1/child-identities/{$identity->uuid}/share", ['share_consent' => true])->assertNotFound();
        $this->postJson("/api/v1/child-identities/{$identity->uuid}/share/{$share->id}/event", [
            'event_type' => 'share.native_opened', 'channel' => 'native',
        ])->assertNotFound();
    }

    private function readyShare(User $user): array
    {
        $identity = ChildIdentityRequest::query()->create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'resume_token_hash' => hash('sha256', Str::random(80)),
            'parent_name' => $user->name, 'parent_phone' => $user->phone,
            'parent_email' => $user->email, 'child_name' => 'Private Child',
            'child_age' => 7, 'age_range' => '٦ - ٩ سنوات', 'status' => 'generated',
            'consent_accepted_at' => now(), 'consent_version' => 'test-v1', 'last_activity_at' => now(),
        ]);
        $attempt = $identity->attempts()->create([
            'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(),
            'initiated_by' => 'customer', 'status' => 'succeeded', 'provider' => 'openai',
            'model' => 'gpt-image-2', 'prompt_version' => 'test-v1', 'prompt_snapshot' => 'private',
            'prompt_hash' => hash('sha256', 'private'), 'input_photos_count' => 2,
            'image_size' => '1536x1024', 'image_quality' => 'medium',
            'output_disk' => 'local', 'output_storage_path' => 'private/output.jpg',
            'output_checksum' => hash('sha256', 'output'), 'completed_at' => now(),
        ]);
        $identity->update(['approved_attempt_id' => $attempt->id, 'status' => 'approved']);
        $share = ChildIdentityShare::query()->create([
            'child_identity_request_id' => $identity->id, 'generation_attempt_id' => $attempt->id,
            'public_token' => Str::random(64), 'status' => 'ready', 'share_enabled' => true,
            'display_child_first_name' => false, 'consent_accepted_at' => now(), 'consent_version' => 'test-v1',
            'created_by_type' => 'customer', 'created_by_id' => $user->id, 'card_disk' => 'local',
            'feed_card_path' => 'shares/feed.jpg', 'story_card_path' => 'shares/story.jpg', 'og_card_path' => 'shares/og.jpg',
            'template_version' => 'test-v1', 'card_fingerprint' => hash('sha256', 'card'),
            'generated_fingerprint' => hash('sha256', 'card'), 'generation_version' => 1,
            'caption_snapshot' => 'HeroKid', 'hashtags_snapshot' => '#HeroKid', 'cards_generated_at' => now(),
        ]);

        return [$identity->fresh(), $share];
    }
}
