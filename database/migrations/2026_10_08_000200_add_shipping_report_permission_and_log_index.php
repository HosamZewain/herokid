<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_logs', fn (Blueprint $table) => $table->index(['status_type', 'status', 'order_id', 'created_at'], 'shipping_report_log_idx'));
        $definition = AdminPermissionRegistry::metadata('shipping_reports.view');
        DB::table('permissions')->updateOrInsert(['key' => 'shipping_reports.view'], [
            'group_key' => $definition['group_key'], 'name_ar' => $definition['name_ar'], 'name_en' => $definition['name_en'],
            'description_ar' => $definition['description_ar'], 'description_en' => $definition['description_en'],
            'sort_order' => $definition['sort_order'], 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $permissionId = DB::table('permissions')->where('key', 'shipping_reports.view')->value('id');
        // Only existing active permission managers receive the new sensitive permission automatically.
        $managers = DB::table('users')->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('users.role', 'admin')->where('users.is_active', true)
            ->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->pluck('users.id');
        foreach ($managers as $userId) {
            DB::table('permission_user')->insertOrIgnore(['permission_id' => $permissionId, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('order_status_logs', fn (Blueprint $table) => $table->dropIndex('shipping_report_log_idx'));
        DB::table('permissions')->where('key', 'shipping_reports.view')->delete();
    }
};
