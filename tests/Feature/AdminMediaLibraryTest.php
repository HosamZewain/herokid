<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\AdminMediaFile;
use App\Models\AdminMediaUploadSession;
use App\Models\Permission;
use App\Models\User;
use App\Services\MediaLibrary\AdminMediaLibraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_sees_library_with_uploader_and_uploaded_at_metadata(): void
    {
        $admin = $this->admin();
        AdminMediaFile::create([
            'public_id' => '54f8c08c-e184-470d-b5c0-8a419757b213',
            'disk' => 'local',
            'path' => 'admin/media-library/files/sample.txt',
            'original_name' => 'دليل الاستخدام.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => 42,
            'sha256' => str_repeat('a', 64),
            'uploaded_by' => $admin->id,
            'uploaded_by_name' => $admin->name,
        ]);

        $this->actingAs($admin)->get(route('admin.media-library.index'))
            ->assertOk()
            ->assertSee('مكتبة الوسائط')
            ->assertSee('دليل الاستخدام.txt')
            ->assertSee($admin->name)
            ->assertSee('حتى 300MB')
            ->assertSee('data-media-uploader', false);
    }

    public function test_upload_is_chunked_and_completed_file_gets_permanent_public_url(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $contents = "تعليمات HeroKid\nالسطر الثاني";
        $chunk = UploadedFile::fake()->createWithContent('library-upload.part', $contents);

        $start = $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'تعليمات.txt',
            'title' => 'تعليمات فريق الإنتاج',
            'size' => strlen($contents),
            'mime' => 'text/plain',
        ])->assertCreated()
            ->assertJsonPath('data.chunk_size', AdminMediaLibraryService::CHUNK_SIZE)
            ->json('data');

        $this->post($start['chunk_url'], ['index' => 0, 'chunk' => $chunk], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.bytes_received', strlen($contents));

        $completed = $this->postJson($start['complete_url'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'تعليمات.txt')
            ->json('data');

        $media = AdminMediaFile::firstOrFail();
        $this->assertSame($completed['id'], $media->public_id);
        $this->assertSame('تعليمات فريق الإنتاج', $media->title);
        $this->assertSame($admin->id, $media->uploaded_by);
        $this->assertSame($admin->name, $media->uploaded_by_name);
        $this->assertArrayNotHasKey('expires_at', $media->getAttributes());
        Storage::disk('local')->assertExists($media->path);
        $this->assertDatabaseCount('admin_media_upload_sessions', 0);

        auth()->logout();
        $this->get($completed['public_url'])
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->assertSame($contents, Storage::disk('local')->get($media->path));

        $log = AdminActivityLog::where('action', 'media_library.file_uploaded')->firstOrFail();
        $this->assertSame('تعليمات.txt', $log->properties['file_name']);
        $this->assertArrayNotHasKey('path', $log->properties);
    }

    public function test_optional_title_is_searchable_and_rendered_without_hiding_original_filename(): void
    {
        $admin = $this->admin();
        AdminMediaFile::create([
            'public_id' => '0b2b86f6-d6a8-40d6-923d-9600fe721ba5',
            'disk' => 'local',
            'path' => 'admin/media-library/files/catalog.pdf',
            'original_name' => 'original-catalog.pdf',
            'title' => 'كتالوج سبتمبر',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'sha256' => str_repeat('c', 64),
            'uploaded_by' => $admin->id,
            'uploaded_by_name' => $admin->name,
        ]);

        $this->actingAs($admin)->get(route('admin.media-library.index', ['q' => 'سبتمبر']))
            ->assertOk()
            ->assertSee('كتالوج سبتمبر')
            ->assertSee('original-catalog.pdf');

        $start = $this->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'without-title.txt',
            'title' => '',
            'size' => 10,
        ])->assertCreated()->json('data');
        $this->assertNull(AdminMediaUploadSession::where('public_id', $start['upload_id'])->value('title'));
    }

    public function test_image_upload_is_validated_from_actual_content_not_browser_mime(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $image = UploadedFile::fake()->image('child.png', 30, 30);
        $size = $image->getSize();

        $start = $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'child.png',
            'size' => $size,
            'mime' => 'application/octet-stream',
        ])->assertCreated()->json('data');

        $this->post($start['chunk_url'], ['index' => 0, 'chunk' => $image], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($start['complete_url'])->assertCreated();

        $this->assertSame('image/png', AdminMediaFile::firstOrFail()->mime_type);
    }

    public function test_file_larger_than_one_chunk_is_assembled_without_changing_bytes(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $firstContents = str_repeat('A', AdminMediaLibraryService::CHUNK_SIZE);
        $secondContents = "\nHeroKid tail";
        $totalSize = strlen($firstContents) + strlen($secondContents);

        $start = $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'large-notes.txt',
            'size' => $totalSize,
            'mime' => 'text/plain',
        ])->assertCreated()
            ->assertJsonPath('data.total_chunks', 2)
            ->json('data');

        $this->post($start['chunk_url'], [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('part-0', $firstContents),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->post($start['chunk_url'], [
            'index' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('part-1', $secondContents),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($start['complete_url'])->assertCreated();

        $media = AdminMediaFile::firstOrFail();
        $this->assertSame($totalSize, $media->size);
        $this->assertSame(hash('sha256', $firstContents.$secondContents), $media->sha256);
        $this->assertSame($firstContents.$secondContents, Storage::disk('local')->get($media->path));
    }

    public function test_pdf_is_accepted_and_html_inside_txt_is_served_as_inert_plain_text(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $pdf = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

        $pdfStart = $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'manual.pdf',
            'size' => strlen($pdf),
            'mime' => 'application/pdf',
        ])->assertCreated()->json('data');
        $this->post($pdfStart['chunk_url'], [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('pdf-part', $pdf),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->postJson($pdfStart['complete_url'])->assertCreated();

        $text = '<script>alert("not executable")</script>';
        $textStart = $this->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'example.txt',
            'size' => strlen($text),
            'mime' => 'text/html',
        ])->assertCreated()->json('data');
        $this->post($textStart['chunk_url'], [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('txt-part', $text),
        ], ['Accept' => 'application/json'])->assertOk();
        $textResult = $this->postJson($textStart['complete_url'])->assertCreated()->json('data');

        auth()->logout();
        $this->get($textResult['public_url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(['application/pdf', 'text/plain'], AdminMediaFile::orderBy('id')->pluck('mime_type')->all());
    }

    public function test_disguised_file_is_rejected_during_completion(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $contents = 'not a real image';
        $chunk = UploadedFile::fake()->createWithContent('fake.part', $contents);

        $start = $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'fake.jpg',
            'size' => strlen($contents),
            'mime' => 'image/jpeg',
        ])->assertCreated()->json('data');
        $this->post($start['chunk_url'], ['index' => 0, 'chunk' => $chunk], ['Accept' => 'application/json'])->assertOk();

        $this->postJson($start['complete_url'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('admin_media_files', 0);
    }

    public function test_upload_size_and_extensions_are_strictly_validated(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'too-large.pdf',
            'size' => AdminMediaLibraryService::MAX_FILE_SIZE + 1,
        ])->assertUnprocessable()->assertJsonValidationErrors('size');

        $this->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'unsafe.svg',
            'size' => 100,
            'mime' => 'image/svg+xml',
        ])->assertUnprocessable()->assertJsonValidationErrors('file_name');

        $this->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'maximum.pdf',
            'size' => AdminMediaLibraryService::MAX_FILE_SIZE,
            'mime' => 'application/pdf',
        ])->assertCreated();
    }

    public function test_chunks_must_arrive_in_order_and_only_session_owner_can_upload(): void
    {
        Storage::fake('local');
        $owner = $this->admin();
        $other = $this->admin();
        $contents = 'small file';

        $start = $this->actingAs($owner)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'ordered.txt',
            'size' => strlen($contents),
        ])->assertCreated()->json('data');

        $this->post($start['chunk_url'], [
            'index' => 1,
            'chunk' => UploadedFile::fake()->createWithContent('part', $contents),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('index');

        $this->actingAs($other)->post($start['chunk_url'], [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('part', $contents),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_view_and_upload_permissions_are_separate_and_customer_is_denied(): void
    {
        $viewer = $this->admin();
        $viewer->permissions()->sync([Permission::where('key', 'media_library.view')->value('id')]);
        $viewer->adminRoles()->detach();
        $viewer = $viewer->fresh();

        $this->actingAs($viewer)->get(route('admin.media-library.index'))
            ->assertOk()
            ->assertDontSee('رفع ملفات جديدة');
        $this->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'denied.txt',
            'size' => 10,
        ])->assertForbidden();

        $customer = User::factory()->create(['role' => 'customer', 'is_active' => true]);
        $this->actingAs($customer)->get(route('admin.media-library.index'))->assertForbidden();
    }

    public function test_authorized_delete_removes_file_and_disables_public_url_with_audit_log(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $media = AdminMediaFile::create([
            'public_id' => 'd29d66e6-24de-4710-b75e-3d7ed1edab33',
            'disk' => 'local',
            'path' => 'admin/media-library/files/delete-me.txt',
            'original_name' => 'delete-me.txt',
            'title' => 'ملف قديم',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => 9,
            'sha256' => str_repeat('d', 64),
            'uploaded_by' => $admin->id,
            'uploaded_by_name' => $admin->name,
        ]);
        Storage::disk('local')->put($media->path, 'delete me');
        $publicUrl = $media->publicUrl();

        $this->actingAs($admin)->deleteJson(route('admin.media-library.destroy', $media))->assertNoContent();

        $this->assertDatabaseMissing('admin_media_files', ['id' => $media->id]);
        Storage::disk('local')->assertMissing($media->path);
        $this->get($publicUrl)->assertNotFound();

        $log = AdminActivityLog::where('action', 'media_library.file_deleted')->firstOrFail();
        $this->assertSame('ملف قديم', $log->properties['title']);
        $this->assertArrayNotHasKey('path', $log->properties);
    }

    public function test_delete_requires_its_own_permission_and_button_is_hidden_without_it(): void
    {
        Storage::fake('local');
        $viewer = $this->admin();
        $viewer->permissions()->sync([Permission::where('key', 'media_library.view')->value('id')]);
        $viewer->adminRoles()->detach();
        $viewer = $viewer->fresh();
        $media = AdminMediaFile::create([
            'public_id' => '3bab7ef0-f3ea-42ba-97f7-6db1e65cab58',
            'disk' => 'local',
            'path' => 'admin/media-library/files/protected.txt',
            'original_name' => 'protected.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => 9,
            'sha256' => str_repeat('e', 64),
            'uploaded_by' => $viewer->id,
            'uploaded_by_name' => $viewer->name,
        ]);
        Storage::disk('local')->put($media->path, 'protected');

        $this->actingAs($viewer)->get(route('admin.media-library.index'))
            ->assertOk()
            ->assertDontSee(route('admin.media-library.destroy', $media), false);
        $this->deleteJson(route('admin.media-library.destroy', $media))->assertForbidden();
        Storage::disk('local')->assertExists($media->path);
    }

    public function test_cancel_removes_temporary_chunks_without_touching_completed_media(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $contents = 'temporary';
        $start = $this->actingAs($admin)->postJson(route('admin.media-library.uploads.store'), [
            'file_name' => 'temporary.txt',
            'size' => strlen($contents),
        ])->assertCreated()->json('data');

        $this->post($start['chunk_url'], [
            'index' => 0,
            'chunk' => UploadedFile::fake()->createWithContent('part', $contents),
        ], ['Accept' => 'application/json'])->assertOk();

        $session = AdminMediaUploadSession::firstOrFail();
        $this->deleteJson($start['cancel_url'])->assertNoContent();
        $this->assertDatabaseCount('admin_media_upload_sessions', 0);
        $this->assertSame([], Storage::disk('local')->allFiles($session->temp_directory));
    }

    public function test_cleanup_removes_only_abandoned_incomplete_uploads_and_never_completed_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $completed = AdminMediaFile::create([
            'public_id' => '551677f0-c141-44c8-afb4-d454aa458acf',
            'disk' => 'local',
            'path' => 'admin/media-library/files/permanent.txt',
            'original_name' => 'permanent.txt',
            'extension' => 'txt',
            'mime_type' => 'text/plain',
            'size' => 9,
            'sha256' => str_repeat('b', 64),
            'uploaded_by' => $admin->id,
            'uploaded_by_name' => $admin->name,
        ]);
        Storage::disk('local')->put($completed->path, 'permanent');

        $abandoned = app(AdminMediaLibraryService::class)->start($admin, 'abandoned.txt', 10, 'text/plain');
        Storage::disk('local')->put($abandoned->temp_directory.'/chunks/000000.part', 'fragment');
        DB::table('admin_media_upload_sessions')->where('id', $abandoned->id)->update(['updated_at' => now()->subDays(2)]);

        $this->artisan('media-library:cleanup-incomplete-uploads', ['--hours' => 24])
            ->assertSuccessful()
            ->expectsOutputToContain('Completed library files were untouched');

        $this->assertDatabaseMissing('admin_media_upload_sessions', ['id' => $abandoned->id]);
        Storage::disk('local')->assertMissing($abandoned->temp_directory.'/chunks/000000.part');
        $this->assertDatabaseHas('admin_media_files', ['id' => $completed->id]);
        Storage::disk('local')->assertExists($completed->path);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }
}
