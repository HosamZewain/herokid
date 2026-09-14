<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderPreview;
use App\Models\OrderProductPreviewGallery;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderProductPreviewImageService;
use App\Services\Orders\OrderWhatsAppMessageService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OrderProductPreviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_upload_multiple_private_images_and_product_order_shows_gallery(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00', 'Africa/Cairo')->utc());
        $order = $this->productOrder();

        $this->actingAs($this->admin)
            ->post(route('admin.orders.product-previews.store', $order), [
                'preview_images' => [
                    UploadedFile::fake()->image('front.jpg', 900, 900),
                    UploadedFile::fake()->image('back.png', 900, 900),
                ],
                'preview_note' => 'نسخة العميل الأولى',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $gallery = OrderProductPreviewGallery::with('previews')->firstOrFail();
        $gallery->previews()->update(['created_at' => now()->subMinutes(5)]);
        $gallery->load('previews');
        $uploadedAgo = app_datetime_human($gallery->previews->first()->created_at);
        $this->assertCount(2, $gallery->previews);
        $this->assertSame($order->checkoutGroupKey(), $gallery->checkout_group_key);
        $gallery->previews->each(function ($preview): void {
            Storage::disk('local')->assertExists($preview->file_path);
            $this->assertStringStartsWith('orders/product-previews/', $preview->file_path);
            $this->assertNotNull($preview->checksum);
        });

        $response = $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('معاينة المنتجات للعميل')
            ->assertSee('front.jpg')
            ->assertSee('back.png')
            ->assertSee('data-order-ajax-delete', false)
            ->assertSee('data-order-bulk-delete', false)
            ->assertSee('تحديد كل صور المعاينة');

        $response
            ->assertSee($uploadedAgo)
            ->assertSee('رُفعت '.app_datetime($gallery->previews->first()->created_at, 'd/m/Y h:i A'))
            ->assertDontSee($gallery->previews->first()->file_path);

        $this->assertDatabaseHas('admin_activity_logs', [
            'subject_id' => $order->id,
            'action' => 'order.product_previews_uploaded',
        ]);
    }

    public function test_group_page_still_renders_when_a_preview_owner_child_is_soft_deleted(): void
    {
        $removedChild = $this->productOrder();
        $remainingChild = $this->productOrder('remaining-child');
        $remainingChild->update([
            'checkout_group_key' => $removedChild->checkout_group_key,
        ]);

        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $removedChild), [
            'preview_images' => [UploadedFile::fake()->image('removed-child-preview.jpg', 800, 800)],
        ])->assertRedirect();

        $removedChild->delete();

        $preview = OrderProductPreviewGallery::with('previews.order')->firstOrFail()->previews->firstOrFail();
        $this->assertTrue($preview->order->trashed());

        $this->actingAs($this->admin)
            ->get(route('admin.orders.groups.show', $remainingChild))
            ->assertOk()
            ->assertSee('removed-child-preview.jpg');
    }

    public function test_existing_whatsapp_preview_variable_uses_product_gallery_link(): void
    {
        $order = $this->productOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [UploadedFile::fake()->image('preview.webp', 800, 800)],
        ])->assertRedirect();

        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);
        $variables = app(OrderWhatsAppMessageService::class)->variablesForGroup($group);
        $gallery = OrderProductPreviewGallery::firstOrFail();

        $this->assertSame($gallery->publicUrl(), $variables['preview_url']);
        $this->assertSame($gallery->publicUrl(), $variables['product_preview_url']);
        $this->assertSame($gallery->publicUrl(), $variables['customer_preview_url']);

        $previewMessage = collect(app(OrderWhatsAppMessageService::class)->messagesForGroup($group))
            ->firstWhere('id', 'preview');
        $this->assertStringContainsString($gallery->publicUrl(), $previewMessage['body']);
    }

    public function test_customer_gallery_and_images_are_private_noindex_and_do_not_expose_paths(): void
    {
        $order = $this->productOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [UploadedFile::fake()->image('customer.jpg', 1000, 1000)],
        ])->assertRedirect();

        $gallery = OrderProductPreviewGallery::with('previews')->firstOrFail();
        $preview = $gallery->previews->first();
        $token = $gallery->plainPublicToken();

        $page = $this->get($gallery->publicUrl());
        $page->assertOk()
            ->assertSee('معاينة طلبك من HeroKid')
            ->assertSee('protected-preview', false)
            ->assertSee('noindex,nofollow,noarchive', false)
            ->assertDontSee($preview->file_path)
            ->assertDontSee('<a href="'.route('order-product-previews.image', compact('token', 'preview')), false);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        $imageUrl = route('order-product-previews.image', compact('token', 'preview'));
        $this->withHeader('Sec-Fetch-Dest', 'document')->get($imageUrl)->assertNotFound();

        $image = $this->withHeader('Sec-Fetch-Dest', 'image')->get($imageUrl);
        $image->assertOk();
        $this->assertSame('image/jpeg', $image->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $image->headers->get('Cache-Control'));
        $this->assertSame('same-origin', $image->headers->get('Cross-Origin-Resource-Policy'));
        $this->assertNotSame(Storage::disk('local')->get($preview->file_path), $image->getContent());

        $order->delete();
        $this->get($gallery->publicUrl())->assertGone();
    }

    public function test_existing_preview_is_protected_lazily_and_downscaled_for_customer_access(): void
    {
        $order = $this->productOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [UploadedFile::fake()->image('legacy.jpg', 2400, 1800)],
        ])->assertRedirect();

        $gallery = OrderProductPreviewGallery::with('previews')->firstOrFail();
        $preview = $gallery->previews->first();
        $service = app(OrderProductPreviewImageService::class);
        $protectedPath = $service->protectedPath($preview);
        Storage::disk('local')->delete($protectedPath);

        $this->get(route('order-product-previews.image', [
            'token' => $gallery->plainPublicToken(),
            'preview' => $preview,
        ]))->assertOk();

        Storage::disk('local')->assertExists($protectedPath);
        $image = new \Imagick(Storage::disk('local')->path($protectedPath));
        $this->assertLessThanOrEqual(1400, $image->getImageWidth());
        $this->assertLessThanOrEqual(1400, $image->getImageHeight());
        $image->clear();
    }

    public function test_preview_message_appends_gallery_link_when_saved_template_omits_variable(): void
    {
        $order = $this->productOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [UploadedFile::fake()->image('preview.jpg', 800, 800)],
        ])->assertRedirect();
        Setting::updateOrCreate(['key' => OrderWhatsAppMessageService::SETTING_KEY], ['value' => json_encode([[
            'id' => 'preview',
            'title' => 'إرسال معاينة للعميل',
            'message' => 'راجع المعاينة الخاصة بطلبك {{order_reference}}.',
            'is_active' => true,
            'sort_order' => 20,
        ]], JSON_UNESCAPED_UNICODE)]);

        $group = app(AdminOrderGroupService::class)->findByRepresentative($order->id);
        $message = app(OrderWhatsAppMessageService::class)->messagesForGroup($group)[0];

        $this->assertStringContainsString('رابط المعاينة:', $message['body']);
        $this->assertStringContainsString(OrderProductPreviewGallery::firstOrFail()->publicUrl(), $message['body']);
    }

    public function test_product_preview_upload_rejects_pdf_files(): void
    {
        $order = $this->productOrder();

        $this->actingAs($this->admin)
            ->from(route('admin.orders.groups.show', $order))
            ->post(route('admin.orders.product-previews.store', $order), [
                'preview_images' => [UploadedFile::fake()->create('preview.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect(route('admin.orders.groups.show', $order))
            ->assertSessionHasErrors('preview_images.0');

        $this->assertDatabaseCount('order_product_preview_galleries', 0);
    }

    public function test_admin_can_delete_product_preview_with_ajax_without_a_redirect(): void
    {
        $order = $this->productOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [
                UploadedFile::fake()->image('first.jpg', 800, 800),
                UploadedFile::fake()->image('second.jpg', 800, 800),
            ],
        ])->assertRedirect();

        $gallery = OrderProductPreviewGallery::with('previews')->firstOrFail();
        $preview = $gallery->previews->firstOrFail();

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.orders.product-previews.destroy', [$order, $preview]))
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'تم حذف صورة المعاينة.',
                'deleted_preview_id' => $preview->id,
            ]);

        $this->assertDatabaseMissing('order_previews', ['id' => $preview->id]);
        $this->assertDatabaseCount('order_previews', 1);
        Storage::disk('local')->assertMissing($preview->file_path);
    }

    public function test_admin_can_bulk_delete_selected_product_previews_without_touching_unselected_files(): void
    {
        $order = $this->productOrder();
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [
                UploadedFile::fake()->image('first.jpg', 800, 800),
                UploadedFile::fake()->image('second.jpg', 800, 800),
                UploadedFile::fake()->image('keep.jpg', 800, 800),
            ],
        ])->assertRedirect();

        $previews = OrderProductPreviewGallery::with('previews')->firstOrFail()->previews;
        $selected = $previews->take(2);
        $kept = $previews->last();

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.orders.product-previews.destroy-many', $order), [
                'preview_ids' => $selected->pluck('id')->all(),
            ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'deleted_preview_ids' => $selected->pluck('id')->all(),
            ]);

        $selected->each(function ($preview): void {
            $this->assertDatabaseMissing('order_previews', ['id' => $preview->id]);
            Storage::disk('local')->assertMissing($preview->file_path);
        });
        $this->assertDatabaseHas('order_previews', ['id' => $kept->id]);
        Storage::disk('local')->assertExists($kept->file_path);
    }

    public function test_bulk_preview_delete_rejects_a_preview_from_another_checkout(): void
    {
        $order = $this->productOrder();
        $other = $this->productOrder('2');

        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $order), [
            'preview_images' => [UploadedFile::fake()->image('first.jpg')],
        ]);
        $this->actingAs($this->admin)->post(route('admin.orders.product-previews.store', $other), [
            'preview_images' => [UploadedFile::fake()->image('other.jpg')],
        ]);
        $previews = OrderPreview::query()->orderBy('id')->get();

        $this->actingAs($this->admin)
            ->deleteJson(route('admin.orders.product-previews.destroy-many', $order), [
                'preview_ids' => $previews->pluck('id')->all(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preview_ids');

        $this->assertDatabaseCount('order_previews', 2);
    }

    private function productOrder(string $suffix = '1'): Order
    {
        $order = Order::create([
            'order_number' => 'HK-PRODUCT-PREVIEW-'.$suffix,
            'checkout_group_key' => 'GROUP-PRODUCT-PREVIEW-'.$suffix,
            'parent_name' => 'ولي الأمر',
            'status' => 'new',
            'payment_status' => 'unpaid',
            'delivery_details' => [
                'phone' => '01111822277',
                'delivery_fee' => 50,
                'country' => 'مصر',
                'governorate' => 'القاهرة',
            ],
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'title' => 'ستيكر مخصص',
            'unit_price_cents' => 24_500,
            'quantity' => 1,
            'total_price_cents' => 24_500,
        ]);

        return $order;
    }
}
