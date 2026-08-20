<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = ['settings.mobile.view', 'settings.mobile.manage'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach (self::KEYS as $key) {
            $definition = AdminPermissionRegistry::metadata($key);
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'group_key' => $definition['group_key'],
                'name_ar' => $definition['name_ar'],
                'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'],
                'description_en' => $definition['description_en'],
                'sort_order' => $definition['sort_order'],
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }
        $permissionIds = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id');
        DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id')
            ->each(fn (int $userId) => $permissionIds->each(fn (int $permissionId) => DB::table('permission_user')->insertOrIgnore([
                'permission_id' => $permissionId,
                'user_id' => $userId,
                'created_at' => now(),
                'updated_at' => now(),
            ])));
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        $ids = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id');
        if (Schema::hasTable('permission_user')) {
            DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('key', self::KEYS)->delete();
    }
};
