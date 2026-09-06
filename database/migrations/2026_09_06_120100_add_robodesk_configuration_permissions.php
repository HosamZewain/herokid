<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['robodesk.configure', 'robodesk.manage_credentials'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $key) {
            $definition = AdminPermissionRegistry::metadata($key);

            if (! $definition) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'group_key' => $definition['group_key'],
                    'name_ar' => $definition['name_ar'],
                    'name_en' => $definition['name_en'],
                    'description_ar' => $definition['description_ar'] ?? null,
                    'description_en' => $definition['description_en'] ?? null,
                    'sort_order' => $definition['sort_order'] ?? 999,
                    'is_system' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $managerPermissionId = DB::table('permissions')
            ->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->value('id');

        if (! $managerPermissionId) {
            return;
        }

        $managerIds = DB::table('users')
            ->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->where('users.role', 'admin')
            ->where('users.is_active', true)
            ->where('permission_user.permission_id', $managerPermissionId)
            ->pluck('users.id');

        foreach (self::PERMISSIONS as $key) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');

            if (! $permissionId) {
                continue;
            }

            $managerIds->each(fn (int $userId) => DB::table('permission_user')->insertOrIgnore([
                'permission_id' => $permissionId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::PERMISSIONS as $key) {
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');

            if ($permissionId && Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }

            DB::table('permissions')->where('key', $key)->delete();
        }
    }
};
