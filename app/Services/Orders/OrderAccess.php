<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\RequestSettings;
use Illuminate\Support\Facades\DB;

/** Staff HTTP boundary only. Never restrict customers, API agents or background jobs. */
class OrderAccess
{
    public function restricted(?User $user = null): bool
    {
        $user ??= request()->user();

        return request()->routeIs('admin.*') && $user && ! $user->agent_api_enabled
            && (RequestSettings::all()['orders_visibility'] ?? 'all') === 'assigned'
            && ! $user->hasPermission('orders.view_all');
    }

    public function eligibleUsers()
    {
        if (! request()->attributes->has('order_assignment_candidates')) {
            request()->attributes->set('order_assignment_candidates', User::with(['permissions:id,key', 'adminRoles.permissions:id,key'])->where('is_active', true)->where('role', 'admin')->get()->filter(fn ($u) => ! $u->agent_api_enabled && $u->hasPermission('orders.view'))->values());
        }

        return request()->attributes->get('order_assignment_candidates');
    }

    public function keys(User $user)
    {
        return DB::table('order_group_assignments')->select('checkout_group_key')->where('assigned_to_user_id', $user->id);
    }

    public function scope($query, string $column = 'orders.checkout_group_key')
    {
        if ($this->restricted()) {
            $query->whereIn($column, $this->keys(request()->user()));
        }

        return $query;
    }

    public function assert(Order $order): void
    {
        abort_if($this->restricted() && ! DB::table('order_group_assignments')->where('checkout_group_key', $order->checkoutGroupKey())->where('assigned_to_user_id', request()->user()->id)->exists(), 404);
    }

    public function controls(Order $order, User $user): bool
    {
        return $user->is_active && ! $user->agent_api_enabled && $user->hasPermission('orders.automation.control')
            && $user->hasPermission('orders.view') && $user->hasPermission('orders.update')
            && ($user->hasPermission('orders.view_all') || DB::table('order_group_assignments')->where('checkout_group_key', $order->checkoutGroupKey())->where('assigned_to_user_id', $user->id)->exists());
    }
}
