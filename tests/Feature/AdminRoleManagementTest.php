<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Permission;
use App\Models\User;
use App\Support\AdminPermissionRegistry;
use App\Support\AdminRoleSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_management_requires_its_separate_sensitive_permission(): void
    {
        $viewer = $this->adminWithPermissions(['admin_users.view']);
        $manager = $this->adminWithPermissions(['admin_users.roles.manage']);

        $this->actingAs($viewer)->get(route('admin.roles.index'))->assertForbidden();

        $this->actingAs($manager)
            ->get(route('admin.roles.index'))
            ->assertOk()
            ->assertSee('إدارة الأدوار الوظيفية')
            ->assertSee('موظف إنتاج');
    }

    public function test_owner_can_create_a_custom_role_and_assign_it_when_creating_staff(): void
    {
        $owner = $this->adminWithPermissions(AdminPermissionRegistry::keys());

        $this->actingAs($owner)
            ->post(route('admin.roles.store'), [
                'name_ar' => 'مراجع طلبات',
                'name_en' => 'Order Reviewer',
                'description_ar' => 'يراجع الطلبات بدون تعديل.',
                'is_active' => '1',
                'permissions' => ['orders.view', 'customers.view'],
            ])
            ->assertRedirect(route('admin.roles.index'));

        $role = AdminRole::where('name_en', 'Order Reviewer')->firstOrFail();
        $this->assertFalse($role->is_system);

        $this->actingAs($owner)
            ->post(route('admin.users.store'), [
                'name' => 'Reviewer',
                'email' => 'reviewer@example.test',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'is_active' => '1',
                'admin_role' => $role->key,
            ])
            ->assertRedirect(route('admin.users.index'));

        $staff = User::where('email', 'reviewer@example.test')->firstOrFail();
        $this->assertTrue($staff->hasPermission('orders.view'));
        $this->assertTrue($staff->hasPermission('customers.view'));
        $this->assertFalse($staff->hasPermission('orders.update'));
    }

    public function test_updating_or_disabling_a_role_immediately_changes_assigned_users_effective_permissions(): void
    {
        $owner = $this->adminWithPermissions(AdminPermissionRegistry::keys());
        $role = AdminRole::where('key', 'shipping')->with('permissions')->firstOrFail();
        $staff = $this->adminWithPermissions([]);
        $staff->adminRoles()->sync([$role->id]);

        $this->assertTrue($staff->refresh()->hasPermission('bosta.create_shipment'));

        $this->actingAs($owner)
            ->put(route('admin.roles.update', $role), [
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
                'description_ar' => $role->description_ar,
                'is_active' => '1',
                'permissions' => ['orders.view', 'bosta.view'],
            ])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertTrue($staff->refresh()->hasPermission('bosta.view'));
        $this->assertFalse($staff->hasPermission('bosta.create_shipment'));

        $this->actingAs($owner)
            ->put(route('admin.roles.update', $role), [
                'name_ar' => $role->name_ar,
                'name_en' => $role->name_en,
                'description_ar' => $role->description_ar,
                'is_active' => '0',
                'permissions' => ['orders.view', 'bosta.view'],
            ])
            ->assertRedirect(route('admin.roles.index'));

        $this->assertFalse($staff->refresh()->hasPermission('bosta.view'));
    }

    public function test_owner_role_cannot_be_disabled_or_lose_full_permissions(): void
    {
        $owner = $this->adminWithPermissions(AdminPermissionRegistry::keys());
        $ownerRole = AdminRole::where('key', 'owner')->firstOrFail();

        $this->actingAs($owner)
            ->from(route('admin.roles.edit', $ownerRole))
            ->put(route('admin.roles.update', $ownerRole), [
                'name_ar' => $ownerRole->name_ar,
                'name_en' => $ownerRole->name_en,
                'is_active' => '0',
                'permissions' => ['orders.view'],
            ])
            ->assertRedirect(route('admin.roles.edit', $ownerRole))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($ownerRole->refresh()->is_active);
        $this->assertEqualsCanonicalizing(
            AdminPermissionRegistry::keys(),
            $ownerRole->permissions()->pluck('key')->all(),
        );
    }

    public function test_role_can_be_duplicated_but_system_or_assigned_roles_cannot_be_deleted(): void
    {
        $owner = $this->adminWithPermissions(AdminPermissionRegistry::keys());
        $source = AdminRole::where('key', 'production')->firstOrFail();

        $this->actingAs($owner)
            ->post(route('admin.roles.duplicate', $source))
            ->assertRedirect();

        $copy = AdminRole::where('name_en', 'Production Copy')->firstOrFail();
        $this->assertFalse($copy->is_active);
        $this->assertFalse($copy->is_system);
        $this->assertSame($source->permissions()->count(), $copy->permissions()->count());

        $staff = $this->adminWithPermissions([]);
        $staff->adminRoles()->sync([$copy->id]);

        $this->actingAs($owner)
            ->delete(route('admin.roles.destroy', $copy))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('admin_roles', ['id' => $copy->id]);

        $this->actingAs($owner)
            ->delete(route('admin.roles.destroy', $source))
            ->assertSessionHas('error');
        $this->assertDatabaseHas('admin_roles', ['id' => $source->id]);
    }

    public function test_manager_cannot_edit_or_copy_a_role_containing_permissions_they_do_not_have(): void
    {
        $manager = $this->adminWithPermissions(['admin_users.roles.manage', 'orders.view']);
        $ownerRole = AdminRole::where('key', 'owner')->firstOrFail();

        $this->actingAs($manager)
            ->get(route('admin.roles.edit', $ownerRole))
            ->assertForbidden();
        $this->actingAs($manager)
            ->post(route('admin.roles.duplicate', $ownerRole))
            ->assertForbidden();
    }

    public function test_last_permission_manager_cannot_remove_management_permission_from_their_own_role(): void
    {
        $managementRole = AdminRole::create([
            'key' => 'custom_management',
            'name_ar' => 'إدارة الصلاحيات',
            'name_en' => 'Permission Management',
            'is_system' => false,
            'is_active' => true,
            'sort_order' => 500,
        ]);
        $managementRole->permissions()->sync(Permission::whereIn('key', [
            'admin_users.permissions.manage',
            'admin_users.roles.manage',
        ])->pluck('id'));

        $manager = $this->adminWithPermissions([]);
        $manager->adminRoles()->sync([$managementRole->id]);

        $this->actingAs($manager)
            ->from(route('admin.roles.edit', $managementRole))
            ->put(route('admin.roles.update', $managementRole), [
                'name_ar' => $managementRole->name_ar,
                'name_en' => $managementRole->name_en,
                'is_active' => '1',
                'permissions' => ['admin_users.roles.manage'],
            ])
            ->assertRedirect(route('admin.roles.edit', $managementRole))
            ->assertSessionHasErrors('permissions');

        $this->assertTrue($managementRole->refresh()->permissions()->where('key', 'admin_users.permissions.manage')->exists());
    }

    public function test_normal_role_sync_does_not_overwrite_permissions_customized_from_admin(): void
    {
        $production = AdminRole::where('key', 'production')->firstOrFail();
        $production->permissions()->sync(Permission::where('key', 'orders.view')->pluck('id'));

        app(AdminRoleSyncer::class)->sync();

        $this->assertSame(['orders.view'], $production->refresh()->permissions()->pluck('key')->all());

        $owner = AdminRole::where('key', 'owner')->firstOrFail();
        $this->assertEqualsCanonicalizing(
            AdminPermissionRegistry::keys(),
            $owner->permissions()->pluck('key')->all(),
        );
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

        $admin->permissions()->sync(Permission::whereIn('key', $permissionKeys)->pluck('id'));

        return $admin->refresh();
    }
}
