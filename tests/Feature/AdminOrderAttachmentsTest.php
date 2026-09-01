<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\User;
use App\Services\Orders\OrderAttachmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminOrderAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_upload_private_pdf_and_image_with_thirty_day_default_expiry(): void
    {
        Storage::fake('local');
        $order = $this->productOrder();

        $response = $this->actingAs($this->admin)->post(route('admin.orders.attachments.store', $order), [
            'attachments' => [
                UploadedFile::fake()->create('final-design.pdf', 500, 'application/pdf'),
                UploadedFile::fake()->image('reference.jpg', 800, 800),
            ],
            'note' => 'نسخة الطباعة',
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();

        $attachments = $order->attachments()->oldest()->get();
        $this->assertCount(2, $attachments);

        foreach ($attachments as $attachment) {
            $this->assertSame(30, $attachment->validity_days);
            $this->assertTrue($attachment->expires_at->between(now()->addDays(29)->addHours(23), now()->addDays(30)->addMinute()));
            $this->assertSame($this->admin->id, $attachment->uploaded_by_user_id);
            $this->assertSame('نسخة الطباعة', $attachment->note);
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_active_attachment_is_private_and_expired_attachment_is_gone(): void
    {
        Storage::fake('local');
        $order = $this->productOrder();
        $attachment = $this->attachment($order, now()->addDays(30));

        $this->get(route('admin.orders.attachments.show', $attachment))->assertRedirect(route('login'));

        $this->actingAs($this->admin)
            ->get(route('admin.orders.attachments.show', $attachment))
            ->assertOk()
            ->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');

        $attachment->update(['expires_at' => now()->subSecond()]);

        $this->actingAs($this->admin)
            ->get(route('admin.orders.attachments.show', $attachment))
            ->assertGone();
    }

    public function test_cleanup_command_permanently_deletes_expired_files_and_records_only(): void
    {
        Storage::fake('local');
        $order = $this->productOrder();
        $expired = $this->attachment($order, now()->subDay(), 'expired.pdf');
        $active = $this->attachment($order, now()->addDay(), 'active.pdf');

        $result = app(OrderAttachmentService::class)->cleanupExpired();

        $this->assertSame(['expired' => 1, 'deleted_files' => 1], $result);
        $this->assertDatabaseMissing('order_attachments', ['id' => $expired->id]);
        $this->assertDatabaseHas('order_attachments', ['id' => $active->id]);
        Storage::disk('local')->assertMissing($expired->path);
        Storage::disk('local')->assertExists($active->path);
    }

    public function test_product_only_order_page_shows_attachment_controls_and_rejects_unsafe_files(): void
    {
        Storage::fake('local');
        $order = $this->productOrder();

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('مرفقات الطلب')
            ->assertSee('data-order-attachments', false)
            ->assertSee('رفع المرفقات الآن')
            ->assertSee('data-order-attachment-form', false)
            ->assertSee('تُحذف بعد 30 يومًا');

        $this->actingAs($this->admin)
            ->post(route('admin.orders.attachments.store', $order), [
                'attachments' => [UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream')],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('order_attachments', 0);
    }

    public function test_attachment_expiry_is_displayed_in_cairo_time(): void
    {
        Storage::fake('local');
        config(['display.timezone' => 'Africa/Cairo']);
        $order = $this->productOrder();
        $this->attachment($order, CarbonImmutable::parse('2026-08-31 12:00:00', 'UTC'));

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('31/08/2026 03:00 PM')
            ->assertDontSee('31/08/2026 12:00 PM');
    }

    public function test_attachment_size_limit_is_fifty_megabytes_per_file(): void
    {
        Storage::fake('local');
        $order = $this->productOrder();

        $this->actingAs($this->admin)
            ->post(route('admin.orders.attachments.store', $order), [
                'attachments' => [UploadedFile::fake()->create('large-preview.pdf', 51_200, 'application/pdf')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('order_attachments', 1);

        $this->actingAs($this->admin)
            ->post(route('admin.orders.attachments.store', $order), [
                'attachments' => [UploadedFile::fake()->create('too-large.pdf', 51_201, 'application/pdf')],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('order_attachments', 1);
    }

    public function test_admin_can_manually_delete_attachment_and_physical_file(): void
    {
        Storage::fake('local');
        $order = $this->productOrder();
        $attachment = $this->attachment($order, now()->addDays(30));

        $this->actingAs($this->admin)
            ->delete(route('admin.orders.attachments.destroy', $attachment))
            ->assertRedirect();

        $this->assertDatabaseMissing('order_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($attachment->path);
    }

    private function productOrder(): Order
    {
        return Order::query()->create([
            'order_number' => 'HK-ATTACH-'.uniqid(),
            'checkout_group_key' => 'CHK-ATTACH-'.uniqid(),
            'story_id' => null,
            'parent_name' => 'ولي الأمر',
            'child_name' => null,
            'child_age' => null,
            'child_gender' => null,
            'status' => 'new',
            'delivery_details' => [
                'phone' => '201501188884',
                'delivery_fee' => 0,
            ],
        ]);
    }

    private function attachment(Order $order, $expiresAt, string $name = 'document.pdf'): OrderAttachment
    {
        $path = "order-attachments/{$order->id}/{$name}";
        Storage::disk('local')->put($path, 'private order attachment');

        return $order->attachments()->create([
            'uploaded_by_user_id' => $this->admin->id,
            'disk' => 'local',
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 24,
            'validity_days' => 30,
            'expires_at' => $expiresAt,
        ]);
    }
}
