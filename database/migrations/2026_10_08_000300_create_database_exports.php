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
        Schema::create('database_exports', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued')->index();
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
        $definition = AdminPermissionRegistry::metadata('database_exports.manage');
        DB::table('permissions')->updateOrInsert(['key' => 'database_exports.manage'], [
            'group_key' => $definition['group_key'], 'name_ar' => $definition['name_ar'], 'name_en' => $definition['name_en'],
            'description_ar' => $definition['description_ar'], 'description_en' => $definition['description_en'],
            'sort_order' => $definition['sort_order'], 'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $id = DB::table('permissions')->where('key', 'database_exports.manage')->value('id');
        $managers = DB::table('users')->join('permission_user', 'permission_user.user_id', '=', 'users.id')
            ->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('users.role', 'admin')->where('users.is_active', true)
            ->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->pluck('users.id');
        foreach ($managers as $userId) {
            DB::table('permission_user')->insertOrIgnore(['permission_id' => $id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('database_exports');
        DB::table('permissions')->where('key', 'database_exports.manage')->delete();
    }
};
