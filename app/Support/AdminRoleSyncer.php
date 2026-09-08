<?php

namespace App\Support;

use App\Models\AdminRole;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class AdminRoleSyncer
{
    public function sync(bool $resetSystemRoles = false): void
    {
        DB::transaction(function () use ($resetSystemRoles): void {
            foreach (AdminRoleRegistry::roles() as $key => $definition) {
                $role = AdminRole::firstOrNew(['key' => $key]);
                $isNew = ! $role->exists;

                if ($isNew) {
                    $role->fill([
                        'name_ar' => $definition['name_ar'],
                        'name_en' => $definition['name_en'],
                        'description_ar' => $definition['description_ar'] ?? null,
                        'sort_order' => $definition['sort_order'] ?? 0,
                        'is_system' => true,
                        'is_active' => true,
                    ]);
                }

                $role->save();

                $permissionIds = Permission::query()
                    ->whereIn('key', AdminRoleRegistry::permissionKeys($key))
                    ->pluck('id')
                    ->all();

                if ($isNew || $resetSystemRoles || $key === 'owner') {
                    $role->permissions()->sync($permissionIds);
                }
            }
        });
    }
}
