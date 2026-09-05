<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Support\AppDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminOrderAdminNotesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_append_a_permanent_note_visible_across_the_checkout_group(): void
    {
        $admin = User::factory()->create([
            'name' => 'مسؤول هيروكد',
            'role' => 'admin',
        ]);
        [$first, $second] = $this->ordersInSameCheckout();

        $this->actingAs($admin)
            ->post(route('admin.orders.notes.store', $first), [
                'body' => "تم التواصل مع العميل.\nطلب تعديل الاسم على الغلاف.",
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $note = OrderAdminNote::query()->sole();
        $this->assertSame('CHECKOUT-NOTES', $note->checkout_group_key);
        $this->assertSame($first->id, $note->representative_order_id);
        $this->assertSame($admin->id, $note->author_user_id);
        $this->assertSame('مسؤول هيروكد', $note->author_name);
        $this->assertSame("تم التواصل مع العميل.\nطلب تعديل الاسم على الغلاف.", $note->body);

        foreach ([$first, $second] as $order) {
            $this->actingAs($admin)
                ->get(route('admin.orders.show', $order))
                ->assertOk()
                ->assertSee('ملاحظات فريق العمل')
                ->assertSee('مسؤول هيروكد')
                ->assertSee('تم التواصل مع العميل.')
                ->assertSee('طلب تعديل الاسم على الغلاف.')
                ->assertSee(AppDateTime::format($note->created_at, 'd/m/Y h:i A'));
        }

        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $admin->id,
            'action' => 'order.note_added',
            'subject_type' => Order::class,
            'subject_id' => $first->id,
        ]);
    }

    public function test_product_only_checkout_page_displays_the_shared_notes_log(): void
    {
        $admin = User::factory()->create(['name' => 'مشرف المنتجات', 'role' => 'admin']);
        $product = Product::query()->create([
            'name_ar' => 'ملصق مدرسي',
            'slug' => 'notes-product-'.uniqid(),
            'price_cents' => 19_500,
            'is_active' => true,
        ]);
        $order = Order::query()->create([
            'order_number' => 'HK-NOTE-PRODUCT',
            'checkout_group_key' => 'CHECKOUT-PRODUCT-NOTES',
            'parent_name' => 'ولي أمر المنتج',
            'story_id' => null,
            'child_name' => 'سلمى',
            'child_age' => 8,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => [
                'checkout_group' => 'CHECKOUT-PRODUCT-NOTES',
                'phone' => '201000000001',
            ],
            'status' => 'new',
        ]);
        $order->items()->create([
            'item_type' => 'product',
            'product_id' => $product->id,
            'title' => $product->name_ar,
            'unit_price_cents' => 19_500,
            'quantity' => 1,
            'total_price_cents' => 19_500,
        ]);

        $this->actingAs($admin)->post(route('admin.orders.notes.store', $order), [
            'body' => 'تم استلام صور الملصق من العميل.',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('ملاحظات فريق العمل')
            ->assertSee('data-order-admin-notes', false)
            ->assertSee('مشرف المنتجات')
            ->assertSee('تم استلام صور الملصق من العميل.');
    }

    public function test_note_can_include_a_private_attachment_with_the_existing_retention_rules(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin']);
        [$order] = $this->ordersInSameCheckout();

        $this->actingAs($admin)
            ->post(route('admin.orders.notes.store', $order), [
                'body' => 'راجع ملف المقاسات المرفق.',
                'attachment' => UploadedFile::fake()->create('sizes.pdf', 700, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $note = OrderAdminNote::with('attachment')->sole();
        $this->assertNotNull($note->attachment);
        $this->assertSame('sizes.pdf', $note->attachment->original_name);
        $this->assertSame(30, $note->attachment->validity_days);
        Storage::disk('local')->assertExists($note->attachment->path);

        $this->actingAs($admin)
            ->get(route('admin.orders.groups.show', $order))
            ->assertOk()
            ->assertSee('راجع ملف المقاسات المرفق.')
            ->assertSee('sizes.pdf')
            ->assertSee('فتح')
            ->assertSee('تحميل');
    }

    public function test_edit_permission_controls_note_text_and_attachment_replacement(): void
    {
        Storage::fake('local');
        $author = User::factory()->create(['name' => 'كاتب الملاحظة', 'role' => 'admin']);
        $editor = User::factory()->create(['name' => 'معدل الملاحظة', 'role' => 'admin']);
        $withoutPermission = User::factory()->create(['role' => 'admin']);
        $withoutPermission->permissions()->sync(
            Permission::query()->whereIn('key', ['orders.view', 'orders.update'])->pluck('id'),
        );
        [$order] = $this->ordersInSameCheckout();

        $this->actingAs($author)->post(route('admin.orders.notes.store', $order), [
            'body' => 'النص قبل التعديل',
            'attachment' => UploadedFile::fake()->create('old.pdf', 100, 'application/pdf'),
        ]);
        $note = OrderAdminNote::with('attachment')->sole();
        $oldPath = $note->attachment->path;

        $this->actingAs($withoutPermission)
            ->put(route('admin.orders.notes.update', [$order, $note]), ['body' => 'غير مسموح'])
            ->assertForbidden();

        $this->actingAs($editor)
            ->put(route('admin.orders.notes.update', [$order, $note]), [
                'body' => 'النص بعد التعديل',
                'attachment' => UploadedFile::fake()->image('new.jpg'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $note->refresh()->load('attachment');
        $this->assertSame('النص بعد التعديل', $note->body);
        $this->assertSame($author->id, $note->author_user_id);
        $this->assertSame($editor->id, $note->last_edited_by_user_id);
        $this->assertSame('new.jpg', $note->attachment->original_name);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($note->attachment->path);

        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $editor->id,
            'action' => 'order.note_updated',
            'subject_id' => $order->id,
        ]);
    }

    public function test_delete_permission_soft_deletes_note_and_removes_its_attachment(): void
    {
        Storage::fake('local');
        $author = User::factory()->create(['role' => 'admin']);
        $deleter = User::factory()->create(['role' => 'admin']);
        $withoutPermission = User::factory()->create(['role' => 'admin']);
        $withoutPermission->permissions()->sync(
            Permission::query()->whereIn('key', ['orders.view', 'orders.update'])->pluck('id'),
        );
        [$order] = $this->ordersInSameCheckout();

        $this->actingAs($author)->post(route('admin.orders.notes.store', $order), [
            'body' => 'ملاحظة قابلة للحذف بصلاحية',
            'attachment' => UploadedFile::fake()->create('delete-me.pdf', 100, 'application/pdf'),
        ]);
        $note = OrderAdminNote::with('attachment')->sole();
        $attachmentId = $note->attachment->id;
        $attachmentPath = $note->attachment->path;

        $this->actingAs($withoutPermission)
            ->delete(route('admin.orders.notes.destroy', [$order, $note]))
            ->assertForbidden();

        $this->actingAs($deleter)
            ->delete(route('admin.orders.notes.destroy', [$order, $note]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSoftDeleted('order_admin_notes', [
            'id' => $note->id,
            'deleted_by_user_id' => $deleter->id,
        ]);
        $this->assertDatabaseMissing('order_attachments', ['id' => $attachmentId]);
        Storage::disk('local')->assertMissing($attachmentPath);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $deleter->id,
            'action' => 'order.note_deleted',
            'subject_id' => $order->id,
        ]);
    }

    public function test_note_management_rejects_a_note_from_another_checkout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$firstOrder] = $this->ordersInSameCheckout();
        $otherOrder = Order::query()->create([
            'order_number' => 'HK-NOTE-OTHER',
            'checkout_group_key' => 'CHECKOUT-NOTES-OTHER',
            'parent_name' => 'ولي أمر آخر',
            'status' => 'new',
            'delivery_details' => ['phone' => '201000000002'],
        ]);

        $this->actingAs($admin)->post(route('admin.orders.notes.store', $firstOrder), [
            'body' => 'ملاحظة الطلب الأول',
        ]);
        $note = OrderAdminNote::query()->sole();

        $this->actingAs($admin)
            ->put(route('admin.orders.notes.update', [$otherOrder, $note]), ['body' => 'محاولة نقل'])
            ->assertNotFound();
        $this->actingAs($admin)
            ->delete(route('admin.orders.notes.destroy', [$otherOrder, $note]))
            ->assertNotFound();

        $this->assertSame('ملاحظة الطلب الأول', $note->fresh()->body);
        $this->assertNull($note->fresh()->deleted_at);
    }

    public function test_note_creation_requires_update_permission_and_valid_body(): void
    {
        $viewOnlyAdmin = User::factory()->create(['role' => 'admin']);
        $viewOnlyAdmin->permissions()->sync(
            Permission::query()->where('key', 'orders.view')->pluck('id'),
        );
        [$order] = $this->ordersInSameCheckout();

        $this->actingAs($viewOnlyAdmin)
            ->post(route('admin.orders.notes.store', $order), ['body' => 'غير مسموح'])
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.notes.store', $order), ['body' => ''])
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('order_admin_notes', 0);
    }

    private function ordersInSameCheckout(): array
    {
        $story = Story::query()->create([
            'title' => 'قصة اختبار الملاحظات',
            'slug' => 'order-notes-story-'.uniqid(),
            'language' => 'ar',
            'gender' => 'both',
            'price' => 349,
            'active' => true,
        ]);

        return collect(['HK-NOTE-1', 'HK-NOTE-2'])
            ->map(fn (string $number, int $index) => Order::query()->create([
                'order_number' => $number,
                'checkout_group_key' => 'CHECKOUT-NOTES',
                'parent_name' => 'ولي الأمر',
                'story_id' => $story->id,
                'child_name' => $index === 0 ? 'ليلى' : 'عمر',
                'child_age' => 7,
                'child_gender' => $index === 0 ? 'girl' : 'boy',
                'language' => 'ar',
                'delivery_details' => [
                    'checkout_group' => 'CHECKOUT-NOTES',
                    'phone' => '201000000000',
                    'country' => 'مصر',
                    'governorate' => 'القاهرة',
                ],
                'status' => 'new',
            ]))
            ->each(fn (Order $order) => $order->items()->create([
                'item_type' => 'story',
                'story_id' => $story->id,
                'title' => $story->title,
                'unit_price_cents' => 34_900,
                'quantity' => 1,
                'total_price_cents' => 34_900,
            ]))
            ->all();
    }
}
