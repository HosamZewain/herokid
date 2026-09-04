<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = ['bosta.view', 'bosta.create_shipment', 'bosta.create_pickup', 'bosta.print_awb'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }
        foreach (self::KEYS as $key) {
            $definition = AdminPermissionRegistry::metadata($key);
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'group_key' => $definition['group_key'], 'name_ar' => $definition['name_ar'], 'name_en' => $definition['name_en'],
                'description_ar' => $definition['description_ar'], 'description_en' => $definition['description_en'],
                'sort_order' => $definition['sort_order'], 'is_system' => true, 'updated_at' => now(), 'created_at' => now(),
            ]);
        }
        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }
        $managerId = DB::table('permissions')->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->value('id');
        if (! $managerId) {
            return;
        }
        $admins = DB::table('users')->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->where('users.role', 'admin')->where('users.is_active', true)->where('permission_user.permission_id', $managerId)->pluck('users.id');
        foreach (DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id') as $permissionId) {
            foreach ($admins as $userId) {
                DB::table('permission_user')->insertOrIgnore(['permission_id' => $permissionId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
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
