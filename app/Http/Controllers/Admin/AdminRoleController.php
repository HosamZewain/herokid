<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\Permission;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\AdminPermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminRoleController extends Controller
{
    public function index()
    {
        $roles = AdminRole::query()
            ->with('permissions:id,key')
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();

        $assignablePermissions = $this->assignablePermissionKeys();
        $manageableRoleIds = $roles
            ->filter(fn (AdminRole $role): bool => array_diff($role->permissions->pluck('key')->all(), $assignablePermissions) === [])
            ->pluck('id')
            ->all();

        return view('admin.roles.index', compact('roles', 'manageableRoleIds'));
    }

    public function create()
    {
        return view('admin.roles.create', [
            'permissionGroups' => AdminPermissionRegistry::grouped($this->assignablePermissionKeys()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRole($request);
        $permissionKeys = $this->validatedAssignablePermissionKeys($request->input('permissions', []));

        if ($permissionKeys === []) {
            throw ValidationException::withMessages([
                'permissions' => 'يجب اختيار صلاحية واحدة على الأقل للدور.',
            ]);
        }

        $role = DB::transaction(function () use ($validated, $permissionKeys): AdminRole {
            $role = AdminRole::create([
                'key' => 'custom_'.Str::lower(Str::random(12)),
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'description_ar' => $validated['description_ar'] ?? null,
                'sort_order' => (AdminRole::max('sort_order') ?? 0) + 10,
                'is_system' => false,
                'is_active' => (bool) $validated['is_active'],
            ]);

            $role->permissions()->sync($this->permissionIdsForKeys($permissionKeys));

            return $role;
        });

        $this->logChange($request, 'admin_role.created', 'إنشاء دور وظيفي: '.$role->name_ar, $role, [
            'permissions' => $permissionKeys,
        ]);

        return redirect()->route('admin.roles.index')->with('success', 'تم إنشاء الدور الوظيفي بنجاح.');
    }

    public function edit(AdminRole $role)
    {
        $role->load('permissions:id,key');
        $this->ensureRoleAssignable($role);

        return view('admin.roles.edit', [
            'role' => $role,
            'permissionGroups' => AdminPermissionRegistry::grouped($this->assignablePermissionKeys()),
            'selectedPermissions' => $role->permissions->pluck('key')->all(),
            'isOwnerRole' => $role->key === 'owner',
        ]);
    }

    public function update(Request $request, AdminRole $role): RedirectResponse
    {
        $role->load('permissions:id,key');
        $this->ensureRoleAssignable($role);
        $validated = $this->validateRole($request);
        $before = $this->snapshot($role);
        $isOwner = $role->key === 'owner';
        $permissionKeys = $isOwner
            ? AdminPermissionRegistry::keys()
            : $this->validatedAssignablePermissionKeys($request->input('permissions', []));

        if (! $isOwner && $permissionKeys === []) {
            throw ValidationException::withMessages([
                'permissions' => 'يجب اختيار صلاحية واحدة على الأقل للدور.',
            ]);
        }

        if ($isOwner && ! $request->boolean('is_active')) {
            throw ValidationException::withMessages([
                'is_active' => 'لا يمكن تعطيل دور مالك النظام.',
            ]);
        }

        DB::transaction(function () use ($role, $validated, $permissionKeys, $isOwner): void {
            $role->update([
                'name_ar' => $validated['name_ar'],
                'name_en' => $validated['name_en'],
                'description_ar' => $validated['description_ar'] ?? null,
                'is_active' => $isOwner ? true : (bool) $validated['is_active'],
            ]);
            $role->permissions()->sync($this->permissionIdsForKeys($permissionKeys));

            $this->ensurePermissionManagerRemains();
        });

        $role->refresh()->load('permissions:id,key');
        $this->logChange($request, 'admin_role.updated', 'تحديث دور وظيفي: '.$role->name_ar, $role, [
            'before' => $before,
            'after' => $this->snapshot($role),
        ]);

        return redirect()->route('admin.roles.index')->with('success', 'تم تحديث الدور الوظيفي بنجاح.');
    }

    public function duplicate(Request $request, AdminRole $role): RedirectResponse
    {
        $role->load('permissions:id,key');
        $this->ensureRoleAssignable($role);
        $permissionKeys = $this->validatedAssignablePermissionKeys($role->permissions->pluck('key')->all());

        $copy = DB::transaction(function () use ($role, $permissionKeys): AdminRole {
            $copy = AdminRole::create([
                'key' => 'custom_'.Str::lower(Str::random(12)),
                'name_ar' => 'نسخة من '.$role->name_ar,
                'name_en' => $role->name_en.' Copy',
                'description_ar' => $role->description_ar,
                'sort_order' => (AdminRole::max('sort_order') ?? 0) + 10,
                'is_system' => false,
                'is_active' => false,
            ]);
            $copy->permissions()->sync($this->permissionIdsForKeys($permissionKeys));

            return $copy;
        });

        $this->logChange($request, 'admin_role.duplicated', 'نسخ دور وظيفي: '.$role->name_ar, $copy, [
            'source_role_id' => $role->id,
        ]);

        return redirect()->route('admin.roles.edit', $copy)->with('success', 'تم إنشاء نسخة موقوفة من الدور؛ راجعها ثم فعّلها.');
    }

    public function destroy(Request $request, AdminRole $role): RedirectResponse
    {
        $role->load('permissions:id,key');
        $this->ensureRoleAssignable($role);

        if ($role->is_system) {
            return back()->with('error', 'لا يمكن حذف دور نظام أساسي، ويمكن تعطيله بدلًا من ذلك باستثناء مالك النظام.');
        }

        if ($role->users()->exists()) {
            return back()->with('error', 'لا يمكن حذف الدور لأنه مرتبط بمستخدمين. انقل المستخدمين إلى دور آخر أولًا.');
        }

        $roleId = $role->id;
        $roleName = $role->name_ar;
        $role->delete();

        AdminActivityLogger::log(
            action: 'admin_role.deleted',
            description: 'حذف دور وظيفي: '.$roleName,
            properties: ['admin_role_id' => $roleId],
            request: $request,
        );

        return redirect()->route('admin.roles.index')->with('success', 'تم حذف الدور الوظيفي.');
    }

    private function validateRole(Request $request): array
    {
        return $request->validate([
            'name_ar' => ['required', 'string', 'max:120'],
            'name_en' => ['required', 'string', 'max:120'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['required', 'boolean'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(AdminPermissionRegistry::keys())],
        ]);
    }

    private function assignablePermissionKeys(): array
    {
        return auth()->user()->permissionKeys()->all();
    }

    private function validatedAssignablePermissionKeys(array $permissionKeys): array
    {
        $permissionKeys = collect($permissionKeys)
            ->filter(fn ($key): bool => is_string($key))
            ->unique()
            ->values()
            ->all();
        $notAssignable = array_values(array_diff($permissionKeys, $this->assignablePermissionKeys()));

        if ($notAssignable !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'لا يمكنك منح صلاحيات لا تملكها: '.implode(', ', $notAssignable),
            ]);
        }

        return $permissionKeys;
    }

    private function permissionIdsForKeys(array $permissionKeys): array
    {
        return Permission::whereIn('key', $permissionKeys)->pluck('id')->all();
    }

    private function ensureRoleAssignable(AdminRole $role): void
    {
        abort_unless(
            array_diff($role->permissions->pluck('key')->all(), $this->assignablePermissionKeys()) === [],
            403,
        );
    }

    private function ensurePermissionManagerRemains(): void
    {
        $managerExists = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereHas('permissions', fn ($permissions) => $permissions->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION))
                    ->orWhereHas('adminRoles', fn ($roles) => $roles
                        ->where('is_active', true)
                        ->whereHas('permissions', fn ($permissions) => $permissions->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION)));
            })
            ->exists();

        if (! $managerExists) {
            throw ValidationException::withMessages([
                'permissions' => AdminPermissionRegistry::lastManagerError(),
            ]);
        }
    }

    private function snapshot(AdminRole $role): array
    {
        $role->loadMissing('permissions:id,key');

        return [
            'name_ar' => $role->name_ar,
            'name_en' => $role->name_en,
            'description_ar' => $role->description_ar,
            'is_active' => (bool) $role->is_active,
            'permissions' => $role->permissions->pluck('key')->sort()->values()->all(),
        ];
    }

    private function logChange(Request $request, string $action, string $description, AdminRole $role, array $properties = []): void
    {
        AdminActivityLogger::log(
            action: $action,
            description: $description,
            subject: $role,
            properties: array_merge(['admin_role_id' => $role->id], $properties),
            request: $request,
        );
    }
}
