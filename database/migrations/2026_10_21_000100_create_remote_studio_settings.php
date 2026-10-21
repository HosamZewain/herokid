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
        Schema::create('studio_settings_revisions', function (Blueprint $table): void {
            $table->id();
            $table->json('settings');
            $table->json('assets');
            $table->string('checksum', 64);
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500);
            $table->timestamp('created_at');
        });
        Schema::create('studio_settings_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->foreignId('revision_id')->nullable()->constrained('studio_settings_revisions');
        });
        DB::table('studio_settings_state')->insert(['id' => 1]);
        Schema::create('studio_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->string('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
        });
        Schema::create('studio_employee_grants', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            // Not a credential: the existing employee-owned policy token is never disclosed.
            $table->unsignedBigInteger('agent_token_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        Schema::create('studio_revision_assets', function (Blueprint $table): void {
            $table->foreignId('revision_id')->constrained('studio_settings_revisions')->cascadeOnDelete();
            $table->foreignUuid('asset_id')->index()->constrained('studio_assets');
            $table->primary(['revision_id', 'asset_id']);
        });
        Schema::create('studio_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id')->unique();
            $table->unsignedBigInteger('agent_token_id')->nullable();
            $table->unsignedInteger('grant_version');
            $table->string('policy_fingerprint', 64)->nullable();
            $table->string('password_fingerprint', 64);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at');
        });
        Schema::create('studio_cover_generations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('personal_access_token_id');
            $table->uuid('request_id');
            $table->string('request_hash', 64);
            $table->foreignId('revision_id')->constrained('studio_settings_revisions');
            $table->foreignId('order_id')->constrained('orders');
            $table->string('unit_key', 200);
            $table->string('kind', 30);
            $table->string('status', 20)->default('queued');
            $table->string('reference_path')->nullable();
            $table->string('output_path')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->json('output')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
        });
        $managers = DB::table('permission_user')->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->join('users', 'users.id', '=', 'permission_user.user_id')->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->where('users.role', 'admin')->where('users.is_active', true)->pluck('users.id');
        $roleManagers = DB::table('admin_role_user')->join('admin_roles', 'admin_roles.id', '=', 'admin_role_user.admin_role_id')
            ->join('admin_role_permission', 'admin_role_permission.admin_role_id', '=', 'admin_roles.id')
            ->join('permissions', 'permissions.id', '=', 'admin_role_permission.permission_id')
            ->join('users', 'users.id', '=', 'admin_role_user.user_id')->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
            ->where('admin_roles.is_active', true)->where('users.role', 'admin')->where('users.is_active', true)->pluck('users.id');
        $managers = $managers->merge($roleManagers)->unique();
        foreach (['studio.access', 'studio.settings.read', 'studio.settings.manage', 'studio.sessions.manage', 'studio.covers.generate'] as $key) {
            $definition = AdminPermissionRegistry::metadata($key);
            DB::table('permissions')->updateOrInsert(['key' => $key], array_merge(
                array_intersect_key($definition, array_flip(['group_key', 'name_ar', 'name_en', 'description_ar', 'description_en', 'sort_order'])),
                ['is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ));
            // Only existing permission administrators can bootstrap management.
            // No production/cost permission or Agent flag is granted by this migration.
            if ($key === 'studio.covers.generate') {
                continue;
            }
            $permissionId = DB::table('permissions')->where('key', $key)->value('id');
            foreach ($managers as $id) {
                DB::table('permission_user')->insertOrIgnore(['user_id' => $id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['studio_cover_generations', 'studio_sessions', 'studio_employee_grants', 'studio_revision_assets', 'studio_assets', 'studio_settings_state', 'studio_settings_revisions'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('permissions')->whereIn('key', ['studio.access', 'studio.settings.read', 'studio.settings.manage', 'studio.sessions.manage', 'studio.covers.generate'])->delete();
    }
};
