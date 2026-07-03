# Admin Permissions

Hero Kid uses a lightweight custom RBAC layer for admin/staff access. It does not use Spatie or any external permissions package.

## Architecture

- `users.role` still separates account type: `admin` vs `customer`.
- `users.is_active` controls whether an admin account can access the admin panel.
- `permissions` stores the system permission catalog.
- `permission_user` assigns permissions to admin users.
- `User::isAdmin()` returns true only for active admin users.
- `User::hasPermission()`, `hasAnyPermission()`, and `hasAllPermissions()` are the central checks.
- Route middleware `permission:key` enforces access server-side.
- Blade uses Laravel Gate syntax such as `@can('orders.view')`.

## Registry

The source of truth is:

```text
config/admin_permissions.php
```

Do not create arbitrary permission keys from the UI. To add a future module permission:

1. Add the group or permission to `config/admin_permissions.php`.
2. Protect the route with `permission:new.key`.
3. Hide/show UI actions with `@can('new.key')`.
4. Run the sync command.
5. Add tests.

## Sync Command

```bash
php artisan admin-permissions:sync
```

To grant all current system permissions to existing active admins during a legacy deployment or recovery:

```bash
php artisan admin-permissions:sync --grant-existing-admins
```

The migration also inserts the initial catalog and grants all initial permissions to existing active admin users to avoid lockout.

## Staff Users

New staff accounts are created from Admin > إدارة المشرفين.

- New staff keep `role = admin`.
- New staff receive only explicitly selected permissions.
- Active staff must have at least one permission.
- Disabled staff cannot access admin routes.
- The creator can only assign permissions they already hold.

## Sensitive Permissions

Sensitive permissions include:

- `orders.photos.view`
- `orders.production_prompt.manage`
- `settings.site.update`
- `settings.production_prompt.manage`
- `admin_users.permissions.manage`
- `activity_logs.view`

Child photo routes and production prompt actions are protected by dedicated permissions. Sidebar hiding is only a UI convenience; route middleware still returns `403` for direct restricted URLs.

## Last Permission Manager Safeguard

The system must always keep at least one active admin with:

```text
admin_users.permissions.manage
```

The app blocks:

- Removing that permission from the last active manager.
- Disabling the last active manager.
- Deleting the last active manager.

Arabic validation message:

```text
لا يمكن تنفيذ العملية لأن النظام يجب أن يحتفظ بمستخدم إداري نشط واحد على الأقل قادر على إدارة الصلاحيات.
```

## Self Protection

- Admins cannot delete themselves.
- Admins cannot deactivate themselves.
- Staff can update their own name, email, and password without user-management permissions.
- Staff without `admin_users.view` are redirected back to their own edit page after self-update.

## Recovery

If permissions are accidentally misconfigured but shell access is available:

```bash
php artisan admin-permissions:sync --grant-existing-admins
php artisan optimize:clear
```

Then sign in with an active admin account and reduce permissions manually if needed.

## Test Commands

```bash
php artisan test tests/Feature/AdminPermissionsTest.php
php artisan test
```
