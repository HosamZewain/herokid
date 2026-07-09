<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $definition = AdminPermissionRegistry::metadata('analytics.view');
        if (! $definition) {
            return;
        }

        DB::table('permissions')->updateOrInsert(
            ['key' => 'analytics.view'],
            [
                'group_key' => $definition['group_key'],
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'] ?? null,
                'description_en' => $definition['description_en'] ?? null,
                'sort_order' => $definition['sort_order'] ?? 999,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', 'analytics.view')->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $userId) use ($permissionId): void {
                DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('key', 'analytics.view')->value('id');

        if ($permissionId && Schema::hasTable('permission_user')) {
            DB::table('permission_user')->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('key', 'analytics.view')->delete();
    }
};
