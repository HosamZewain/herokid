<?php

namespace App\Services\AdminAssistant;

use App\Models\User;
use App\Services\Orders\OrderAccess;
use App\Support\RequestSettings;
use Illuminate\Support\Facades\DB;

final class AssistantAccess
{
    public function user(): User
    {
        $user = User::find(request()->user()?->id);
        abort_unless(config('admin_assistant.enabled') && $user?->isAdmin() && $user->is_active && ! $user->agent_api_enabled, 403);
        request()->setUserResolver(fn () => $user);
        abort_unless($user->hasAnyPermission(['orders.view', 'sales_reports.view']), 403);

        return $user;
    }

    public function tools(User $user): array
    {
        $tools = ['help'];
        if ($user->hasPermission('orders.view')) {
            $tools = [...$tools, 'order', 'queue'];
            if (! app(OrderAccess::class)->restricted($user) && $user->hasPermission('order_automation.view')) {
                $tools[] = 'autopilot';
            }
        }
        if (! app(OrderAccess::class)->restricted($user) && $user->hasPermission('sales_reports.view')) {
            $tools = [...$tools, 'sales', 'payments'];
        }

        return $tools;
    }

    public function signature(User $user): string
    {
        request()->attributes->remove(RequestSettings::class);

        return hash('sha256', json_encode([$user->id, $user->permissionKeys()->sort()->values()->all(),
            RequestSettings::all()['orders_visibility'] ?? 'all',
            DB::table('order_group_assignments')->where('assigned_to_user_id', $user->id)->orderBy('checkout_group_key')->pluck('checkout_group_key')->all()], JSON_THROW_ON_ERROR));
    }

    public function check(string $signature): User
    {
        $user = $this->user();
        abort_unless(hash_equals($signature, $this->signature($user)), 403, 'تغيّرت صلاحياتك أو نطاق الطلبات. افتح محادثة جديدة.');

        return $user;
    }
}
