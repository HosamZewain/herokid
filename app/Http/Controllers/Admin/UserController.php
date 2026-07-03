<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\User;
use App\Support\AdminActivityLogger;
use App\Support\AdminPermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index()
    {
        $admins = User::with('permissions')
            ->where('role', 'admin')
            ->latest()
            ->get();

        return view('admin.users.index', compact('admins'));
    }

    public function create()
    {
        return view('admin.users.create', [
            'permissionGroups' => AdminPermissionRegistry::grouped($this->assignablePermissionKeys()),
            'assignablePermissionKeys' => $this->assignablePermissionKeys(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)],
            'is_active' => 'nullable|boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(AdminPermissionRegistry::keys())],
        ]);

        $isActive = $request->boolean('is_active', true);
        $permissionKeys = $this->validatedAssignablePermissionKeys($request->input('permissions', []));

        if ($isActive && $permissionKeys === []) {
            throw ValidationException::withMessages([
                'permissions' => 'يجب اختيار صلاحية واحدة على الأقل أو إنشاء الحساب موقوفاً.',
            ]);
        }

        $admin = DB::transaction(function () use ($validated, $permissionKeys, $isActive): User {
            $admin = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Hash::make($validated['password']),
                'role' => 'admin',
                'is_active' => $isActive,
                'email_verified_at' => now(),
            ]);

            $admin->permissions()->sync($this->permissionIdsForKeys($permissionKeys));

            return $admin;
        });

        AdminActivityLogger::log(
            action: 'admin_user.created',
            description: 'إنشاء حساب مشرف: '.$admin->name,
            subject: $admin,
            properties: [
                'admin_user_id' => $admin->id,
                'is_active' => $admin->is_active,
                'permissions' => $permissionKeys,
            ],
            request: $request,
        );

        if ($permissionKeys !== []) {
            AdminActivityLogger::log(
                action: 'admin_permissions.updated',
                description: 'تعيين صلاحيات مشرف جديد: '.$admin->name,
                subject: $admin,
                properties: [
                    'before' => [],
                    'after' => $permissionKeys,
                    'added' => $permissionKeys,
                    'removed' => [],
                ],
                request: $request,
            );
        }

        return redirect()->route('admin.users.index')->with('success', 'تم إضافة المشرف بنجاح!');
    }

    public function edit(User $user)
    {
        abort_unless($user->role === 'admin', 404);

        if ($user->id !== auth()->id()) {
            abort_unless(auth()->user()->hasAnyPermission(['admin_users.update', 'admin_users.permissions.manage']), 403);
        }

        $user->load('permissions');

        return view('admin.users.edit', [
            'user' => $user,
            'permissionGroups' => AdminPermissionRegistry::grouped($this->assignablePermissionKeys()),
            'assignablePermissionKeys' => $this->assignablePermissionKeys(),
        ]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'admin', 404);

        $isSelf = $user->id === auth()->id();
        $canUpdateOthers = auth()->user()->hasPermission('admin_users.update');
        $canManagePermissions = auth()->user()->hasPermission('admin_users.permissions.manage');

        if (! $isSelf && ! $canUpdateOthers && ! $canManagePermissions) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user)],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'is_active' => 'nullable|boolean',
            'permissions' => 'nullable|array',
            'permissions.*' => ['string', Rule::in(AdminPermissionRegistry::keys())],
        ]);

        $user->load('permissions');
        $beforePermissions = $user->permissions->pluck('key')->sort()->values()->all();
        $beforeActive = (bool) $user->is_active;
        $permissionKeys = $beforePermissions;
        $nextActive = $beforeActive;
        $willChangePermissions = $request->has('permissions');
        $willChangeStatus = $request->has('is_active');

        if (! $isSelf && ! $canUpdateOthers && ($validated['name'] !== $user->name || $validated['email'] !== $user->email || ! empty($validated['password']))) {
            abort(403);
        }

        if (($willChangePermissions || $willChangeStatus) && ! $canManagePermissions) {
            abort(403);
        }

        if ($willChangePermissions) {
            $permissionKeys = $this->validatedAssignablePermissionKeys($request->input('permissions', []));
        }

        if ($willChangeStatus) {
            $nextActive = $request->boolean('is_active');
        }

        if ($isSelf && $willChangeStatus && ! $nextActive) {
            throw ValidationException::withMessages([
                'is_active' => 'لا يمكنك إيقاف حسابك الخاص.',
            ]);
        }

        if ($nextActive && $permissionKeys === []) {
            throw ValidationException::withMessages([
                'permissions' => 'يجب أن يمتلك الحساب النشط صلاحية واحدة على الأقل.',
            ]);
        }

        if ($willChangePermissions || $willChangeStatus) {
            $this->ensureAtLeastOnePermissionManagerRemains(
                target: $user,
                targetActive: $nextActive,
                targetPermissionKeys: $permissionKeys,
            );
        }

        DB::transaction(function () use ($user, $validated, $permissionKeys, $nextActive, $willChangePermissions, $willChangeStatus): void {
            $user->name = $validated['name'];
            $user->email = $validated['email'];

            if (! empty($validated['password'])) {
                $user->password = Hash::make($validated['password']);
            }

            if ($willChangeStatus) {
                $user->is_active = $nextActive;
            }

            $user->save();

            if ($willChangePermissions) {
                $user->permissions()->sync($this->permissionIdsForKeys($permissionKeys));
            }
        });

        $user->refresh()->load('permissions');
        $afterPermissions = $user->permissions->pluck('key')->sort()->values()->all();

        AdminActivityLogger::log(
            action: 'admin_user.updated',
            description: 'تحديث حساب مشرف: '.$user->name,
            subject: $user,
            properties: [
                'admin_user_id' => $user->id,
                'active' => [
                    'old' => $beforeActive,
                    'new' => (bool) $user->is_active,
                ],
                'password_changed' => ! empty($validated['password']),
            ],
            request: $request,
        );

        if ($beforeActive !== (bool) $user->is_active) {
            AdminActivityLogger::log(
                action: $user->is_active ? 'admin_user.activated' : 'admin_user.deactivated',
                description: ($user->is_active ? 'تفعيل حساب مشرف: ' : 'إيقاف حساب مشرف: ').$user->name,
                subject: $user,
                properties: ['admin_user_id' => $user->id],
                request: $request,
            );
        }

        if ($beforePermissions !== $afterPermissions) {
            AdminActivityLogger::log(
                action: 'admin_permissions.updated',
                description: 'تحديث صلاحيات مشرف: '.$user->name,
                subject: $user,
                properties: [
                    'before' => $beforePermissions,
                    'after' => $afterPermissions,
                    'added' => array_values(array_diff($afterPermissions, $beforePermissions)),
                    'removed' => array_values(array_diff($beforePermissions, $afterPermissions)),
                ],
                request: $request,
            );
        }

        $message = $user->id === auth()->id()
            ? 'تم تحديث بياناتك بنجاح!'
            : 'تم تحديث بيانات المشرف بنجاح!';

        $redirectRoute = $user->id === auth()->id() && ! auth()->user()->hasPermission('admin_users.view')
            ? ['admin.users.edit', $user]
            : ['admin.users.index'];

        return redirect()->route($redirectRoute[0], $redirectRoute[1] ?? [])->with('success', $message);
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_unless($user->role === 'admin', 404);

        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'لا يمكنك حذف حسابك الخاص!');
        }

        $this->ensureAtLeastOnePermissionManagerRemains(
            target: $user,
            targetActive: false,
            targetPermissionKeys: [],
            deleting: true,
        );

        $deletedUserId = $user->id;
        $deletedName = $user->name;
        $user->delete();

        AdminActivityLogger::log(
            action: 'admin_user.deleted',
            description: 'حذف حساب مشرف: '.$deletedName,
            properties: ['admin_user_id' => $deletedUserId],
            request: request(),
        );

        return redirect()->route('admin.users.index')->with('success', 'تم حذف المشرف بنجاح!');
    }

    private function assignablePermissionKeys(): array
    {
        return auth()->user()->permissionKeys()->all();
    }

    private function validatedAssignablePermissionKeys(array $permissionKeys): array
    {
        $permissionKeys = collect($permissionKeys)
            ->filter(fn ($permissionKey): bool => is_string($permissionKey))
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

    private function ensureAtLeastOnePermissionManagerRemains(User $target, bool $targetActive, array $targetPermissionKeys, bool $deleting = false): void
    {
        $activeManagers = User::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->where('id', '!=', $target->id)
            ->whereHas('permissions', fn ($query) => $query->where('key', AdminPermissionRegistry::LAST_MANAGER_PERMISSION))
            ->count();

        if (! $deleting && $targetActive && in_array(AdminPermissionRegistry::LAST_MANAGER_PERMISSION, $targetPermissionKeys, true)) {
            $activeManagers++;
        }

        if ($activeManagers < 1) {
            throw ValidationException::withMessages([
                'permissions' => AdminPermissionRegistry::lastManagerError(),
            ]);
        }
    }
}
