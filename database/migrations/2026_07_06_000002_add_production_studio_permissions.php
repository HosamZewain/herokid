<?php

use App\Support\AdminPermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $keys = [
        'production_studio.view',
        'production_studio.create_from_order',
        'production_studio.manage',
        'production_studio.assign',
        'production_studio.story_edit',
        'production_studio.character_profile_edit',
        'production_studio.scene_edit',
        'production_studio.qa_review',
        'production_studio.archive',
        'production_studio.delete_or_cancel',
        'production_studio.settings',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        foreach ($this->keys as $key) {
            $permission = AdminPermissionRegistry::metadata($key);

            if (! $permission) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(
                ['key' => $key],
                [
                    'group_key' => $permission['group_key'],
                    'name_ar' => $permission['name_ar'],
                    'name_en' => $permission['name_en'],
                    'description_ar' => $permission['description_ar'] ?? null,
                    'description_en' => $permission['description_en'] ?? null,
                    'sort_order' => $permission['sort_order'] ?? 0,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        if (! Schema::hasTable('permission_user') || ! Schema::hasTable('users')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('key', $this->keys)
            ->pluck('id');

        $adminIds = DB::table('users')
            ->where('role', 'admin')
            ->where('is_active', true)
            ->pluck('id');

        foreach ($adminIds as $adminId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('permission_user')->insertOrIgnore([
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
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->whereIn('key', $this->keys)->delete();
    }
};
