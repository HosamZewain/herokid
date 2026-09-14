<?php

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AdminRoleRegistry
{
    public static function roles(): array
    {
        return config('admin_roles.roles', []);
    }

    public static function keys(): array
    {
        return array_keys(self::roles());
    }

    public static function permissionKeys(string $roleKey): array
    {
        $definition = self::roles()[$roleKey] ?? null;

        if (! $definition) {
            return [];
        }

        $patterns = $definition['permission_patterns'] ?? [];
        $excluded = $definition['exclude_patterns'] ?? [];

        return collect(AdminPermissionRegistry::keys())
            ->filter(fn (string $key): bool => self::matches($key, $patterns))
            ->reject(fn (string $key): bool => self::matches($key, $excluded))
            ->values()
            ->all();
    }

    public static function options(?array $onlyKeys = null): Collection
    {
        $allowed = $onlyKeys === null ? null : array_flip($onlyKeys);

        return collect(self::roles())
            ->filter(fn (array $role, string $key): bool => $allowed === null || isset($allowed[$key]))
            ->map(fn (array $role, string $key): array => array_merge($role, [
                'key' => $key,
                'permission_keys' => self::permissionKeys($key),
            ]))
            ->sortBy('sort_order')
            ->values();
    }

    private static function matches(string $permissionKey, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || Str::is($pattern, $permissionKey)) {
                return true;
            }
        }

        return false;
    }
}
