<?php

namespace App\Services\Orders;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderProcessRun;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PreviewRegenerationGuard
{
    public function authorize(User $actor): void
    {
        abort_unless($actor->is_active && ! $actor->agent_api_enabled && $actor->hasPermission('orders.update')
            && $actor->hasPermission('orders.photos.view') && $actor->hasPermission('orders.production_prompt.manage'), 403);
    }

    /** Caller locks checkout rows. An existing process must be explicitly taken over first. */
    public function check(Order $order): void
    {
        $rows = Order::where(fn ($q) => $q->whereKey($order->id)->orWhere('checkout_group_key', $order->checkoutGroupKey()))->get();
        if ($rows->contains(fn ($r) => in_array($r->status, ['new', 'cancelled', 'completed'], true)
            || ! in_array($r->printing_status, ['not_started', 'ready', 'on_hold', 'not_required'], true)
            || ! in_array($r->shipping_status, ['not_ready', 'ready', 'not_required'], true))
            || CodexSceneRun::where('checkout_key', $order->checkoutGroupKey())->where('active_slot', 1)->exists()
            || OrderIdentityRegeneration::where('checkout_key', $order->checkoutGroupKey())->whereIn('status', ['queued', 'processing', 'uncertain'])->exists()
            || app(AutomationAssignment::class)->owns($order)) {
            throw ValidationException::withMessages(['regeneration' => 'انتظر انتهاء التوليد أو راجع النتيجة غير المؤكدة. إعادة التوليد متاحة قبل الطباعة والشحن.']);
        }
        if (OrderProcessRun::where('checkout_key', $order->checkoutGroupKey())->exists() && ! AutomationHandoff::held($order->checkoutGroupKey())) {
            throw ValidationException::withMessages(['regeneration' => 'استلم الطلب من الأوتوميشن أولًا؛ حتى لا يعتمد رد قديم النسخة الجديدة.']);
        }
    }
}
