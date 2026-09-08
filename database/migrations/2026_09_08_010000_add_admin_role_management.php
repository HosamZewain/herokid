<?php

use App\Support\AdminPermissionRegistry;
use App\Support\AdminPermissionSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admin_roles', 'is_active')) {
            Schema::table('admin_roles', function (Blueprint $table) {
                $table->boolean('is_active')->default(true)->after('is_system');
            });
        }

        app(AdminPermissionSyncer::class)->sync();

        $roleManagerPermissionId = DB::table('permissions')
            ->where('key', 'admin_users.roles.manage')
            ->value('id');
        $permissionManagerId = DB::table('permissions')
            ->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->value('id');

        if (! $roleManagerPermissionId || ! $permissionManagerId) {
            return;
        }

        $managerIds = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where(function ($query) use ($permissionManagerId): void {
                $query->whereExists(function ($direct) use ($permissionManagerId): void {
                    $direct->selectRaw('1')
                        ->from('permission_user')
                        ->whereColumn('permission_user.user_id', 'users.id')
                        ->where('permission_user.permission_id', $permissionManagerId);
                })->orWhereExists(function ($viaRole) use ($permissionManagerId): void {
                    $viaRole->selectRaw('1')
                        ->from('admin_role_user')
                        ->join('admin_roles', 'admin_roles.id', '=', 'admin_role_user.admin_role_id')
                        ->join('admin_role_permission', 'admin_role_permission.admin_role_id', '=', 'admin_roles.id')
                        ->whereColumn('admin_role_user.user_id', 'users.id')
                        ->where('admin_roles.is_active', true)
                        ->where('admin_role_permission.permission_id', $permissionManagerId);
                });
            })
            ->pluck('id');

        $now = now();

        foreach ($managerIds as $userId) {
            DB::table('permission_user')->insertOrIgnore([
                'user_id' => $userId,
                'permission_id' => $roleManagerPermissionId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('key', 'admin_users.roles.manage')
            ->value('id');

        if ($permissionId) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            DB::table('admin_role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        if (Schema::hasColumn('admin_roles', 'is_active')) {
            Schema::table('admin_roles', function (Blueprint $table) {
                $table->dropColumn('is_active');
            });
        }
    }
};
