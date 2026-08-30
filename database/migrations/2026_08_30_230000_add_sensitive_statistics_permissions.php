<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION_KEYS = [
        'dashboard.statistics.view',
        'orders.statistics.view',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach (self::PERMISSION_KEYS as $key) {
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
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('permission_user')) {
            return;
        }

        // Existing permission managers are the current owner-equivalent users.
        // Do not expose financial metrics to every active administrator.
        $managerPermissionId = DB::table('permissions')
            ->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->value('id');

        if (! $managerPermissionId) {
            return;
        }

        $ownerIds = DB::table('permission_user')
            ->where('permission_id', $managerPermissionId)
            ->pluck('user_id');
        $permissionIds = DB::table('permissions')
            ->whereIn('key', self::PERMISSION_KEYS)
            ->pluck('id');

        foreach ($ownerIds as $ownerId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
                    'user_id' => $ownerId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', self::PERMISSION_KEYS)
            ->pluck('id');

        if (Schema::hasTable('permission_user')) {
            DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('key', self::PERMISSION_KEYS)->delete();
    }
};
