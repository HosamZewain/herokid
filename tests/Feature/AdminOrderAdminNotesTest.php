<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderAdminNote;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Story;
use App\Models\User;
use App\Services\Orders\OrderAdminNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
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
                ->assertSee($note->created_at->format('d/m/Y h:i A'));
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

    public function test_notes_are_append_only_and_have_no_edit_or_delete_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$order] = $this->ordersInSameCheckout();

        $this->actingAs($admin)->post(route('admin.orders.notes.store', $order), [
            'body' => 'الملاحظة الأولى',
        ]);
        $this->actingAs($admin)->post(route('admin.orders.notes.store', $order), [
            'body' => 'الملاحظة الثانية',
        ]);

        $this->assertSame(
            ['الملاحظة الثانية', 'الملاحظة الأولى'],
            app(OrderAdminNoteService::class)
                ->notesFor($order)
                ->pluck('body')
                ->all(),
        );
        $this->assertFalse(Route::has('admin.orders.notes.update'));
        $this->assertFalse(Route::has('admin.orders.notes.destroy'));

        $this->expectException(LogicException::class);
        OrderAdminNote::query()->firstOrFail()->update(['body' => 'نص معدل']);
    }

    public function test_note_model_prevents_deletion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$order] = $this->ordersInSameCheckout();

        $this->actingAs($admin)->post(route('admin.orders.notes.store', $order), [
            'body' => 'ملاحظة دائمة',
        ]);

        $this->expectException(LogicException::class);
        OrderAdminNote::query()->firstOrFail()->delete();
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
