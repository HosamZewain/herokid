<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\OrderGroupAssignment;
use App\Models\Permission;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderProductPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class OrderBulkDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('s3_private');
        config(['media.private_disk' => 's3_private']);
    }

    private function order(string $suffix): Order
    {
        return Order::create(['order_number' => 'BULK-'.$suffix, 'checkout_group_key' => 'BULK-'.$suffix, 'parent_name' => 'Synthetic', 'status' => 'new', 'payment_status' => 'unpaid']);
    }

    private function attachment(Order $order, int $page): OrderAttachment
    {
        $path = 'synthetic/'.$order->id.'/'.$page.'.png';
        Storage::disk('s3_private')->put($path, 'page-'.$page);

        return OrderAttachment::create(['order_id' => $order->id, 'disk' => 's3_private', 'path' => $path, 'original_name' => '../same.png', 'mime_type' => 'image/png', 'size' => 10]);
    }

    public function test_thirty_selected_files_download_once_in_selection_order_without_overwriting_duplicates(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order('thirty');
        $files = collect(range(1, 30))->map(fn ($page) => $this->attachment($order, $page));
        $ids = $files->reverse()->pluck('id')->all();
        $before = glob(sys_get_temp_dir().'/herokid-download-*');
        $response = $this->actingAs($admin)->get(route('admin.orders.attachments.download-many', ['representative' => $order, 'attachment_ids' => $ids]))
            ->assertOk()->assertHeader('Content-Type', 'application/zip');
        $bytes = $response->streamedContent();
        $this->assertSame($before, glob(sys_get_temp_dir().'/herokid-download-*'));
        $path = tempnam(sys_get_temp_dir(), 'bulk-test-');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertSame(30, $zip->numFiles);
        for ($index = 0; $index < 30; $index++) {
            $this->assertSame('page-'.(30 - $index), $zip->getFromIndex($index));
            $this->assertStringNotContainsString('..', $zip->getNameIndex($index));
            $this->assertStringStartsWith(sprintf('%03d-', $index + 1), $zip->getNameIndex($index));
        }
        $zip->close();
        unlink($path);
        $this->assertDatabaseCount('order_attachments', 30);
        $files->each(fn ($file) => Storage::disk('s3_private')->assertExists($file->path));
        Http::assertNothingSent();
    }

    public function test_preview_zip_contains_original_and_cross_order_empty_and_duplicate_selections_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $order = $this->order('preview');
        $other = $this->order('other');
        $gallery = app(OrderProductPreviewService::class)->upload($order, [UploadedFile::fake()->image('page.jpg')], null, $admin);
        $preview = $gallery->previews->first();
        $url = route('admin.orders.product-previews.download-many', ['representative' => $order, 'preview_ids' => [$preview->id]]);
        $this->get($url)->assertRedirect(route('login'));
        $response = $this->actingAs($admin)->get($url)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'bulk-test-');
        file_put_contents($path, $response->streamedContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(Storage::disk('s3_private')->get($preview->file_path), $zip->getFromIndex(0));
        $zip->close();
        unlink($path);
        $this->getJson(route('admin.orders.product-previews.download-many', ['representative' => $other, 'preview_ids' => [$preview->id]]))->assertUnprocessable();
        $this->getJson(route('admin.orders.product-previews.download-many', ['representative' => $order]))->assertUnprocessable();
        $this->getJson(route('admin.orders.product-previews.download-many', ['representative' => $order, 'preview_ids' => [$preview->id, $preview->id]]))->assertUnprocessable();
    }

    public function test_download_respects_visibility_transfer_permissions_and_rejects_missing_files_atomically(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $employee = User::factory()->create(['role' => 'admin']);
        $employee->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));
        $employee->unsetRelation('permissions');
        $order = $this->order('mine');
        $other = $this->order('foreign');
        $file = $this->attachment($order, 1);
        $foreign = $this->attachment($other, 2);
        Setting::updateOrCreate(['key' => 'orders_visibility'], ['value' => 'assigned']);
        $assignment = OrderGroupAssignment::create(['checkout_group_key' => $order->checkoutGroupKey(), 'assigned_to_user_id' => $employee->id, 'assigned_by_user_id' => $admin->id, 'assigned_at' => now()]);
        $url = route('admin.orders.attachments.download-many', ['representative' => $order, 'attachment_ids' => [$file->id]]);
        $this->actingAs($employee)->get($url)->assertOk()->streamedContent();
        $this->getJson(route('admin.orders.attachments.download-many', ['representative' => $order, 'attachment_ids' => [$file->id, $foreign->id]]))->assertUnprocessable();
        $assignment->update(['assigned_to_user_id' => $admin->id]);
        $this->getJson($url)->assertNotFound();
        $this->actingAs($admin);
        $before = glob(sys_get_temp_dir().'/herokid-download-*');
        Storage::disk('s3_private')->delete($file->path);
        $this->getJson($url)->assertUnprocessable()->assertJsonPath('message', 'أحد الملفات المحددة غير متاح. حدّث الصفحة وأعد المحاولة.');
        $this->assertSame($before, glob(sys_get_temp_dir().'/herokid-download-*'));
        $this->assertDatabaseHas('order_attachments', ['id' => $file->id]);
    }
}
