<?php

namespace App\Http\Middleware;

use App\Models\BookletPreview;
use App\Models\Order;
use App\Models\OrderAttachment;
use App\Models\ProductionProject;
use App\Services\Orders\AutomationAssignment;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Block conflicting staff writes; financial review and internal notes remain usable. */
class ProtectAutomationOrders
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() && ! $request->routeIs('admin.orders.production-prompt.regenerate')) {
            return $next($request);
        }
        if ($request->routeIs('admin.orders.groups.payment', 'admin.orders.payment-review.close', 'admin.orders.codex-scenes.stop',
            'admin.orders.notes.*', 'admin.orders.groups.tags*', 'admin.orders.activity.*', 'admin.orders.message-retry')) {
            return $next($request);
        }
        $ids = [];
        foreach (['order', 'representative'] as $parameter) {
            $value = $request->route($parameter);
            $id = $value instanceof Order ? $value->id : $value;
            if (is_scalar($id) && ctype_digit((string) $id)) {
                $ids[] = (int) $id;
            }
        }
        if ($request->routeIs('admin.orders.bulk-actions')) {
            foreach ((array) $request->input('representative_ids', []) as $id) {
                if (is_scalar($id) && ctype_digit((string) $id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        foreach (['attachment' => OrderAttachment::class, 'project' => ProductionProject::class,
            'bookletPreview' => BookletPreview::class] as $parameter => $model) {
            $value = $request->route($parameter);
            if ($value instanceof $model) {
                $ids[] = $value->order_id;
            } elseif (is_scalar($value) && ctype_digit((string) $value)) {
                $ids[] = $model::find($value)?->order_id;
            }
        }
        $identity = $request->route('identity');
        $identityId = $identity instanceof Model ? $identity->getKey() : $identity;
        if ($request->routeIs('admin.child-identities.*') && is_scalar($identityId) && ctype_digit((string) $identityId)) {
            $ids = array_merge($ids, Order::where('child_identity_request_id', $identityId)->pluck('id')->all());
        }
        foreach (Order::whereIn('id', array_filter($ids))->get() as $order) {
            app(AutomationAssignment::class)->assertAvailable($order);
        }

        return $next($request);
    }
}
