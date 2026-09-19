<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\User;
use App\Services\MediaLibrary\AdminMediaLibraryService;
use App\Services\Orders\OrderAttachmentService;
use App\Services\Storage\MediaStorage;
use App\Services\Storage\PersistentMediaResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HybridMediaStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_s3_disks_and_hostinger_safe_defaults_are_configured(): void
    {
        $this->assertSame('local', config('media.private_disk'));
        $this->assertSame('public', config('media.public_disk'));
        $this->assertSame('local', config('media.processing_disk'));
        $this->assertSame('scoped', config('filesystems.disks.s3_private.driver'));
        $this->assertSame('s3', config('filesystems.disks.s3_private.disk'));
        $this->assertSame('private', config('filesystems.disks.s3_private.prefix'));
        $this->assertSame('private', config('filesystems.disks.s3_public.visibility'));
        $environmentExample = file_get_contents(base_path('.env.example'));
        $this->assertStringNotContainsString('AWS_ACCESS_KEY_ID=', $environmentExample);
        $this->assertStringNotContainsString('AWS_SECRET_ACCESS_KEY=', $environmentExample);
    }

    public function test_media_storage_copies_reads_builds_checksums_and_cleans_local_working_files(): void
    {
        Storage::fake('source');
        Storage::fake('persistent');
        Storage::fake('processing');
        config([
            'media.processing_disk' => 'processing',
            'filesystems.disks.processing.driver' => 'local',
        ]);

        Storage::disk('source')->put('incoming/source.txt', 'HeroKid media');
        $media = app(MediaStorage::class);

        $contents = $media->withLocalCopy('source', 'incoming/source.txt', fn (string $path): string => file_get_contents($path));
        $this->assertSame('HeroKid media', $contents);
        $this->assertSame([], Storage::disk('processing')->allFiles('media-processing'));

        $media->copy('source', 'incoming/source.txt', 'persistent', 'saved/copy.txt');
        Storage::disk('persistent')->assertExists('saved/copy.txt');
        $this->assertSame(hash('sha256', 'HeroKid media'), $media->checksum('persistent', 'saved/copy.txt'));

        $result = $media->buildAndStore('persistent', 'saved/generated.txt', function (string $path): string {
            file_put_contents($path, 'generated output');

            return 'built';
        });
        $this->assertSame('built', $result);
        $this->assertSame('generated output', Storage::disk('persistent')->get('saved/generated.txt'));
        $this->assertSame([], Storage::disk('processing')->allFiles('media-processing'));

        Storage::disk('persistent')->delete('saved/copy.txt');
        Storage::disk('persistent')->assertMissing('saved/copy.txt');
    }

    public function test_order_attachment_uses_configured_private_disk(): void
    {
        Storage::fake('s3_private');
        config(['media.private_disk' => 's3_private']);
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order();

        $attachment = app(OrderAttachmentService::class)->upload(
            $order,
            [UploadedFile::fake()->create('production.pdf', 10, 'application/pdf')],
            null,
            $admin,
        )->firstOrFail();

        $this->assertSame('s3_private', $attachment->disk);
        Storage::disk('s3_private')->assertExists($attachment->path);

        $attachment->delete();
        Storage::disk('s3_private')->assertMissing($attachment->path);
    }

    public function test_large_media_library_upload_assembles_locally_then_persists_and_cleans_chunks(): void
    {
        Storage::fake('s3_private');
        Storage::fake('processing');
        config([
            'media.private_disk' => 's3_private',
            'media.processing_disk' => 'processing',
            'filesystems.disks.processing.driver' => 'local',
        ]);
        $admin = User::factory()->create(['role' => 'admin']);
        $service = app(AdminMediaLibraryService::class);
        $contents = "HeroKid media library\n";
        $upload = $service->start($admin, 'instructions.txt', strlen($contents), 'text/plain', 'Production instructions');
        $upload = $service->appendChunk($upload, $admin, 0, UploadedFile::fake()->createWithContent('chunk.part', $contents));

        $media = $service->complete($upload, $admin);

        $this->assertSame('s3_private', $media->disk);
        $this->assertSame('Production instructions', $media->title);
        $this->assertSame($contents, Storage::disk('s3_private')->get($media->path));
        $this->assertSame([], Storage::disk('processing')->allFiles('admin/media-library/uploads'));
        $response = $this->get($media->publicUrl())->assertOk();
        $this->assertSame($contents, $response->streamedContent());
    }

    public function test_s3_backed_inline_response_keeps_range_support_and_removes_materialized_file(): void
    {
        Storage::fake('remote_private');
        Storage::fake('processing');
        config([
            'media.processing_disk' => 'processing',
            'filesystems.disks.processing.driver' => 'local',
            'filesystems.disks.remote_private.driver' => 's3',
        ]);
        Storage::disk('remote_private')->put('previews/test.pdf', str_repeat('0123456789', 10));

        $request = Request::create('/preview', 'GET', [], [], [], ['HTTP_RANGE' => 'bytes=0-9']);
        $response = app(PersistentMediaResponse::class)
            ->inline('remote_private', 'previews/test.pdf', 'preview.pdf', ['Content-Type' => 'application/pdf']);
        $response->prepare($request);

        $this->assertSame(206, $response->getStatusCode());
        $this->assertStringStartsWith('bytes 0-9/', (string) $response->headers->get('Content-Range'));
        ob_start();
        $response->sendContent();
        ob_end_clean();
        $this->assertSame([], Storage::disk('processing')->allFiles('media-responses'));
    }

    public function test_disk_reference_migration_is_dry_run_by_default_skips_missing_and_is_idempotent(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('s3_private');
        Storage::fake('s3_public');
        $order = $this->order();
        $present = $this->attachment($order, 'present.pdf');
        $missing = $this->attachment($order, 'missing.pdf');
        Storage::disk('s3_private')->put($present->path, 'copied object');

        $this->assertSame(0, Artisan::call('media:migrate-disk-references'));
        $this->assertSame('local', $present->fresh()->disk);
        $this->assertSame('local', $missing->fresh()->disk);

        $this->assertSame(0, Artisan::call('media:migrate-disk-references', ['--apply' => true]));
        $this->assertSame('s3_private', $present->fresh()->disk);
        $this->assertSame('local', $missing->fresh()->disk);
        Storage::disk('local')->assertExists($present->path);
        Storage::disk('local')->assertExists($missing->path);
        Storage::disk('s3_private')->assertExists($present->path);

        $this->assertSame(0, Artisan::call('media:migrate-disk-references', ['--apply' => true]));
        $this->assertSame('s3_private', $present->fresh()->disk);
        $this->assertSame('local', $missing->fresh()->disk);
    }

    public function test_verification_command_checks_implicit_json_references_without_mutation(): void
    {
        Storage::fake('s3_private');
        Storage::fake('s3_public');
        $order = $this->order();
        $order->update(['uploaded_photos' => ['orders/photos/one.jpg']]);
        Storage::disk('s3_private')->put('orders/photos/one.jpg', 'photo');

        $this->assertSame(0, Artisan::call('media:verify-s3-references'));
        $this->assertSame(['orders/photos/one.jpg'], $order->fresh()->uploaded_photos);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'order_number' => 'HK-HYBRID-'.uniqid(),
            'checkout_group_key' => 'CHK-HYBRID-'.uniqid(),
            'parent_name' => 'Parent',
            'status' => 'new',
            'delivery_details' => ['phone' => '201000000000', 'delivery_fee' => 0],
        ]);
    }

    private function attachment(Order $order, string $name): OrderAttachment
    {
        $path = 'order-attachments/'.$order->id.'/'.$name;
        Storage::disk('local')->put($path, 'local object');

        return $order->attachments()->create([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 12,
            'expires_at' => now()->addMonth(),
        ]);
    }
}
