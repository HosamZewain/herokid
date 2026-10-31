<?php

namespace App\Services\Orders;

use App\Models\User;
use App\Support\AdminPermissionRegistry;
use App\Support\AppDateTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeePerformanceReportService
{
    public function report(Request $request): array
    {
        $user = $request->user();
        abort_unless($user?->isAdmin(), 403);
        // Owners retain full reporting when paid automation permissions are withheld.
        // Require the active canonical owner role and permission-management access.
        $canCompare = $user->hasAllPermissions(AdminPermissionRegistry::keys())
            || ($user->hasPermission(AdminPermissionRegistry::LAST_MANAGER_PERMISSION)
                && $user->adminRoles->where('is_active', true)->contains('key', 'owner'));
        $filters = $request->validate([
            'employees' => ['sometimes', 'array', 'max:100'],
            'employees.*' => ['required', 'integer', 'min:1', 'distinct'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $today = AppDateTime::display(now());
        $filters['from'] ??= $today->startOfMonth()->toDateString();
        $filters['to'] ??= $today->toDateString();
        $selected = array_map('intval', $filters['employees'] ?? []);
        if (! $canCompare) {
            abort_if(collect($selected)->contains(fn ($id) => $id !== $user->id), 403);
            $employees = collect([$user->only(['id', 'name', 'is_active'])]);
            $ids = [$user->id];
        } else {
            $employees = User::where('role', 'admin')->orderBy('name')->get(['id', 'name', 'is_active'])->map->only(['id', 'name', 'is_active']);
            if (array_diff($selected, $employees->pluck('id')->all())) {
                throw ValidationException::withMessages(['employees' => 'اختر موظفين من القائمة.']);
            }
            $ids = $selected ?: $employees->pluck('id')->all();
        }
        // Construct only the accepted filters, and scope SQL before any totals,
        // product details or undated warnings can include a different employee.
        $scoped = Request::create($request->url(), 'GET', array_intersect_key($filters, array_flip(['from', 'to'])));
        $scoped->setUserResolver(fn () => $user);
        $report = app(AdminShippingReportService::class)->report($scoped, $ids);
        $totals = $report['teams']->keyBy('id');
        $empty = array_fill_keys(array_keys($report['summary']), 0);
        $teams = $employees->whereIn('id', $ids)->map(function ($employee) use ($totals, $empty) {
            $metrics = $totals->get((string) $employee['id'], $empty);

            return array_intersect_key($metrics, $empty) + [
                'id' => (string) $employee['id'], 'name' => $employee['name'], 'is_active' => $employee['is_active'],
                'delivery_rate' => $metrics['shipments'] ? round(100 * $metrics['delivered'] / $metrics['shipments'], 1) : null,
            ];
        })->sortByDesc('shipments')->values();

        return [
            'from' => $report['from'], 'to' => $report['to'], 'canCompare' => $canCompare,
            'canViewValues' => $report['canViewValues'], 'employees' => $employees, 'selected' => $selected,
            'teams' => $teams, 'summary' => $report['summary'], 'daily' => $report['daily'],
            'sku_breakdown' => $report['sku_breakdown'], 'undated' => $report['undated'],
        ];
    }
}
