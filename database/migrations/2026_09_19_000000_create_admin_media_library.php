<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'media_library.view',
        'media_library.upload',
    ];

    public function up(): void
    {
        Schema::create('admin_media_files', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('disk', 40)->default('local');
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('extension', 16);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_by_name');
            $table->timestamps();

            $table->index(['mime_type', 'created_at']);
            $table->index(['uploaded_by', 'created_at']);
        });

        Schema::create('admin_media_upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('original_name');
            $table->string('extension', 16);
            $table->string('declared_mime', 150)->nullable();
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('next_chunk_index')->default(0);
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->string('status', 24)->default('uploading');
            $table->string('temp_directory')->unique();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['uploaded_by', 'status']);
        });

        $this->syncPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->whereIn('permission_id', $permissionIds)->delete();
            }
            if (Schema::hasTable('admin_role_permission')) {
                DB::table('admin_role_permission')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('admin_media_upload_sessions');
        Schema::dropIfExists('admin_media_files');
    }

    private function syncPermissions(): void
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
                    'created_at' => now(),
                    'updated_at' => now(),
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

        $permissionIds = DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id');

        if (Schema::hasTable('admin_role_permission')) {
            $managerRoleIds = DB::table('admin_role_permission')
                ->where('permission_id', $managerPermissionId)
                ->pluck('admin_role_id');

            foreach ($managerRoleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
                    DB::table('admin_role_permission')->insertOrIgnore([
                        'admin_role_id' => $roleId,
                        'permission_id' => $permissionId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        foreach ($managerIds as $userId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
                    'permission_id' => $permissionId,
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
