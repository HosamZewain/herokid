<?php

namespace Tests\Feature;

use App\Models\AdminActivityLog;
use App\Models\CustomerStoryView;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCustomersTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_registered_customers_with_contact_and_address_details(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create([
            'name' => 'Customer One',
            'email' => 'customer@example.test',
            'phone' => '201000000000',
            'last_seen_at' => now(),
        ]);
        $story = $this->story('moon-quest', 'مغامرة القمر');

        Order::create([
            'order_number' => 'HK-2026-CUST01',
            'user_id' => $customer->id,
            'parent_name' => 'Customer One',
            'story_id' => $story->id,
            'child_name' => 'رينا',
            'child_age' => 7,
            'child_gender' => 'girl',
            'language' => 'ar',
            'interests' => 'الفضاء والرسم',
            'delivery_details' => $this->deliveryDetails('201000000000', 'customer-session'),
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        CustomerStoryView::create([
            'user_id' => $customer->id,
            'story_id' => $story->id,
            'session_id' => 'customer-session',
            'viewed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Customers')
            ->assertSee('Customer One')
            ->assertSee('customer@example.test')
            ->assertSee('201000000000')
            ->assertSee('Nasr City');
    }

    public function test_admin_can_view_registered_customer_story_views_child_details_and_orders(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create([
            'name' => 'Customer Two',
            'email' => 'two@example.test',
            'phone' => '201111111111',
            'last_seen_at' => now(),
        ]);
        $story = $this->story('sea-secret', 'سر البحر');
        $order = Order::create([
            'order_number' => 'HK-2026-CUST02',
            'user_id' => $customer->id,
            'parent_name' => 'Customer Two',
            'story_id' => $story->id,
            'child_name' => 'سليم',
            'child_age' => 8,
            'child_gender' => 'boy',
            'language' => 'ar',
            'interests' => 'البحر والقوارب',
            'delivery_details' => $this->deliveryDetails('201111111111', 'registered-session'),
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        CustomerStoryView::create([
            'user_id' => $customer->id,
            'story_id' => $story->id,
            'session_id' => 'registered-session',
            'viewed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.show', 'user-'.$customer->id))
            ->assertOk()
            ->assertSee('Customer Two')
            ->assertSee('two@example.test')
            ->assertSee('سر البحر')
            ->assertSee('سليم')
            ->assertSee('8 سنة، ولد')
            ->assertSee('البحر والقوارب')
            ->assertSee($order->order_number);
    }

    public function test_admin_can_list_and_view_guest_customers_from_orders(): void
    {
        $admin = $this->admin();
        $story = $this->story('forest-key', 'مفتاح الغابة');

        $order = Order::create([
            'order_number' => 'HK-2026-GUEST1',
            'user_id' => null,
            'parent_name' => 'Guest Parent',
            'story_id' => $story->id,
            'child_name' => 'ليلى',
            'child_age' => 5,
            'child_gender' => 'girl',
            'language' => 'ar',
            'interests' => 'الغابة والألوان',
            'delivery_details' => $this->deliveryDetails('201222222222', 'guest-session'),
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        CustomerStoryView::create([
            'story_id' => $story->id,
            'session_id' => 'guest-session',
            'viewed_at' => now(),
        ]);

        $guestKey = 'guest-'.sha1('201222222222');

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertSee('Guest Parent')
            ->assertSee('201222222222')
            ->assertSee('طلب بدون حساب');

        $this->actingAs($admin)
            ->get(route('admin.customers.show', $guestKey))
            ->assertOk()
            ->assertSee('Guest Parent')
            ->assertSee('مفتاح الغابة')
            ->assertSee('ليلى')
            ->assertSee($order->order_number);
    }

    public function test_admin_can_edit_registered_customer_and_reset_password(): void
    {
        $admin = $this->admin();
        $customer = User::factory()->create([
            'name' => 'Old Customer',
            'email' => 'old@example.test',
            'phone' => '201333333333',
            'role' => 'customer',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers.edit', 'user-'.$customer->id))
            ->assertOk()
            ->assertSee('تعديل العميل')
            ->assertSee('Old Customer');

        $this->actingAs($admin)
            ->put(route('admin.customers.update', 'user-'.$customer->id), [
                'name' => 'Updated Customer',
                'email' => 'updated@example.test',
                'phone' => '201444444444',
                'password' => 'new-password-123',
                'password_confirmation' => 'new-password-123',
            ])
            ->assertRedirect(route('admin.customers.show', 'user-'.$customer->id))
            ->assertSessionHas('customer_account_message');

        $customer->refresh();

        $this->assertSame('Updated Customer', $customer->name);
        $this->assertSame('updated@example.test', $customer->email);
        $this->assertSame('201444444444', $customer->phone);
        $this->assertTrue(Hash::check('new-password-123', $customer->password));
    }

    public function test_admin_can_convert_guest_customer_to_registered_client_with_password(): void
    {
        $admin = $this->admin();
        $story = $this->story('guest-conversion-story', 'قصة التحويل');
        $order = Order::create([
            'order_number' => 'HK-2026-CONVERT',
            'user_id' => null,
            'parent_name' => 'Guest Parent',
            'story_id' => $story->id,
            'child_name' => 'ليلى',
            'child_age' => 5,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => $this->deliveryDetails('201555555555', 'convert-session'),
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        CustomerStoryView::create([
            'story_id' => $story->id,
            'session_id' => 'convert-session',
            'viewed_at' => now(),
        ]);

        $guestKey = 'guest-'.sha1('201555555555');

        $this->actingAs($admin)
            ->get(route('admin.customers.edit', $guestKey))
            ->assertOk()
            ->assertSee('إنشاء الحساب وربط الطلبات')
            ->assertSee('Guest Parent');

        $this->actingAs($admin)
            ->put(route('admin.customers.update', $guestKey), [
                'name' => 'Converted Parent',
                'email' => 'converted@example.test',
                'phone' => '201555555555',
                'password' => 'client-password-123',
                'password_confirmation' => 'client-password-123',
            ])
            ->assertRedirect()
            ->assertSessionHas('customer_account_message')
            ->assertSessionHas('customer_account_whatsapp_url');

        $user = User::where('phone', '201555555555')->firstOrFail();
        $order->refresh();

        $this->assertSame('Converted Parent', $user->name);
        $this->assertSame('converted@example.test', $user->email);
        $this->assertSame('customer', $user->role);
        $this->assertTrue(Hash::check('client-password-123', $user->password));
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame('Converted Parent', $order->parent_name);
        $this->assertDatabaseHas('customer_story_views', [
            'session_id' => 'convert-session',
            'user_id' => $user->id,
        ]);
    }

    public function test_authorized_admin_can_export_previous_customers_as_deduplicated_csv(): void
    {
        $admin = $this->admin();
        $story = $this->story('customer-export', 'قصة التصدير');
        $registered = User::factory()->create([
            'name' => 'Registered Account Name',
            'email' => 'registered-export@example.test',
            'phone' => '01012345678',
            'role' => 'customer',
        ]);
        User::factory()->create([
            'name' => 'Never Ordered',
            'email' => 'never-ordered@example.test',
            'phone' => '01099999999',
            'role' => 'customer',
        ]);

        Order::create([
            'order_number' => 'HK-2026-EXPORT-OLD',
            'user_id' => $registered->id,
            'parent_name' => 'الاسم القديم',
            'story_id' => $story->id,
            'delivery_details' => $this->deliveryDetails('+201012345678', 'export-old'),
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        Order::create([
            'order_number' => 'HK-2026-EXPORT-LATEST',
            'user_id' => $registered->id,
            'parent_name' => 'أحدث اسم للعميل',
            'story_id' => $story->id,
            'delivery_details' => $this->deliveryDetails('٠١٠١٢٣٤٥٦٧٨', 'export-latest'),
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $deletedOrder = Order::create([
            'order_number' => 'HK-2026-EXPORT-DELETED',
            'parent_name' => 'عميل طلب محذوف',
            'story_id' => $story->id,
            'delivery_details' => $this->deliveryDetails('01123456789', 'export-deleted'),
            'uploaded_photos' => [],
            'status' => 'cancelled',
        ]);
        $deletedOrder->delete();

        $response = $this->actingAs($admin)->get(route('admin.customers.export'));

        $response->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('cache-control', 'must-revalidate, no-cache, no-store, private');

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('الاسم,"رقم الهاتف"', $csv);
        $this->assertStringContainsString('"أحدث اسم للعميل",01012345678', $csv);
        $this->assertSame(1, substr_count($csv, '01012345678'));
        $this->assertStringContainsString('"عميل طلب محذوف",01123456789', $csv);
        $this->assertStringNotContainsString('الاسم القديم', $csv);
        $this->assertStringNotContainsString('Never Ordered', $csv);
        $this->assertStringNotContainsString('never-ordered@example.test', $csv);
        $this->assertStringNotContainsString('Street 1', $csv);
        $this->assertDatabaseHas('admin_activity_logs', [
            'user_id' => $admin->id,
            'action' => 'customers.exported',
        ]);
        $activity = AdminActivityLog::where('action', 'customers.exported')->firstOrFail();
        $this->assertSame(2, $activity->properties['row_count']);
    }

    public function test_customer_export_requires_its_dedicated_permission_and_button_is_hidden_without_it(): void
    {
        $admin = $this->admin();
        $exportPermission = Permission::where('key', 'customers.export')->firstOrFail();
        $admin->permissions()->detach($exportPermission);
        $admin->unsetRelation('permissions');

        $this->actingAs($admin)
            ->get(route('admin.customers.export'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertDontSee('تصدير العملاء السابقين CSV');
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
        ]);
    }

    private function story(string $slug, string $title): Story
    {
        return Story::create([
            'title' => $title,
            'slug' => $slug,
            'language' => 'ar',
            'lesson_value' => 'الشجاعة',
            'price' => 149,
            'active' => true,
        ]);
    }

    private function deliveryDetails(string $phone, string $sessionId): array
    {
        return [
            'phone' => $phone,
            'country' => 'Egypt',
            'governorate' => 'القاهرة',
            'city' => 'Nasr City',
            'street' => 'Street 1',
            'address_details' => 'Building 2',
            'checkout_session_id' => $sessionId,
        ];
    }
}
