<?php

use App\Services\OrderAutomation\WorkflowDefinition;
use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_automations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->json('draft');
            $table->unsignedInteger('draft_version')->default(1);
            $table->unsignedBigInteger('revision_id')->nullable();
            $table->boolean('enabled')->default(false);
            $table->unsignedBigInteger('scan_after_id')->default(0);
            $table->timestamps();
        });
        Schema::create('order_automation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('order_automations');
            $table->json('definition');
            $table->string('checksum', 64);
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('created_at');
        });
        Schema::create('order_automation_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('automation_id')->constrained('order_automations');
            $table->foreignId('revision_id')->constrained('order_automation_revisions');
            $table->foreignId('order_id')->constrained('orders');
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('live_key')->nullable()->unique();
            $table->string('request_key')->unique();
            $table->boolean('dry_run')->default(false);
            $table->boolean('trial')->default(false);
            $table->string('status', 40)->default('queued')->index();
            $table->unsignedInteger('step_index')->default(0);
            $table->longText('context')->nullable();
            $table->json('steps')->nullable();
            $table->unsignedBigInteger('reserved_microusd')->default(0);
            $table->timestamp('reserved_at')->nullable()->index();
            $table->string('lease_token', 36)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_at')->nullable()->index();
            $table->string('error_code', 100)->nullable();
            $table->timestamps();
        });
        // Explicit grants only; existing permission managers may configure, not spend automatically.
        foreach (['view', 'manage', 'activate', 'execute'] as $action) {
            $key = 'order_automation.'.$action;
            $meta = AdminPermissionRegistry::metadata($key);
            DB::table('permissions')->updateOrInsert(['key' => $key], array_merge(
                array_intersect_key($meta, array_flip(['group_key', 'name_ar', 'name_en', 'description_ar', 'description_en', 'sort_order'])),
                ['is_system' => true, 'created_at' => now(), 'updated_at' => now()],
            ));
        }
        $managers = DB::table('permission_user')->join('permissions', 'permissions.id', '=', 'permission_user.permission_id')
            ->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->pluck('permission_user.user_id');
        $roleManagers = DB::table('admin_role_user')->join('admin_roles', 'admin_roles.id', '=', 'admin_role_user.admin_role_id')
            ->join('admin_role_permission', 'admin_role_permission.admin_role_id', '=', 'admin_roles.id')
            ->join('permissions', 'permissions.id', '=', 'admin_role_permission.permission_id')
            ->where('admin_roles.is_active', true)->where('permissions.key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)->pluck('admin_role_user.user_id');
        $ids = DB::table('users')->whereIn('id', $managers->merge($roleManagers)->unique())->where('role', 'admin')->where('is_active', true)->pluck('id');
        foreach (DB::table('permissions')->whereIn('key', ['order_automation.view', 'order_automation.manage'])->pluck('id') as $permissionId) {
            foreach ($ids as $id) {
                DB::table('permission_user')->insertOrIgnore(['user_id' => $id, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $definition = WorkflowDefinition::defaults();
        DB::table('order_automations')->insert(['name' => 'معاينة هوية القصص', 'draft' => json_encode($definition, JSON_UNESCAPED_UNICODE), 'enabled' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (['order_automation_runs', 'order_automation_revisions', 'order_automations'] as $table) {
            Schema::dropIfExists($table);
        }
        DB::table('permissions')->where('key', 'like', 'order_automation.%')->delete();
    }
};
