<?php

namespace Tests\Feature;

use App\Models\BookletPreview;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Services\BookletPreviews\BookletPreviewManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Tests\TestCase;

class BookletPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_pdf_creates_private_stable_reader_link_and_immutable_versions(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions([
            'orders.view',
            'orders.preview.upload',
            'booklet_previews.view',
            'booklet_previews.create',
            'booklet_previews.update',
        ]);
        $order = $this->order($this->story(), 'HK-PREVIEW-001');

        $this->actingAs($admin)
            ->post(route('admin.orders.booklet-preview.store', $order), [
                'pdf_file' => $this->validPdfUpload('first.pdf', 2),
                'note' => 'الإصدار الأول',
            ])
            ->assertRedirect();

        $preview = BookletPreview::with('currentVersion')->firstOrFail();
        $stableUrl = $preview->publicUrl();
        $firstPath = $preview->currentVersion->file_path;

        $this->assertSame('order', $preview->source_type);
        $this->assertSame($order->id, $preview->order_id);
        $this->assertSame(2, $preview->currentVersion->page_count);
        $this->assertSame('preview_uploaded', $order->refresh()->status);
        Storage::disk('local')->assertExists($firstPath);
        $this->assertStringStartsWith('booklet-previews/', $firstPath);
        $this->assertDatabaseHas('admin_activity_logs', ['action' => 'order.booklet_preview_uploaded']);
        $this->assertDatabaseHas('order_status_logs', ['order_id' => $order->id, 'status' => 'preview_uploaded']);

        $this->actingAs($admin)
            ->post(route('admin.orders.booklet-preview.store', $order), [
                'pdf_file' => $this->validPdfUpload('corrected.pdf', 3),
                'note' => 'تصحيح الصفحة الثانية',
            ])
            ->assertRedirect();

        $preview->refresh()->load('currentVersion');
        $this->assertSame($stableUrl, $preview->publicUrl());
        $this->assertSame(2, $preview->versions()->count());
        $this->assertSame(2, $preview->currentVersion->version_number);
        $this->assertSame(3, $preview->currentVersion->page_count);
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('local')->assertExists($preview->currentVersion->file_path);
    }

    public function test_customer_reader_requires_capability_link_then_streams_inline_with_private_headers(): void
    {
        Storage::fake('local');
        $preview = app(BookletPreviewManager::class)->create([
            'source_type' => 'standalone',
            'title' => 'معاينة عامة آمنة',
            'reading_direction' => 'rtl',
        ], $this->validPdfUpload('reader.pdf', 2), null);

        $this->get(route('booklet-previews.document', $preview))
            ->assertForbidden();

        $reader = $this->get($preview->publicUrl());
        $reader->assertOk()
            ->assertSee('data-booklet-reader', false)
            ->assertSee('data-reading-direction="rtl"', false)
            ->assertSee('[data-reader-loading][hidden]', false)
            ->assertSee('h-[100dvh]', false)
            ->assertSee('--reader-fit-width', false)
            ->assertSee('data-side-next-page', false)
            ->assertSee('data-side-previous-page', false)
            ->assertSee('/images/icons/chevron-right.svg', false)
            ->assertSee('noindex,nofollow,noarchive', false)
            ->assertDontSee($preview->currentVersion->file_path)
            ->assertDontSee('data-download', false)
            ->assertDontSee('data-print', false)
            ->assertDontSee('connect.facebook.net', false)
            ->assertDontSee('googletagmanager.com', false);

        $scenesReader = $this->get($preview->publicScenesUrl());
        $scenesReader->assertOk()
            ->assertSee('data-scene-reader', false)
            ->assertSee('data-reading-direction="rtl"', false)
            ->assertSee('data-page-count="2"', false)
            ->assertSee('عرض التقليب')
            ->assertSee($preview->publicUrl(), false)
            ->assertDontSee($preview->currentVersion->file_path)
            ->assertDontSee('data-download', false)
            ->assertDontSee('data-print', false);

        $this->assertStringContainsString('no-store', (string) $scenesReader->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $scenesReader->headers->get('X-Robots-Tag'));

        $this->assertStringContainsString('no-store', (string) $reader->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $reader->headers->get('X-Robots-Tag'));
        $this->assertSame('no-referrer', $reader->headers->get('Referrer-Policy'));
        $this->assertSame('DENY', $reader->headers->get('X-Frame-Options'));
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $reader->headers->get('Content-Security-Policy'));

        $document = $this->get(route('booklet-previews.document', $preview));
        $document->assertOk();
        $this->assertSame('application/pdf', $document->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $document->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $document->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $document->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('noindex', (string) $document->headers->get('X-Robots-Tag'));

        $range = $this->withHeader('Range', 'bytes=0-31')
            ->get(route('booklet-previews.document', $preview));
        $range->assertStatus(206);
        $this->assertStringStartsWith('bytes 0-31/', (string) $range->headers->get('Content-Range'));
    }

    public function test_revoked_deleted_preview_and_deleted_order_return_gone(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions(['booklet_previews.revoke', 'booklet_previews.delete']);
        $manager = app(BookletPreviewManager::class);
        $preview = $manager->create([
            'source_type' => 'standalone',
            'title' => 'رابط قابل للإيقاف',
            'reading_direction' => 'rtl',
        ], $this->validPdfUpload(), $admin);
        $url = $preview->publicUrl();

        $this->actingAs($admin)->post(route('admin.booklet-previews.revoke', $preview), [
            'reason' => 'تم إرسال نسخة أحدث',
        ])->assertRedirect();
        Auth::logout();
        $this->get($url)->assertGone();

        $manager->reenable($preview->fresh(), $admin);
        $this->get($url)->assertOk();
        $this->actingAs($admin)->delete(route('admin.booklet-previews.destroy', $preview), [
            'reason' => 'لم تعد المعاينة مطلوبة',
        ])->assertRedirect(route('admin.booklet-previews.index'));
        Auth::logout();
        $this->get($url)->assertGone();

        $story = $this->story('order-gone-story');
        $order = $this->order($story, 'HK-PREVIEW-GONE');
        $orderPreview = $manager->createOrReplaceForOrder($order, $this->validPdfUpload('order.pdf'), null, $admin);
        $orderUrl = $orderPreview->publicUrl();
        $order->delete();
        $this->get($orderUrl)->assertGone();
    }

    public function test_story_preview_button_requires_explicit_active_publication(): void
    {
        Storage::fake('local');
        $story = $this->story('public-preview-story');
        $manager = app(BookletPreviewManager::class);
        $preview = $manager->create([
            'source_type' => 'story',
            'story_id' => $story->id,
            'title' => 'معاينة قصة منشورة',
            'reading_direction' => 'rtl',
        ], $this->validPdfUpload(), null);

        $this->get(route('stories.show', $story->slug))->assertOk()->assertDontSee('معاينة القصة');

        $manager->publish($preview, true, null);
        $this->get(route('stories.show', $story->slug))
            ->assertOk()
            ->assertSee('معاينة القصة')
            ->assertSee($preview->publicUrl(), false);

        $manager->revoke($preview->fresh(), 'إيقاف مؤقت', null);
        $this->get(route('stories.show', $story->slug))->assertOk()->assertDontSee('معاينة القصة');
    }

    public function test_order_upload_is_isolated_between_stories_in_same_checkout(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions([
            'orders.preview.upload',
            'booklet_previews.create',
        ]);
        $first = $this->order($this->story('first-checkout-story'), 'HK-GROUP-001', 'CHK-SHARED');
        $second = $this->order($this->story('second-checkout-story'), 'HK-GROUP-002', 'CHK-SHARED');

        $this->actingAs($admin)->post(route('admin.orders.booklet-preview.store', $first), [
            'pdf_file' => $this->validPdfUpload(),
        ])->assertRedirect();

        $this->assertNotNull($first->refresh()->bookletPreview);
        $this->assertNull($second->refresh()->bookletPreview);
        $this->assertSame('new', $second->status);
    }

    public function test_booklet_permissions_gate_menu_create_and_order_upload(): void
    {
        Storage::fake('local');
        $viewer = $this->adminWithPermissions(['booklet_previews.view']);

        $this->actingAs($viewer)
            ->get(route('admin.booklet-previews.index'))
            ->assertOk()
            ->assertSee('معاينات الكتب')
            ->assertDontSee('+ إضافة معاينة');
        $this->actingAs($viewer)->get(route('admin.booklet-previews.create'))->assertForbidden();

        $uploaderWithoutBookletPermission = $this->adminWithPermissions(['orders.preview.upload']);
        $order = $this->order($this->story('permission-story'), 'HK-PERM-PREVIEW');
        $this->actingAs($uploaderWithoutBookletPermission)
            ->post(route('admin.orders.booklet-preview.store', $order), [
                'pdf_file' => $this->validPdfUpload(),
            ])
            ->assertForbidden();
    }

    public function test_malformed_pdf_is_rejected_without_creating_records(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions(['booklet_previews.create']);
        $path = tempnam(sys_get_temp_dir(), 'bad-herokid-pdf-');
        file_put_contents($path, '%PDF-this-is-not-a-real-document');

        $this->actingAs($admin)
            ->from(route('admin.booklet-previews.create'))
            ->post(route('admin.booklet-previews.store'), [
                'source_type' => 'standalone',
                'title' => 'ملف تالف',
                'reading_direction' => 'rtl',
                'pdf_file' => new UploadedFile($path, 'broken.pdf', 'application/pdf', null, true),
            ])
            ->assertRedirect(route('admin.booklet-previews.create'))
            ->assertSessionHasErrors('pdf_file');

        $this->assertDatabaseCount('booklet_previews', 0);
        $this->assertDatabaseCount('booklet_preview_versions', 0);
    }

    public function test_encrypted_and_over_limit_pdfs_are_rejected(): void
    {
        Storage::fake('local');
        $manager = app(BookletPreviewManager::class);

        try {
            $manager->create([
                'source_type' => 'standalone',
                'title' => 'ملف مشفر',
                'reading_direction' => 'rtl',
            ], $this->encryptedPdfUpload(), null);
            $this->fail('Encrypted PDF should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pdf_file', $exception->errors());
        }

        config()->set('booklet_previews.max_pages', 1);
        try {
            $manager->create([
                'source_type' => 'standalone',
                'title' => 'صفحات أكثر من الحد',
                'reading_direction' => 'rtl',
            ], $this->validPdfUpload('too-many-pages.pdf', 2), null);
            $this->fail('Over-limit PDF should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('pdf_file', $exception->errors());
        }

        $this->assertDatabaseCount('booklet_previews', 0);
    }

    public function test_original_pdf_download_is_admin_only_and_permission_gated(): void
    {
        Storage::fake('local');
        $preview = app(BookletPreviewManager::class)->create([
            'source_type' => 'standalone',
            'title' => 'مصدر خاص',
            'reading_direction' => 'rtl',
        ], $this->validPdfUpload('private-source.pdf'), null);
        $viewer = $this->adminWithPermissions(['booklet_previews.view']);

        $this->actingAs($viewer)
            ->get(route('admin.booklet-previews.versions.download', [$preview, $preview->currentVersion]))
            ->assertForbidden();

        $downloader = $this->adminWithPermissions(['booklet_previews.download_source']);
        $response = $this->actingAs($downloader)
            ->get(route('admin.booklet-previews.versions.download', [$preview, $preview->currentVersion]));
        $response->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('private-source.pdf', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_preview_approval_rejects_a_different_authenticated_customer(): void
    {
        $owner = User::factory()->create(['role' => 'customer']);
        $otherCustomer = User::factory()->create(['role' => 'customer']);
        $order = $this->order($this->story('approval-owner-story'), 'HK-OWNER-001');
        $order->update(['user_id' => $owner->id, 'status' => 'preview_uploaded']);

        $this->actingAs($otherCustomer)
            ->post(route('orders.approve-preview', $order))
            ->assertForbidden();

        $this->assertSame('preview_uploaded', $order->refresh()->status);
    }

    private function adminWithPermissions(array $permissionKeys): User
    {
        $admin = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $admin->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id')->all());

        return $admin->refresh();
    }

    private function story(string $slug = 'booklet-preview-story'): Story
    {
        return Story::create([
            'title' => 'قصة المعاينة '.$slug,
            'slug' => $slug,
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);
    }

    private function order(Story $story, string $number, ?string $checkoutGroup = null): Order
    {
        return Order::create([
            'order_number' => $number,
            'checkout_group_key' => $checkoutGroup,
            'story_id' => $story->id,
            'parent_name' => 'ولي أمر',
            'child_name' => 'مريم',
            'child_age' => 6,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000', 'checkout_group' => $checkoutGroup],
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }

    private function validPdfUpload(string $name = 'preview.pdf', int $pages = 1): UploadedFile
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        for ($page = 1; $page <= $pages; $page++) {
            if ($page > 1) {
                $pdf->AddPage();
            }
            $pdf->WriteHTML('<h1>HeroKid Preview '.$page.'</h1><p>Booklet test page.</p>');
        }

        $path = tempnam(sys_get_temp_dir(), 'herokid-booklet-');
        file_put_contents($path, $pdf->Output('', 'S'));

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function encryptedPdfUpload(): UploadedFile
    {
        $pdf = new Mpdf(['tempDir' => sys_get_temp_dir()]);
        $pdf->SetProtection([], 'customer-password', 'owner-password', 128);
        $pdf->WriteHTML('<h1>Protected HeroKid Preview</h1>');
        $path = tempnam(sys_get_temp_dir(), 'herokid-booklet-protected-');
        file_put_contents($path, $pdf->Output('', 'S'));

        return new UploadedFile($path, 'protected.pdf', 'application/pdf', null, true);
    }
}
