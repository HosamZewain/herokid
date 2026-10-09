<?php

namespace App\Services\RoboDesk\Conversations;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\AdminOrderGroupService;
use Illuminate\Database\Eloquent\Builder;

class ConversationAccess
{
    private array $assignedKeys = [];

    public function assignedActive(User $user): Builder
    {
        return app(AdminOrderGroupService::class)->activeOrdersQuery()
            ->whereHas('groupAssignment', fn ($q) => $q->where('assigned_to_user_id', $user->id));
    }

    public function authorize(User $user, Order $order): void
    {
        // Fresh server-side scope for every action; never trust browser window state.
        abort_unless($user->hasPermission('orders.conversations.view-all') || $this->assignedActive($user)->where('checkout_group_key', $order->checkout_group_key)->exists(), 403);
    }

    public function allowsCheckout(User $user, ?string $key): bool
    {
        if ($user->hasPermission('orders.conversations.view-all')) {
            return true;
        }
        $this->assignedKeys[$user->id] ??= array_fill_keys($this->assignedActive($user)->distinct()->pluck('checkout_group_key')->all(), true);

        return isset($this->assignedKeys[$user->id][$key ?? '']);
    }
}
