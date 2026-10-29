<?php

namespace Tests\Feature;

use App\Models\ChildIdentityRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AwsAttemptPhotoMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attempt_snapshot_is_only_converted_after_its_own_object_is_verified(): void
    {
        Storage::fake('local');
        Storage::fake('s3_private');
        $identity = ChildIdentityRequest::create([
            'uuid' => (string) Str::uuid(), 'resume_token_hash' => hash('sha256', 'synthetic'),
            'parent_name' => 'Synthetic Parent', 'parent_phone' => '201000000000',
            'child_name' => 'Synthetic Child', 'child_age' => 5, 'age_range' => '3-6',
            'consent_accepted_at' => now(), 'consent_version' => 'test',
        ]);
        $photo = $identity->photos()->create([
            'disk' => 'local', 'path' => 'child-identities/test/source.png',
            'original_filename' => 'synthetic.png', 'mime_type' => 'image/png',
            'file_size' => 9, 'checksum' => hash('sha256', 'synthetic'),
        ]);
        $attempt = $identity->attempts()->create([
            'attempt_number' => 1, 'idempotency_key' => (string) Str::uuid(),
            'model' => 'mock', 'prompt_version' => 'test', 'prompt_snapshot' => 'synthetic',
            'prompt_hash' => hash('sha256', 'synthetic'), 'input_photos_count' => 1,
            'image_size' => '1024x1024', 'image_quality' => 'standard',
        ]);
        $snapshot = 'child-identities/test/attempt-input.png';
        $attempt->photos()->attach($photo->id, [
            'disk' => 'local', 'path' => $snapshot, 'checksum' => hash('sha256', 'snapshot'),
        ]);
        Storage::disk('local')->put($snapshot, 'snapshot');
        Storage::disk('s3_private')->put($photo->path, 'synthetic');
        $options = ['--target-public' => 'public'];
        $this->artisan('media:verify-s3-references', $options)
            ->expectsOutputToContain('child_identity_attempt_photos.path')->assertFailed();
        $this->artisan('media:migrate-disk-references', $options + ['--apply' => true])->assertSuccessful();
        $this->assertSame('local', DB::table('child_identity_attempt_photos')->value('disk'));
        Storage::disk('s3_private')->put($snapshot, 'snapshot');
        $this->artisan('media:migrate-disk-references', $options)->assertSuccessful();
        $this->assertSame('local', DB::table('child_identity_attempt_photos')->value('disk'));
        foreach ([1, 2] as $run) {
            $this->artisan('media:migrate-disk-references', $options + ['--apply' => true])->assertSuccessful();
            $this->assertDatabaseHas('child_identity_attempt_photos', ['disk' => 's3_private', 'path' => $snapshot]);
            $this->assertDatabaseCount('child_identity_attempt_photos', 1);
        }
        Storage::disk('local')->assertExists($snapshot);
        $this->assertSame('snapshot', Storage::disk('s3_private')->get($snapshot));
    }
}
