<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\TemporaryPhotoUpload;
use App\Models\User;
use App\Services\Orders\OrderAttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermanentOrderMediaTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        return Order::create(['order_number' => 'HK-MEDIA-RETENTION-TEST', 'parent_name' => 'Synthetic Parent',
            'child_name' => 'Synthetic Child', 'child_age' => 6, 'child_gender' => 'girl', 'language' => 'ar',
            'status' => 'completed', 'uploaded_photos' => ['orders/photos/retained.png'], 'delivery_details' => []]);
    }

    public function test_new_and_old_files_on_private_s3_remain_available_after_a_year_and_legacy_cleanup(): void
    {
        Storage::fake('s3_private');
        config(['media.private_disk' => 's3_private']);
        $order = $this->order();
        $attachment = app(OrderAttachmentService::class)->upload($order,
            [UploadedFile::fake()->create('synthetic-production.pdf', 10, 'application/pdf')], null, null)->first();
        $attachment->update(['expires_at' => now()->subDays(90), 'validity_days' => 30]);
        $this->travel(366)->days();
        $this->artisan('order-attachments:cleanup')->assertSuccessful();
        $this->assertDatabaseHas('order_attachments', ['id' => $attachment->id]);
        Storage::disk('s3_private')->assertExists($attachment->path);
        $this->assertFalse($attachment->fresh()->isExpired());
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.orders.attachments.download', $attachment))->assertOk();
        $this->travelBack();
    }

    public function test_migration_cancels_old_deadlines_without_changing_files_ids_or_timestamps(): void
    {
        Storage::fake('local');
        $path = 'order-attachments/17/synthetic-reference.png';
        Storage::disk('local')->put($path, 'synthetic reference');
        // Isolate upgrade DDL from RefreshDatabase's surrounding MySQL transaction.
        config(['database.connections.retention_upgrade' => ['driver' => 'sqlite', 'database' => ':memory:',
            'prefix' => '', 'foreign_key_constraints' => false]]);
        $default = DB::getDefaultConnection();
        $schema = Schema::getFacadeRoot();
        DB::setDefaultConnection('retention_upgrade');
        Schema::swap(Schema::connection('retention_upgrade'));
        try {
            $original = require database_path('migrations/2026_08_28_000000_create_order_attachments_table.php');
            $original->up();
            DB::table('order_attachments')->insert(['id' => 45, 'order_id' => 17, 'disk' => 'local', 'path' => $path,
                'original_name' => 'synthetic-reference.png', 'mime_type' => 'image/png', 'size' => 19,
                'note' => 'Keep note verbatim', 'validity_days' => 30, 'expires_at' => '2026-01-01 00:00:00',
                'created_at' => '2025-12-01 00:00:00', 'updated_at' => '2025-12-01 00:00:00']);
            $before = (array) DB::table('order_attachments')->first();
            $migration = require database_path('migrations/2026_10_08_000000_keep_order_attachments_permanently.php');
            $migration->up();
            $after = (array) DB::table('order_attachments')->first();
            $this->assertNull($after['expires_at']);
            $this->assertNull($after['validity_days']);
            unset($before['expires_at'], $before['validity_days'], $after['expires_at'], $after['validity_days']);
            $this->assertSame($before, $after);
            $migration->up();
            $migration->down();
            $this->assertNull(DB::table('order_attachments')->value('expires_at'));
        } finally {
            Schema::swap($schema);
            DB::setDefaultConnection($default);
            DB::purge('retention_upgrade');
        }
        Storage::disk('local')->assertExists($path);
    }

    public function test_temporary_cleanup_keeps_old_order_photos_prepared_copies_and_identity_photos(): void
    {
        Storage::fake('local');
        $order = $this->order();
        foreach (['attached-order', 'attached-identity', 'old-order-link', 'unattached'] as $key) {
            $path = 'temporary-uploads/child-photos/'.$key.'.png';
            $prepared = 'temporary-uploads/child-photos/'.$key.'-prepared.jpg';
            Storage::disk('local')->put($path, 'synthetic original');
            Storage::disk('local')->put($prepared, 'synthetic derivative');
            TemporaryPhotoUpload::create(['public_id' => (string) Str::uuid(), 'session_hash' => 'synthetic',
                'disk' => 'local', 'path' => $path, 'prepared_disk' => 'local', 'prepared_path' => $prepared,
                'mime_type' => 'image/png', 'file_size' => 18, 'expires_at' => now()->subDays(120),
                'status' => in_array($key, ['attached-order', 'attached-identity']) ? 'attached' : 'uploaded',
                'attached_order_id' => $key === 'old-order-link' ? $order->id : null,
                'attached_cart_key' => $key === 'attached-identity' ? 'child-identity:synthetic-identity' : null]);
        }
        Storage::disk('local')->put('orders/photos/retained.png', 'synthetic permanent original');
        $this->artisan('photo-uploads:cleanup')->assertSuccessful();
        foreach (['attached-order', 'attached-identity', 'old-order-link'] as $key) {
            Storage::disk('local')->assertExists('temporary-uploads/child-photos/'.$key.'.png');
            Storage::disk('local')->assertExists('temporary-uploads/child-photos/'.$key.'-prepared.jpg');
        }
        Storage::disk('local')->assertExists('orders/photos/retained.png');
        Storage::disk('local')->assertMissing('temporary-uploads/child-photos/unattached.png');
    }

    public function test_missing_files_return_not_found_instead_of_claiming_they_were_restored(): void
    {
        Storage::fake('local');
        $attachment = $this->order()->attachments()->create(['disk' => 'local', 'path' => 'missing.pdf',
            'original_name' => 'missing.pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.orders.attachments.download', $attachment))->assertNotFound();
    }

    public function test_scheduler_keeps_only_safe_temporary_media_cleanup(): void
    {
        Artisan::call('schedule:list');
        $schedule = Artisan::output();
        $this->assertStringNotContainsString('order-attachments:cleanup', $schedule);
        $this->assertStringContainsString('photo-uploads:cleanup', $schedule);
        $this->assertStringContainsString('order-thumbnails:cleanup', $schedule);
        $this->assertStringContainsString('media-library:cleanup-incomplete-uploads', $schedule);
    }
}
