<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Permission;
use App\Models\Story;
use App\Models\User;
use App\Support\AdminPermissionRegistry;
use App\Support\AdminPermissionSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_user_cannot_access_admin_routes(): void
    {
        $customer = User::factory()->create(['role' => 'customer']);

        $this->actingAs($customer)
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }

    public function test_admin_without_required_permission_receives_403_and_sidebar_hides_modules(): void
    {
        $admin = $this->adminWithPermissions(['orders.view']);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('الطلبات')
            ->assertDontSee('القصص')
            ->assertDontSee('إعدادات الموقع');

        $this->actingAs($admin)
            ->get(route('admin.stories.index'))
            ->assertForbidden();
    }

    public function test_admin_layout_exposes_mobile_sidebar_controls_without_mobile_content_offset(): void
    {
        $admin = $this->adminWithPermissions(['dashboard.view']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->assertSee('data-admin-sidebar', false)
            ->assertSee('data-admin-sidebar-toggle', false)
            ->assertSee('data-admin-sidebar-close', false)
            ->assertSee('data-admin-sidebar-overlay', false)
            ->assertSee('translate-x-full', false)
            ->assertSee('lg:translate-x-0', false)
            ->assertSee('lg:mr-64', false)
            ->assertDontSee('class="flex-1 flex flex-col min-w-0 mr-64"', false);
    }

    public function test_admin_sidebar_groups_pages_by_business_function_in_a_clear_order(): void
    {
        $admin = $this->adminWithPermissions(AdminPermissionRegistry::keys());

        $this->actingAs($admin)
            ->get(route('admin.dashboard.index'))
            ->assertOk()
            ->assertSeeInOrder([
                'الرئيسية',
                'لوحة القيادة',
                'التقارير',
                'تحليلات الموقع',
                'تقرير المبيعات',
                'تقرير مشاركة الهويات',
                'سلات الزوار',
                'المالية',
                'المصروفات',
                'الطلبات والإنتاج',
                'الطلبات',
                'معاينات الكتب',
                'هويات الأطفال',
                'الكتالوج والعملاء',
                'القصص',
                'المتجر',
                'العملاء',
                'التصنيفات',
                'إدارة المحتوى',
                'الإعدادات',
                'الإدارة والأمان',
            ]);
    }

    public function test_admin_sidebar_does_not_render_empty_sections_for_limited_staff(): void
    {
        $admin = $this->adminWithPermissions(['orders.view']);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('الطلبات والإنتاج')
            ->assertDontSee('التقارير')
            ->assertDontSee('المالية')
            ->assertDontSee('الكتالوج والعملاء')
            ->assertDontSee('الإدارة والأمان');
    }

    public function test_order_sensitive_actions_require_separate_permissions(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions(['orders.view']);
        $order = $this->orderWithPhoto();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertDontSee('صور الطفل المرفقة')
            ->assertDontSee('copy-production-prompt', false)
            ->assertDontSee('story-production-prompt', false)
            ->assertDontSee('تحديث حالة الطلب')
            ->assertDontSee('رفع تصميم للعميل');

        $this->actingAs($admin)
            ->patch(route('admin.orders.update', $order), ['status' => 'under_review'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('admin.orders.upload-preview', $order), [])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.orders.photo', [$order, 0]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.orders.production-prompt.regenerate', $order))
            ->assertForbidden();
    }

    public function test_order_photo_and_prompt_permissions_reveal_sensitive_sections(): void
    {
        Storage::fake('local');
        $admin = $this->adminWithPermissions([
            'orders.view',
            'orders.photos.view',
            'orders.production_prompt.manage',
        ]);
        $order = $this->orderWithPhoto();

        $this->actingAs($admin)
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('صور الطفل المرفقة')
            ->assertSee('Story Production Prompt');

        $this->actingAs($admin)
            ->get(route('admin.orders.photo', [$order, 0]))
            ->assertOk();
    }

    public function test_story_editor_cannot_delete_without_delete_permission(): void
    {
        $admin = $this->adminWithPermissions(['stories.view', 'stories.create', 'stories.update']);
        $story = Story::create([
            'title' => 'قصة محدودة',
            'slug' => 'limited-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 100,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.stories.index'))
            ->assertOk()
            ->assertSee('إضافة قصة جديدة')
            ->assertDontSee('title="حذف"', false);

        $this->actingAs($admin)
            ->delete(route('admin.stories.destroy', $story))
            ->assertForbidden();
    }

    public function test_product_manager_cannot_access_site_settings(): void
    {
        $admin = $this->adminWithPermissions(['store.products.view', 'store.products.create', 'store.products.update']);

        $this->actingAs($admin)
            ->get(route('admin.products.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.settings.index'))
            ->assertForbidden();
    }

    public function test_content_editor_cannot_access_orders_without_permission(): void
    {
        $admin = $this->adminWithPermissions(['content.faqs.view', 'content.faqs.create', 'content.faqs.update']);

        $this->actingAs($admin)
            ->get(route('admin.faqs.index'))
            ->assertOk();

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }

    public function test_new_admin_user_receives_only_selected_permissions(): void
    {
        $owner = $this->adminWithPermissions(AdminPermissionRegistry::keys());

        $this->actingAs($owner)
            ->post(route('admin.users.store'), [
                'name' => 'Order Staff',
                'email' => 'order-staff@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_active' => '1',
                'permissions' => ['orders.view'],
            ])
            ->assertRedirect(route('admin.users.index'));

        $staff = User::where('email', 'order-staff@example.test')->firstOrFail();

        $this->assertTrue($staff->hasPermission('orders.view'));
        $this->assertFalse($staff->hasPermission('settings.site.update'));
        $this->assertSame(['orders.view'], $staff->permissions()->pluck('key')->all());
    }

    public function test_permission_sync_grants_existing_active_admins_but_not_customers(): void
    {
        $admin = $this->adminWithPermissions([]);
        $customer = User::factory()->create(['role' => 'customer']);

        app(AdminPermissionSyncer::class)->sync(grantExistingAdmins: true);

        $this->assertTrue($admin->refresh()->hasPermission('orders.view'));
        $this->assertSame(0, $customer->permissions()->count());
    }

    public function test_staff_cannot_assign_permission_they_do_not_hold(): void
    {
        $manager = $this->adminWithPermissions([
            'admin_users.view',
            'admin_users.create',
            'admin_users.permissions.manage',
            'orders.view',
        ]);

        $this->actingAs($manager)
            ->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'Escalated Staff',
                'email' => 'escalated@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_active' => '1',
                'permissions' => ['settings.site.update'],
            ])
            ->assertRedirect(route('admin.users.create'))
            ->assertSessionHasErrors('permissions');
    }

    public function test_user_without_permission_manager_cannot_edit_another_users_permissions(): void
    {
        $editor = $this->adminWithPermissions(['admin_users.update']);
        $target = $this->adminWithPermissions(['orders.view']);

        $this->actingAs($editor)
            ->put(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'permissions' => ['orders.view', 'orders.update'],
            ])
            ->assertForbidden();
    }

    public function test_staff_can_update_own_password_without_user_management_permission(): void
    {
        $staff = $this->adminWithPermissions(['orders.view']);

        $this->actingAs($staff)
            ->put(route('admin.users.update', $staff), [
                'name' => $staff->name,
                'email' => $staff->email,
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ])
            ->assertRedirect(route('admin.users.edit', $staff));

        $this->assertTrue(Hash::check('new-password123', $staff->refresh()->password));
    }

    public function test_user_cannot_delete_or_deactivate_self(): void
    {
        $admin = $this->adminWithPermissions(AdminPermissionRegistry::keys());

        $this->actingAs($admin)
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $admin))
            ->put(route('admin.users.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'is_active' => '0',
                'permissions' => AdminPermissionRegistry::keys(),
            ])
            ->assertRedirect(route('admin.users.edit', $admin))
            ->assertSessionHasErrors('is_active');
    }

    public function test_last_active_permission_manager_cannot_be_deleted_disabled_or_stripped(): void
    {
        $manager = $this->adminWithPermissions(AdminPermissionRegistry::keys());

        $this->actingAs($manager)
            ->from(route('admin.users.edit', $manager))
            ->put(route('admin.users.update', $manager), [
                'name' => $manager->name,
                'email' => $manager->email,
                'permissions' => array_values(array_diff(AdminPermissionRegistry::keys(), [AdminPermissionRegistry::LAST_MANAGER_PERMISSION])),
            ])
            ->assertRedirect(route('admin.users.edit', $manager))
            ->assertSessionHasErrors('permissions');

        $deleter = $this->adminWithPermissions(['admin_users.delete']);

        $this->actingAs($manager)
            ->from(route('admin.users.edit', $manager))
            ->put(route('admin.users.update', $manager), [
                'name' => $manager->name,
                'email' => $manager->email,
                'is_active' => '0',
                'permissions' => AdminPermissionRegistry::keys(),
            ])
            ->assertRedirect(route('admin.users.edit', $manager))
            ->assertSessionHasErrors('is_active');

        $this->actingAs($deleter)
            ->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $manager))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('permissions');
    }

    public function test_disabled_admin_cannot_access_admin_routes(): void
    {
        $admin = $this->adminWithPermissions(['orders.view'], active: false);

        $this->actingAs($admin)
            ->get(route('admin.orders.index'))
            ->assertForbidden();
    }

    public function test_limited_admin_without_dashboard_redirects_to_first_allowed_module(): void
    {
        $admin = $this->adminWithPermissions(['orders.view']);

        $this->actingAs($admin)
            ->get(route('admin.home'))
            ->assertRedirect(route('admin.orders.index'));
    }

    public function test_admin_with_no_permissions_gets_friendly_403(): void
    {
        $admin = $this->adminWithPermissions([]);

        $this->actingAs($admin)
            ->get(route('admin.home'))
            ->assertForbidden()
            ->assertSee('ليس لديك صلاحية للوصول إلى هذه الصفحة');
    }

    private function adminWithPermissions(array $permissionKeys, bool $active = true): User
    {
        $admin = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password123',
            'role' => 'admin',
            'is_active' => $active,
        ]);

        $admin->permissions()->sync(
            Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
        );

        return $admin->refresh();
    }

    private function orderWithPhoto(): Order
    {
        $story = Story::create([
            'title' => 'قصة الصور',
            'slug' => 'photo-story',
            'language' => 'ar',
            'gender' => 'both',
            'price' => 100,
            'active' => true,
        ]);

        $photoPath = 'orders/photos/kid.png';
        Storage::disk('local')->put($photoPath, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return Order::create([
            'order_number' => 'HK-PERM-001',
            'story_id' => $story->id,
            'parent_name' => 'Parent',
            'child_name' => 'Rina',
            'child_age' => 5,
            'child_gender' => 'girl',
            'language' => 'ar',
            'delivery_details' => ['phone' => '201000000000'],
            'uploaded_photos' => [$photoPath],
            'status' => 'new',
        ]);
    }
}
