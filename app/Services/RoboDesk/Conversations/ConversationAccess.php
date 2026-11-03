<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\OrderAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ConversationAccess
{
    private array $assignedKeys = [];

    public function notificationOrders(User $user): Builder
    {
        return $user->hasPermission('orders.conversations.view-all')
            ? app(AdminOrderGroupService::class)->activeOrdersQuery()
            : $this->assignedActive($user);
    }

    public function assignedActive(User $user): Builder
    {
        return app(AdminOrderGroupService::class)->activeOrdersQuery()
            ->whereHas('groupAssignment', fn ($q) => $q->where('assigned_to_user_id', $user->id));
    }

    public function authorize(User $user, Order $order): void
    {
        app(OrderAccess::class)->assert($order);
        // Fresh server-side scope for every action; never trust browser window state.
        abort_unless($user->hasPermission('orders.conversations.view-all') || $this->assignedActive($user)->where('checkout_group_key', $order->checkout_group_key)->exists(), 403);
    }

    public function allowsCheckout(User $user, ?string $key): bool
    {
        if (app(OrderAccess::class)->restricted($user) && ! DB::table('order_group_assignments')->where('checkout_group_key', $key)->where('assigned_to_user_id', $user->id)->exists()) {
            return false;
        }
        if ($user->hasPermission('orders.conversations.view-all')) {
            return true;
        }
        $this->assignedKeys[$user->id] ??= array_fill_keys($this->assignedActive($user)->distinct()->pluck('checkout_group_key')->all(), true);

        return isset($this->assignedKeys[$user->id][$key ?? '']);
    }
}
