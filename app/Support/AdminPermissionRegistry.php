<?php

namespace App\Support;

use Illuminate\Support\Collection;

class AdminPermissionRegistry
{
    public const LAST_MANAGER_PERMISSION = 'admin_users.permissions.manage';

    public static function permissions(): array
    {
        return config('admin_permissions.permissions', []);
    }

    public static function groups(): array
    {
        return config('admin_permissions.groups', []);
    }

    public static function keys(): array
    {
        return array_keys(self::permissions());
    }

    public static function has(string $permissionKey): bool
    {
        return array_key_exists($permissionKey, self::permissions());
    }

    public static function metadata(string $permissionKey): ?array
    {
        return self::permissions()[$permissionKey] ?? null;
    }

    public static function grouped(?array $onlyKeys = null): Collection
    {
        $allowed = $onlyKeys ? array_flip($onlyKeys) : null;
        $permissions = collect(self::permissions())
            ->filter(fn (array $permission, string $key): bool => $allowed === null || isset($allowed[$key]))
            ->map(fn (array $permission, string $key): array => array_merge($permission, ['key' => $key]))
            ->sortBy([
                ['group_key', 'asc'],
                ['sort_order', 'asc'],
                ['key', 'asc'],
            ])
            ->groupBy('group_key');

        return collect(self::groups())
            ->map(fn (array $group, string $key): array => array_merge($group, [
                'key' => $key,
                'permissions' => $permissions->get($key, collect())->values(),
            ]))
            ->filter(fn (array $group): bool => $group['permissions']->isNotEmpty())
            ->sortBy('sort_order')
            ->values();
    }

    public static function firstAllowedRoute(array $permissionKeys): ?string
    {
        $allowed = array_flip($permissionKeys);

        $routes = [
            'dashboard.view' => 'admin.dashboard.index',
            'analytics.view' => 'admin.analytics.index',
            'sales_reports.view' => 'admin.sales-report.index',
            'expenses.view' => 'admin.expenses.index',
            'visitor_carts.view' => 'admin.visitor-carts.index',
            'orders.view' => 'admin.orders.index',
            'orders.create' => 'admin.orders.create',
            'booklet_previews.view' => 'admin.booklet-previews.index',
            'child_identities.view' => 'admin.child-identities.index',
            'stories.view' => 'admin.stories.index',
            'store.products.view' => 'admin.products.index',
            'store.categories.view' => 'admin.product-categories.index',
            'customers.view' => 'admin.customers.index',
            'content.faqs.view' => 'admin.faqs.index',
            'content.testimonials.view' => 'admin.testimonials.index',
            'content.messages.view' => 'admin.messages.index',
            'settings.site.view' => 'admin.settings.index',
            'settings.production_prompt.view' => 'admin.settings.story-production-prompt.edit',
            'settings.ai_providers.view' => 'admin.settings.ai-providers.index',
            'settings.notifications.view' => 'admin.settings.notifications.index',
            'settings.delivery_zones.view' => 'admin.delivery-zones.index',
            'settings.pricing.view' => 'admin.pricing.index',
            'admin_users.view' => 'admin.users.index',
            'activity_logs.view' => 'admin.activity-logs.index',
        ];

        foreach ($routes as $permission => $routeName) {
            if (isset($allowed[$permission])) {
                return $routeName;
            }
        }

        return null;
    }

    public static function lastManagerError(): string
    {
        return (string) config('admin_permissions.last_manager_error');
    }
}
