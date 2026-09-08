<?php

namespace App\Support;

use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminPermissionSyncer
{
    public function sync(bool $grantExistingAdmins = false): void
    {
        DB::transaction(function () use ($grantExistingAdmins): void {
            foreach (AdminPermissionRegistry::permissions() as $key => $definition) {
                Permission::updateOrCreate(
                    ['key' => $key],
                    [
                        'group_key' => $definition['group_key'],
                        'name_ar' => $definition['name_ar'],
                        'name_en' => $definition['name_en'],
                        'description_ar' => $definition['description_ar'] ?? null,
                        'description_en' => $definition['description_en'] ?? null,
                        'sort_order' => $definition['sort_order'] ?? 0,
                        'is_system' => true,
                    ]
                );
            }

            if ($grantExistingAdmins) {
                $permissionIds = Permission::whereIn('key', AdminPermissionRegistry::keys())->pluck('id');

                User::where('role', 'admin')
                    ->where('is_active', true)
                    ->chunkById(100, function ($admins) use ($permissionIds): void {
                        foreach ($admins as $admin) {
                            $admin->permissions()->syncWithoutDetaching($permissionIds);
                        }
                    });
            }
        });

        if (Schema::hasTable('admin_roles')) {
            app(AdminRoleSyncer::class)->sync();
        }
    }
}
