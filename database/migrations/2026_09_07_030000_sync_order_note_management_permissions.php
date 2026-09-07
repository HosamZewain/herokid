<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'orders.notes.edit',
        'orders.notes.delete',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach (self::PERMISSIONS as $key) {
            $definition = AdminPermissionRegistry::metadata($key);
            if (! $definition) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'group_key' => $definition['group_key'],
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'] ?? null,
                'description_en' => $definition['description_en'] ?? null,
                'sort_order' => $definition['sort_order'] ?? 999,
                'is_system' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ]);
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', self::PERMISSIONS)
            ->pluck('id');
        $managerPermissionId = DB::table('permissions')
            ->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->value('id');

        if ($permissionIds->count() !== count(self::PERMISSIONS) || ! $managerPermissionId) {
            return;
        }

        $managerIds = DB::table('users')
            ->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->where('users.role', 'admin')
            ->where('users.is_active', true)
            ->where('permission_user.permission_id', $managerPermissionId)
            ->pluck('users.id');

        foreach ($managerIds as $managerId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $managerId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // These permission keys predate this repair migration. Keep all existing
        // assignments on rollback rather than revoking manually granted access.
    }
};
