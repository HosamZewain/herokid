<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\DatabaseExport;
use App\Models\Permission;
use App\Models\User;
use App\Services\DatabaseExports\DatabaseDumpWriter;
use App\Services\DatabaseExports\DatabaseExportService;
use App\Support\AdminRoleRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AdminDatabaseExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media.processing_disk' => 'local', 'media.private_disk' => 'local']);
        Storage::fake('local');
        $this->writer();
    }

    public function test_page_is_private_and_available_only_with_export_permission(): void
    {
        $this->get(route('admin.database-exports.index'))->assertRedirect();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer)->get(route('admin.database-exports.index'))->assertForbidden();
        $admin = $this->admin();
        $admin->permissions()->detach();
        $admin->unsetRelation('permissions');
        $this->actingAs($admin)->get(route('admin.database-exports.index'))->assertForbidden();
    }

    public function test_page_shows_password_protection_and_database_only_warning(): void
    {
        $this->actingAs($this->admin())->get(route('admin.database-exports.index'))->assertOk()
            ->assertSee('تصدير قاعدة البيانات')->assertSee('24 ساعة')->assertSee('لا يشمل الصور')
            ->assertSee('type="password"', false)->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
    }

    public function test_administrator_role_does_not_gain_sensitive_export_but_owner_does(): void
    {
        $this->assertNotContains('database_exports.manage', AdminRoleRegistry::permissionKeys('administrator'));
        $this->assertContains('database_exports.manage', AdminRoleRegistry::permissionKeys('owner'));
    }

    public function test_wrong_password_cannot_queue_or_be_flashed_into_session(): void
    {
        $this->actingAs($this->admin())->post(route('admin.database-exports.store'), ['current_password' => 'wrong-secret'])
            ->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
        $this->assertDatabaseCount('database_exports', 0);
    }

    public function test_request_is_queued_and_never_dumps_inside_web_request(): void
    {
        $writer = $this->writer();
        $writer->shouldNotReceive('write');
        $admin = $this->admin();
        $this->actingAs($admin)->post(route('admin.database-exports.store'), ['current_password' => 'password'])->assertRedirect(route('admin.database-exports.index'));
        $export = DatabaseExport::sole();
        $this->assertSame('queued', $export->status);
        $this->assertSame($admin->id, $export->requested_by);
        $this->assertNull($export->path);
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'database_export.requested']);
        $this->assertStringNotContainsString('password', json_encode(AdminActivityLog::first()->properties));
    }

    public function test_only_one_pending_request_can_exist_across_users(): void
    {
        $service = app(DatabaseExportService::class);
        $service->request($this->admin());
        $this->actingAs($this->admin())->post(route('admin.database-exports.store'), ['current_password' => 'password'])->assertSessionHasErrors('export');
        $this->assertDatabaseCount('database_exports', 1);
    }

    public function test_unavailable_native_dump_does_not_queue_incomplete_backup(): void
    {
        $this->writer(false);
        $this->actingAs($this->admin())->post(route('admin.database-exports.store'), ['current_password' => 'password'])->assertSessionHasErrors('export');
        $this->assertDatabaseCount('database_exports', 0);
    }

    public function test_public_processing_or_persistent_disk_is_rejected(): void
    {
        config(['media.private_disk' => 'public']);
        $this->actingAs($this->admin())->post(route('admin.database-exports.store'), ['current_password' => 'password'])->assertSessionHasErrors('export');
        $this->assertDatabaseCount('database_exports', 0);
    }

    public function test_public_root_is_rejected_even_if_disk_is_named_private(): void
    {
        config(['filesystems.disks.local.root' => storage_path('app/public')]);
        $this->actingAs($this->admin())->post(route('admin.database-exports.store'), ['current_password' => 'password'])->assertSessionHasErrors('export');
    }

    public function test_export_list_is_requester_only_and_json_has_no_internal_paths(): void
    {
        $owner = $this->admin();
        $own = $this->export($owner);
        $other = $this->export($this->admin());
        $response = $this->actingAs($owner)->getJson(route('admin.database-exports.index'))->assertOk()->assertJsonCount(1, 'exports');
        $this->assertSame($own->uuid, $response->json('exports.0.uuid'));
        $this->assertStringNotContainsString($other->uuid, $response->getContent());
        $this->assertSame(['uuid', 'status', 'size'], array_keys($response->json('exports.0')));
    }

    public function test_background_export_compresses_streams_to_private_disk_and_removes_work_files(): void
    {
        Storage::fake('s3_private');
        config(['media.private_disk' => 's3_private']);
        $export = $this->export($this->admin());
        $sql = "CREATE TABLE sample (name TEXT);\nINSERT INTO sample VALUES ('اختبار');\n";
        $this->writer()->shouldReceive('write')->once()->andReturnUsing(function ($path) use ($sql) {
            $this->assertStringContainsString('/admin/database-exports/work/', $path);
            file_put_contents($path, $sql);
        });
        $service = app(DatabaseExportService::class);
        $this->assertTrue($service->processPending());
        $export->refresh();
        $this->assertSame('ready', $export->status);
        $this->assertSame('s3_private', $export->disk);
        $bytes = Storage::disk('s3_private')->get($export->path);
        $this->assertSame($sql, gzdecode($bytes));
        $this->assertSame(strlen($bytes), $export->size);
        $this->assertSame(hash('sha256', $bytes), $export->sha256);
        $this->assertSame(24, (int) $export->completed_at->diffInHours($export->expires_at));
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertFalse($service->processPending());
    }

    public function test_dump_failure_is_sanitized_and_never_becomes_downloadable(): void
    {
        $export = $this->export($this->admin());
        $this->writer()->shouldReceive('write')->andThrow(new RuntimeException('password=private-secret connection failed'));
        app(DatabaseExportService::class)->processPending();
        $export->refresh();
        $this->assertSame('failed', $export->status);
        $this->assertSame('DUMP_FAILED', $export->error_code);
        $this->assertNull($export->path);
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertStringNotContainsString('private-secret', json_encode($export->toArray()));
    }

    public function test_persistent_write_failure_cleans_partial_object_and_work_directory(): void
    {
        $export = $this->export($this->admin());
        $this->successfulWriter();
        $disk = Mockery::mock(FilesystemAdapter::class);
        Storage::set('export_failure', $disk);
        config(['media.private_disk' => 'export_failure']);
        $disk->shouldReceive('writeStream')->once()->andReturn(false);
        $disk->shouldReceive('delete')->once()->with('admin/database-exports/files/'.$export->uuid.'.sql.gz')->andReturn(true);
        app(DatabaseExportService::class)->processPending();
        $this->assertSame('failed', $export->fresh()->status);
        $this->assertSame('STORAGE_FAILED', $export->fresh()->error_code);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_database_save_failure_removes_uploaded_copy(): void
    {
        $export = $this->export($this->admin());
        $this->successfulWriter();
        DatabaseExport::saving(function ($model) {
            if ($model->status === 'ready') {
                throw new RuntimeException('db failed');
            }
        });
        try {
            app(DatabaseExportService::class)->processPending();
            $this->assertSame('failed', $export->fresh()->status);
            $this->assertEmpty(Storage::disk('local')->allFiles());
        } finally {
            DatabaseExport::flushEventListeners();
        }
    }

    public function test_download_requires_password_again_and_get_is_not_allowed(): void
    {
        $admin = $this->admin();
        $export = $this->ready($admin);
        $this->actingAs($admin)->get(route('admin.database-exports.download', $export))->assertStatus(405);
        $this->post(route('admin.database-exports.download', $export), ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    }

    public function test_requester_can_stream_private_backup_and_download_is_audited(): void
    {
        Storage::fake('s3_private');
        $admin = $this->admin();
        $export = $this->ready($admin, 's3_private');
        $response = $this->actingAs($admin)->post(route('admin.database-exports.download', $export), ['current_password' => 'password'])
            ->assertOk()->assertHeader('Content-Type', 'application/gzip')->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('sanitized SQL fixture', gzdecode($response->streamedContent()));
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'database_export.downloaded']);
    }

    public function test_other_privileged_admin_cannot_download_requesters_backup(): void
    {
        $export = $this->ready($this->admin());
        $this->actingAs($this->admin())->post(route('admin.database-exports.download', $export), ['current_password' => 'password'])->assertForbidden();
    }

    public function test_revoked_permission_blocks_existing_ready_download(): void
    {
        $admin = $this->admin();
        $export = $this->ready($admin);
        $admin->permissions()->detach(Permission::where('key', 'database_exports.manage')->value('id'));
        $admin->unsetRelation('permissions');
        $this->actingAs($admin)->post(route('admin.database-exports.download', $export), ['current_password' => 'password'])->assertForbidden();
    }

    public function test_expired_backup_cannot_be_downloaded_before_cleanup_runs(): void
    {
        $admin = $this->admin();
        $export = $this->ready($admin);
        $export->update(['expires_at' => now()->subSecond()]);
        $this->actingAs($admin)->post(route('admin.database-exports.download', $export), ['current_password' => 'password'])->assertStatus(410);
    }

    public function test_missing_or_invalid_path_cannot_download_unrelated_private_files(): void
    {
        $admin = $this->admin();
        $export = $this->ready($admin);
        $export->update(['path' => 'unrelated.txt']);
        Storage::disk('local')->put('unrelated.txt', 'private unrelated');
        $this->actingAs($admin)->post(route('admin.database-exports.download', $export), ['current_password' => 'password'])->assertNotFound();
    }

    public function test_cleanup_deletes_only_expired_export_copies_not_media_or_database(): void
    {
        $admin = $this->admin();
        $expired = $this->ready($admin);
        $expired->update(['expires_at' => now()->subSecond()]);
        $active = $this->ready($admin);
        Storage::disk('local')->put('admin/media-library/files/example.pdf', 'media');
        $this->assertSame(1, app(DatabaseExportService::class)->cleanup());
        Storage::disk('local')->assertMissing($expired->path);
        Storage::disk('local')->assertExists($active->path);
        Storage::disk('local')->assertExists('admin/media-library/files/example.pdf');
        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_stalled_process_is_recovered_without_touching_other_files(): void
    {
        $export = $this->export($this->admin());
        $export->update(['status' => 'processing', 'started_at' => now()->subHour()]);
        Storage::disk('local')->put('admin/database-exports/files/'.$export->uuid.'.sql.gz', 'partial');
        $service = app(DatabaseExportService::class);
        $service->cleanup();
        $this->assertSame('WORKER_INTERRUPTED', $export->fresh()->error_code);
        $service->cleanup();
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_cleanup_command_and_background_schedule_are_registered(): void
    {
        $this->artisan('database-exports:cleanup')->assertSuccessful();
        $events = app(Schedule::class)->events();
        $event = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'database-exports:process'));
        $this->assertNotNull($event);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('* * * * *', $event->expression);
    }

    private function writer(bool $available = true)
    {
        $writer = Mockery::mock(DatabaseDumpWriter::class);
        $writer->shouldReceive('available')->andReturn($available);
        $this->app->instance(DatabaseDumpWriter::class, $writer);

        return $writer;
    }

    private function successfulWriter(): void
    {
        $this->writer()->shouldReceive('write')->once()->andReturnUsing(fn ($path) => file_put_contents($path, 'sanitized SQL fixture'));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'is_active' => true]);
    }

    private function export(User $user): DatabaseExport
    {
        return DatabaseExport::create(['uuid' => (string) Str::uuid(), 'requested_by' => $user->id, 'status' => 'queued', 'expires_at' => now()->addHours(2)]);
    }

    private function ready(User $user, string $disk = 'local'): DatabaseExport
    {
        $export = $this->export($user);
        $path = 'admin/database-exports/files/'.$export->uuid.'.sql.gz';
        Storage::disk($disk)->put($path, gzencode('sanitized SQL fixture'));
        $export->update(['status' => 'ready', 'disk' => $disk, 'path' => $path, 'filename' => 'database.sql.gz', 'expires_at' => now()->addDay()]);

        return $export;
    }
}
