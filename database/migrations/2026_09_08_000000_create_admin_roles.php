<?php

use App\Support\AdminPermissionRegistry;
use App\Support\AdminRoleSyncer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('admin_role_permission', function (Blueprint $table) {
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['admin_role_id', 'permission_id']);
        });

        Schema::create('admin_role_user', function (Blueprint $table) {
            $table->foreignId('admin_role_id')->constrained('admin_roles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['admin_role_id', 'user_id']);
        });

        app(AdminRoleSyncer::class)->sync();

        $ownerRoleId = DB::table('admin_roles')->where('key', 'owner')->value('id');
        $permissionCount = DB::table('permissions')
            ->whereIn('key', AdminPermissionRegistry::keys())
            ->count();

        if ($ownerRoleId && $permissionCount > 0) {
            $now = now();
            $ownerIds = DB::table('users')
                ->join('permission_user', 'permission_user.user_id', '=', 'users.id')
                ->where('users.role', 'admin')
                ->where('users.is_active', true)
                ->whereIn('permission_user.permission_id', DB::table('permissions')
                    ->select('id')
                    ->whereIn('key', AdminPermissionRegistry::keys()))
                ->groupBy('users.id')
                ->havingRaw('COUNT(DISTINCT permission_user.permission_id) >= ?', [$permissionCount])
                ->pluck('users.id');

            foreach ($ownerIds as $userId) {
                DB::table('admin_role_user')->insertOrIgnore([
                    'admin_role_id' => $ownerRoleId,
                    'user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_role_user');
        Schema::dropIfExists('admin_role_permission');
        Schema::dropIfExists('admin_roles');
    }
};
