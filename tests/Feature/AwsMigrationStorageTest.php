<?php

namespace Tests\Feature;

use App\Jobs\GenerateStudioCover;
use App\Models\AiProvider;
use App\Models\ExpenseCategory;
use App\Models\LibraryStory;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Story;
use App\Models\StudioCoverGeneration;
use App\Models\User;
use App\Services\Ai\AiProviderCredentialService;
use App\Services\Expenses\ExpenseLedgerService;
use App\Services\RemoteStudio\StudioCoverService;
use App\Services\RemoteStudio\StudioSettingsSchema;
use App\Services\RemoteStudio\StudioSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Tests\TestCase;

class AwsMigrationStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('migration_bucket');

        // Normal suite: real scoped adapters over an isolated local bucket.
        // Integration run: the same tests against actual S3 via disposable MinIO.
        // Never accept a production endpoint, bucket, or AWS credentials here.
        if (Env::get('HEROKID_TEST_MINIO') === '1') {
            config(['filesystems.disks.s3' => [
                'driver' => 's3', 'key' => 'herokid-test-only', 'secret' => 'herokid-test-only-secret',
                'region' => 'eu-central-1', 'bucket' => 'herokid-migration-tests',
                'endpoint' => 'http://herokid-migration-minio:9000', 'use_path_style_endpoint' => true,
                'root' => 'isolated-tests/'.Str::uuid(), 'throw' => true,
            ]]);
            Storage::forgetDisk('s3');
            $client = Storage::disk('s3')->getClient();
            if (! $client->doesBucketExistV2('herokid-migration-tests')) {
                $client->createBucket(['Bucket' => 'herokid-migration-tests']);
            }
        } else {
            config(['filesystems.disks.s3' => [
                'driver' => 'local', 'root' => Storage::disk('migration_bucket')->path(''), 'throw' => true,
            ]]);
        }

        config([
            'media.private_disk' => 's3_private', 'media.public_disk' => 'public',
            'media.processing_disk' => 'local', 'media.expenses_disk' => 'local',
            'story_library.disk' => 's3_private',
            'filesystems.disks.studio_private' => $this->studioConfig('scoped'),
        ]);
        Storage::forgetDisk(['s3', 's3_private', 'studio_private']);
    }

    protected function tearDown(): void
    {
        try {
            // Only this test's UUID-scoped root, never a real bucket root.
            if (isset($this->app) && Env::get('HEROKID_TEST_MINIO') === '1') {
                $disk = Storage::disk('s3');
                $disk->delete($disk->allFiles());
            }
        } finally {
            parent::tearDown();
        }
    }

    private function studioConfig(?string $driver = null): array
    {
        $repository = Env::getRepository();
        $previous = $repository->get('STUDIO_PRIVATE_DRIVER');
        $driver === null ? $repository->clear('STUDIO_PRIVATE_DRIVER') : $repository->set('STUDIO_PRIVATE_DRIVER', $driver);
        try {
            return (require config_path('filesystems.php'))['disks']['studio_private'];
        } finally {
            $previous === null ? $repository->clear('STUDIO_PRIVATE_DRIVER') : $repository->set('STUDIO_PRIVATE_DRIVER', $previous);
        }
    }

    private function admin(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'password' => 'migration-test-password']);
        $user->permissions()->sync(Permission::whereIn('key', [
            'studio.access', 'studio.settings.read', 'studio.settings.manage',
            'studio.covers.generate', 'orders.view', 'orders.photos.view',
            'story_library.view', 'story_library.manage',
            'expenses.view', 'expenses.view_attachments', 'expenses.download_attachments',
        ])->pluck('id'));

        return $user;
    }

    private function story(array $overrides = []): LibraryStory
    {
        $id = (string) Str::uuid();

        return LibraryStory::create(array_merge([
            'uuid' => $id, 'slug' => $id, 'title' => 'Migration test', 'language' => 'ar',
            'reading_direction' => 'rtl', 'status' => 'published', 'published_at' => now(),
            'disk' => 'local', 'pdf_path' => 'story-library/'.$id.'/story.pdf',
            'cover_path' => 'story-library/'.$id.'/cover.png', 'cover_mime' => 'image/png',
            'page_count' => 1, 'file_size' => 100, 'checksum' => hash('sha256', 'test'),
        ], $overrides));
    }

    public function test_library_migration_checks_both_objects_is_dry_by_default_and_idempotent(): void
    {
        $story = $this->story();
        Storage::disk('local')->put($story->pdf_path, 'original');
        Storage::disk('s3_private')->put($story->pdf_path, 'copied');
        $options = ['--target-public' => 'public'];
        $this->assertSame(1, Artisan::call('media:verify-s3-references', $options));
        $this->assertStringContainsString('library_stories.cover_path', Artisan::output());
        $this->assertSame(0, Artisan::call('media:migrate-disk-references', $options + ['--apply' => true]));
        $this->assertSame('local', $story->fresh()->disk);

        Storage::disk('s3_private')->put($story->cover_path, 'cover');
        $this->assertSame(0, Artisan::call('media:verify-s3-references', $options));
        $this->assertSame(0, Artisan::call('media:migrate-disk-references', $options));
        $this->assertSame('local', $story->fresh()->disk);
        foreach ([1, 2] as $attempt) {
            $this->assertSame(0, Artisan::call('media:migrate-disk-references', $options + ['--apply' => true]));
            $this->assertSame('s3_private', $story->fresh()->disk);
            $this->assertSame($story->pdf_path, $story->fresh()->pdf_path);
        }
        Storage::disk('local')->assertExists($story->pdf_path);
        $this->assertTrue(Storage::disk('s3')->exists('private/'.$story->pdf_path));
    }

    public function test_library_without_optional_cover_can_migrate_and_deleted_records_are_preserved(): void
    {
        $story = $this->story(['cover_path' => null]);
        $story->delete();
        Storage::disk('s3_private')->put($story->pdf_path, 'archived pdf');
        $this->artisan('media:migrate-disk-references', ['--apply' => true, '--target-public' => 'public'])->assertSuccessful();
        $this->assertSame('s3_private', $story->fresh()->disk);
        $this->assertSoftDeleted($story);
    }

    public function test_public_library_s3_pdf_range_and_cover_reads_keep_paths_private_and_clean_temporary_files(): void
    {
        $story = $this->story(['disk' => 's3_private']);
        $pdf = str_repeat('0123456789', 10);
        Storage::disk('s3_private')->put($story->pdf_path, $pdf);
        Storage::disk('s3_private')->put($story->cover_path, 'test-cover-bytes');
        $this->getJson(route('api.story-library.show', $story))->assertOk()->assertJsonMissingPath('data.disk')->assertJsonMissingPath('data.pdf_path');
        foreach (['story-library.document', 'api.story-library.document'] as $route) {
            $response = $this->withHeader('Range', 'bytes=10-19')->get(route($route, $story))
                ->assertStatus(206)->assertHeader('Content-Type', 'application/pdf')
                ->assertHeader('Content-Range', 'bytes 10-19/100')->assertHeader('Content-Length', '10');
            $this->assertSame('0123456789', $this->sendFile($response));
        }
        $this->flushHeaders();
        $response = $this->get(route('story-library.document', $story))->assertOk();
        $this->assertSame($pdf, $this->sendFile($response));
        $response = $this->get(route('story-library.cover', $story))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame('test-cover-bytes', $this->sendFile($response));
        $this->assertSame([], Storage::disk('local')->allFiles('media-responses'));
        $story->update(['status' => 'draft']);
        $this->get(route('story-library.document', $story))->assertNotFound();
        $this->get(route('story-library.cover', $story))->assertNotFound();
    }

    private function sendFile($response): string
    {
        ob_start();
        try {
            $response->baseResponse->sendContent();

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    public function test_new_library_uploads_use_configured_private_disk(): void
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $pdf->WriteHTML('<p>Isolated migration test</p>');
        $this->actingAs($this->admin())->post(route('admin.story-library.store'), [
            'title' => 'New library story', 'language' => 'ar', 'reading_direction' => 'rtl', 'status' => 'draft', 'sort_order' => 0,
            'pdf_file' => UploadedFile::fake()->createWithContent('story.pdf', $pdf->Output('', 'S')),
            'cover' => UploadedFile::fake()->image('cover.png'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $story = LibraryStory::sole();
        $this->assertSame('s3_private', $story->disk);
        $this->assertTrue(Storage::disk('s3')->exists('private/'.$story->pdf_path));
        $this->assertTrue(Storage::disk('s3')->exists('private/'.$story->cover_path));
        Storage::disk('local')->assertMissing($story->pdf_path);
    }

    public function test_studio_scoped_assets_publish_and_download_with_checksums_without_local_paths(): void
    {
        $user = $this->admin();
        $service = app(StudioSettingsService::class);
        $asset = $service->upload(UploadedFile::fake()->image('logo.png', 20, 30), $user);
        $this->assertTrue(Storage::disk('s3')->exists('private/studio-private/'.$asset->path));
        $this->assertFalse(Storage::disk('s3')->exists('private/'.$asset->path));
        $this->assertTrue($service->intact($asset));
        $settings = StudioSettingsSchema::defaults();
        $settings['default_back_cover'] = ['asset_id' => $asset->id, 'crop' => ['focalX' => 0.5, 'focalY' => 0.5, 'zoom' => 1]];
        $revision = $service->publish($settings, 0, $user, 'Migration test');
        $this->assertSame($asset->checksum, $service->snapshot($revision)['assets'][0]['sha256']);
        $url = '/api/studio/settings/assets/'.$asset->id;
        $this->getJson($url)->assertUnauthorized();
        $token = $this->postJson('/api/studio/auth/login', ['login' => $user->email, 'password' => 'migration-test-password'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/studio/settings')->assertOk()->assertJsonPath('data.revision', (string) $revision->id);
        $download = $this->get($url)->assertOk()->assertHeader('X-Checksum-SHA256', $asset->checksum);
        $this->assertSame($asset->checksum, hash('sha256', $download->streamedContent()));
        $bytes = Storage::disk('studio_private')->get($asset->path);
        Storage::disk('studio_private')->put($asset->path, str_repeat('x', strlen($bytes)));
        $this->assertFalse($service->intact($asset));
        $this->getJson($url)->assertStatus(503);
    }

    public function test_studio_hostinger_fallback_keeps_original_local_root_and_upload_behavior(): void
    {
        $config = $this->studioConfig();
        $this->assertSame('local', $config['driver']);
        $this->assertSame(storage_path('app/studio-private'), $config['root']);
        $this->assertSame('private', $config['visibility']);
        // Test the same local adapter against an isolated directory, not live assets.
        $config['root'] = Storage::disk('local')->path('studio-hostinger-test');
        config(['filesystems.disks.studio_private' => $config]);
        Storage::forgetDisk('studio_private');
        $service = app(StudioSettingsService::class);
        $asset = $service->upload(UploadedFile::fake()->image('back.png'), $this->admin());
        $this->assertTrue($service->intact($asset));
        $this->assertFileExists(Storage::disk('studio_private')->path($asset->path));
        $this->assertFalse(Storage::disk('s3')->exists('private/studio-private/'.$asset->path));
    }

    public function test_studio_cover_job_reads_reference_writes_downloadable_output_and_purges_only_expired_covers(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config(['queue.connections.database.retry_after' => 360]);
        $user = $this->admin();
        $provider = AiProvider::where('driver', 'openai')->firstOrFail();
        $provider->update(['is_active' => true]);
        app(AiProviderCredentialService::class)->save($provider, 'sk-isolated-test-never-live');
        $provider->models()->where('code', 'gpt-image-2')->update(['is_active' => true]);
        $settings = StudioSettingsSchema::defaults();
        $settings['openai_cover']['enabled'] = true;
        $service = app(StudioSettingsService::class);
        $asset = $service->upload(UploadedFile::fake()->image('logo.png'), $user);
        $revision = $service->publish($settings, 0, $user, 'Cover storage test');
        $story = Story::create(['title' => 'Test story', 'slug' => 'migration-story', 'language' => 'ar', 'gender' => 'both', 'price' => 350, 'active' => true]);
        $order = Order::create(['order_number' => 'HK-MIGRATION-TEST', 'checkout_group_key' => 'MIGRATION-TEST',
            'story_id' => $story->id, 'child_name' => 'Test', 'child_age' => 7, 'child_gender' => 'boy', 'status' => 'new', 'language' => 'ar', 'uploaded_photos' => []]);
        $token = $this->postJson('/api/studio/auth/login', ['login' => $user->email, 'password' => 'migration-test-password'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $outputFile = UploadedFile::fake()->image('output.png', 24, 36);
        $output = file_get_contents($outputFile->getRealPath());
        Http::fake(['api.openai.com/v1/images/edits' => Http::response(['data' => [['b64_json' => base64_encode($output)]]], 200)]);
        $id = $this->withToken($token)->postJson('/api/studio/covers', [
            'request_id' => (string) Str::uuid(), 'settings_revision' => $revision->id,
            'order_number' => $order->order_number, 'production_unit_key' => 'story:'.$order->id,
            'kind' => 'story', 'confirmed_billable' => true,
            'reference_image' => UploadedFile::fake()->image('reference.png', 20, 20),
        ])->assertAccepted()->json('data.id');
        $this->assertTrue(Storage::disk('s3')->exists('private/studio-private/covers/'.$id.'/reference'));
        (new GenerateStudioCover($id))->handle(app(StudioCoverService::class));
        $generation = StudioCoverGeneration::findOrFail($id);
        $this->assertSame('completed', $generation->status);
        $this->assertFalse(Storage::disk('studio_private')->exists('covers/'.$id.'/reference'));
        $this->assertTrue(Storage::disk('s3')->exists('private/studio-private/'.$generation->output_path));
        $this->assertSame($output, $this->get('/api/studio/covers/'.$id.'/image')->assertOk()->streamedContent());
        Http::assertSentCount(1);
        $this->assertSame('new', $order->fresh()->status);
        $this->assertDatabaseCount('order_attachments', 0);
        $generation->update(['created_at' => now()->subHours((int) config('remote_studio.cover_result_hours') + 1)]);
        $this->artisan('studio:purge-cover-media')->assertSuccessful();
        $this->assertFalse(Storage::disk('studio_private')->exists($generation->output_path));
        $this->assertTrue($service->intact($asset));
    }

    public function test_old_and_new_expense_files_stay_local_and_are_excluded_from_s3_migration(): void
    {
        $user = $this->admin();
        $transaction = app(ExpenseLedgerService::class)->create([
            'type' => 'expense', 'amount' => 100, 'transaction_date' => today()->toDateString(),
            'category_id' => ExpenseCategory::where('type', 'expense')->firstOrFail()->id,
        ], $user, UploadedFile::fake()->createWithContent('invoice.pdf', 'legacy invoice'), [
            UploadedFile::fake()->createWithContent('extra.pdf', 'additional invoice'),
        ]);
        $attachment = $transaction->attachments()->sole();
        $legacyPath = $transaction->attachment_path;
        $this->assertSame('local', $attachment->disk);
        Storage::disk('local')->assertExists([$legacyPath, $attachment->path]);
        $this->assertFalse(Storage::disk('s3_private')->exists($legacyPath));
        $this->assertFalse(Storage::disk('s3_private')->exists($attachment->path));
        // Simulate legacy paths verbatim, before the new storage setting existed.
        Storage::disk('local')->put('expenses/legacy/receipt.pdf', 'legacy invoice');
        $transaction->update(['attachment_path' => 'expenses/legacy/receipt.pdf']);
        $before = $transaction->getAttributes();
        $this->actingAs($user);
        foreach (['admin.expenses.attachment', 'admin.expenses.attachment.download'] as $route) {
            $this->assertSame('legacy invoice', $this->get(route($route, $transaction))->assertOk()->streamedContent());
        }
        foreach (['admin.expenses.attachments.show', 'admin.expenses.attachments.download'] as $route) {
            $this->assertSame('additional invoice', $this->get(route($route, [$transaction, $attachment]))->assertOk()->streamedContent());
        }
        // Even if copies happen to exist on S3, financial references must not move.
        Storage::disk('s3_private')->put($attachment->path, 'copy');
        $options = ['--target-public' => 'public'];
        $this->assertSame(0, Artisan::call('media:verify-s3-references', $options));
        $this->assertStringNotContainsString('expense_', Artisan::output());
        $this->assertSame(0, Artisan::call('media:migrate-disk-references', $options + ['--apply' => true]));
        $this->assertSame('local', $attachment->fresh()->disk);
        $this->assertSame($attachment->path, $attachment->fresh()->path);
        $this->assertSame($before, $transaction->fresh()->getAttributes());
        Storage::disk('local')->assertExists($transaction->attachment_path);
    }
}
