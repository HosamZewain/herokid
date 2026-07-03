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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('role');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group_key')->index();
            $table->string('name_ar');
            $table->string('name_en');
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permission_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'permission_id']);
        });

        $now = now();
        $permissionIds = [];

        foreach (AdminPermissionRegistry::permissions() as $key => $permission) {
            $id = DB::table('permissions')->insertGetId([
                'key' => $key,
                'group_key' => $permission['group_key'],
                'name_ar' => $permission['name_ar'],
                'name_en' => $permission['name_en'],
                'description_ar' => $permission['description_ar'] ?? null,
                'description_en' => $permission['description_en'] ?? null,
                'sort_order' => $permission['sort_order'] ?? 0,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $permissionIds[] = $id;
        }

        $adminIds = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id');

        foreach ($adminIds as $adminId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insert([
                    'user_id' => $adminId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permissions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
