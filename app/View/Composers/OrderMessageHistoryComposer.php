<?php

namespace App\View\Composers;

use App\Models\Order;
use App\Services\RoboDesk\OrderMessageHistory;
use Illuminate\View\View;

class OrderMessageHistoryComposer
{
    public function compose(View $view): void
    {
        $data = $view->getData();
        $order = $data['order'] ?? $data['representative'] ?? null;
        $group = $data['group'] ?? $data['checkoutGroup'] ?? null;
        if (! $order instanceof Order && is_array($group)) {
            $order = Order::where('checkout_group_key', $group['key'] ?? '')->first();
        }
        $view->with('messageHistoryOrder', $order);
        $view->with('orderMessageStages', $order instanceof Order && auth()->user()?->hasPermission('orders.view')
            ? app(OrderMessageHistory::class)->stages($order, auth()->user()) : collect());
    }
}
