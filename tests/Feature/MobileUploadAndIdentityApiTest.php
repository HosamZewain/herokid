<?php

namespace Tests\Feature;

use App\Models\AiProvider;
use App\Models\ChildIdentityRequest;
use App\Models\ChildProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Ai\AiProviderRegistrySyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileUploadAndIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunks_can_arrive_out_of_order_and_resume_before_private_child_photo_attachment(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Omar', 'age' => 7]);
        Sanctum::actingAs($user, ['mobile']);
        $contents = $this->pngBytes().str_repeat("\0", (1024 * 1024) + 311);

        $created = $this->postJson('/api/v1/uploads', [
            'purpose' => 'child_reference',
            'child_profile_id' => $child->uuid,
            'filename' => 'omar.png',
            'mime_type' => 'image/png',
            'size' => strlen($contents),
        ])->assertCreated();
        $uploadId = $created->json('data.id');
        $chunkSize = $created->json('data.chunk_size');
        $chunks = str_split($contents, $chunkSize);

        $this->post('/api/v1/uploads/'.$uploadId.'/chunks/1', [
            'chunk' => UploadedFile::fake()->createWithContent('part-1', $chunks[1]),
            'checksum' => hash('sha256', $chunks[1]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.received_chunks.0', 1)
            ->assertJsonPath('data.status', 'uploading');

        $this->getJson('/api/v1/uploads/'.$uploadId)
            ->assertOk()
            ->assertJsonPath('data.received_chunks.0', 1);

        $this->post('/api/v1/uploads/'.$uploadId.'/chunks/0', [
            'chunk' => UploadedFile::fake()->createWithContent('part-0', $chunks[0]),
            'checksum' => hash('sha256', $chunks[0]),
        ], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.progress', 1);

        $attached = $this->postJson('/api/v1/uploads/'.$uploadId.'/attach-child-photo', [
            'child_profile_id' => $child->uuid,
            'reuse_consent' => true,
        ])->assertCreated();

        $photoId = $attached->json('data.id');
        $this->getJson('/api/v1/children/'.$child->uuid.'/photos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $photoId);
        $this->get('/api/v1/children/'.$child->uuid.'/photos/'.$photoId.'/media', ['Accept' => 'image/png'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_mobile_identity_creation_reuses_owned_child_photos_records_consent_and_is_idempotent(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->configureIdentityGeneration();
        $user = User::factory()->create(['name' => 'Parent', 'phone' => '01012345678']);
        $child = ChildProfile::create(['user_id' => $user->id, 'name' => 'Mariam', 'age' => 5, 'gender' => 'girl']);
        Sanctum::actingAs($user, ['mobile']);
        $photoIds = [
            $this->uploadAndAttach($child, 'mariam-one.png'),
            $this->uploadAndAttach($child, 'mariam-two.png'),
        ];
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'child_profile_id' => $child->uuid,
            'photo_ids' => $photoIds,
            'identity_type' => 'themed',
            'theme' => 'astronaut',
            'processing_consent' => true,
            'marketing_consent' => false,
            'idempotency_key' => $idempotencyKey,
        ];

        $created = $this->postJson('/api/v1/child-identities', $payload)
            ->assertCreated()
            ->assertJsonPath('data.child_name', 'Mariam')
            ->assertJsonPath('data.identity_type', 'themed')
            ->assertJsonPath('data.theme', 'astronaut')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.attempts.0.status', 'pending');

        $identityId = $created->json('data.id');
        $this->postJson('/api/v1/child-identities', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $identityId);
        $this->assertDatabaseCount('child_identity_requests', 1);
        $this->assertDatabaseHas('consent_records', [
            'user_id' => $user->id,
            'child_profile_id' => $child->id,
            'consent_type' => 'child_image_processing',
            'granted' => true,
        ]);
        $identity = ChildIdentityRequest::firstOrFail();
        $this->assertCount(2, $identity->validPhotos);
        $this->assertStringContainsString('theme astronaut', $identity->attempts()->firstOrFail()->prompt_snapshot);

        $this->deleteJson('/api/v1/child-identities/'.$identityId)->assertNoContent();
        $this->assertDatabaseMissing('child_identity_requests', ['uuid' => $identityId]);
        $this->assertDatabaseHas('consent_records', ['consent_type' => 'child_identity_deleted', 'granted' => false]);
    }

    public function test_upload_and_child_media_are_not_visible_to_another_account(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $child = ChildProfile::create(['user_id' => $owner->id, 'name' => 'Private', 'age' => 8]);
        Sanctum::actingAs($owner, ['mobile']);
        $photoId = $this->uploadAndAttach($child, 'private.png');

        Sanctum::actingAs($other, ['mobile']);
        $this->getJson('/api/v1/children/'.$child->uuid.'/photos')->assertNotFound();
        $this->get('/api/v1/children/'.$child->uuid.'/photos/'.$photoId.'/media')->assertNotFound();
    }

    private function uploadAndAttach(ChildProfile $child, string $filename): string
    {
        $contents = $this->pngBytes();
        $upload = $this->postJson('/api/v1/uploads', [
            'purpose' => 'child_reference',
            'child_profile_id' => $child->uuid,
            'filename' => $filename,
            'mime_type' => 'image/png',
            'size' => strlen($contents),
        ])->assertCreated();
        $uploadId = $upload->json('data.id');
        $this->post('/api/v1/uploads/'.$uploadId.'/chunks/0', [
            'chunk' => UploadedFile::fake()->createWithContent('part', $contents),
            'checksum' => hash('sha256', $contents),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'completed');

        return $this->postJson('/api/v1/uploads/'.$uploadId.'/attach-child-photo', [
            'child_profile_id' => $child->uuid,
            'reuse_consent' => true,
        ])->assertCreated()->json('data.id');
    }

    private function configureIdentityGeneration(): AiProvider
    {
        Setting::updateOrCreate(['key' => 'age_ranges'], [
            'value' => json_encode(['٣ - ٦ سنوات', '٦ - ٩ سنوات'], JSON_UNESCAPED_UNICODE),
        ]);
        app(AiProviderRegistrySyncer::class)->sync();
        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        app(AiProviderCredentialService::class)->save($provider, 'test-openai-key');
        $settings = $provider->settings_json ?? [];
        data_set($settings, 'default_models.character_sheet', 'gpt-image-2');
        $provider->forceFill([
            'is_active' => true,
            'is_configured' => true,
            'is_available' => true,
            'settings_json' => $settings,
        ])->save();
        $provider->models()->where('code', 'gpt-image-2')->update(['is_active' => true]);

        return $provider;
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Z1h8AAAAASUVORK5CYII=', true);
    }
}
