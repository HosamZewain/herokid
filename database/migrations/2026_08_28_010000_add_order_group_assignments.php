<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['orders.assign', 'orders.assignment.manage'];

    public function up(): void
    {
        Schema::create('order_group_assignments', function (Blueprint $table): void {
            $table->id();
            $table->string('checkout_group_key')->unique();
            $table->foreignId('assigned_to_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->index(['assigned_to_user_id', 'assigned_at']);
        });

        $definitions = AdminPermissionRegistry::permissions();
        foreach (self::PERMISSIONS as $key) {
            $definition = $definitions[$key];
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

        if (Schema::hasTable('permission_user')) {
            $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            DB::table('users')->where('role', 'admin')->where('is_active', true)->pluck('id')->each(
                fn (int $userId) => $permissionIds->each(fn (int $permissionId) => DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])),
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_user')) {
            $ids = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            DB::table('permission_user')->whereIn('permission_id', $ids)->delete();
        }
        DB::table('permissions')->whereIn('key', self::PERMISSIONS)->delete();
        Schema::dropIfExists('order_group_assignments');
    }
};
