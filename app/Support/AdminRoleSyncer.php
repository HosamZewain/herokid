<?php

namespace App\Support;

use App\Models\AdminRole;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class AdminRoleSyncer
{
    public function sync(): void
    {
        DB::transaction(function (): void {
            foreach (AdminRoleRegistry::roles() as $key => $definition) {
                $role = AdminRole::updateOrCreate(
                    ['key' => $key],
                    [
                        'name_ar' => $definition['name_ar'],
                        'name_en' => $definition['name_en'],
                        'description_ar' => $definition['description_ar'] ?? null,
                        'sort_order' => $definition['sort_order'] ?? 0,
                        'is_system' => true,
                    ]
                );

                $permissionIds = Permission::query()
                    ->whereIn('key', AdminRoleRegistry::permissionKeys($key))
                    ->pluck('id')
                    ->all();

                $role->permissions()->sync($permissionIds);
            }
        });
    }
}
