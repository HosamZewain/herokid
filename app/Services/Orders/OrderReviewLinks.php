<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderIdentityRegeneration;
use App\Models\OrderProcessIdentity;
use App\Models\OrderProductPreviewGallery;
use App\Services\OrderAutomation\Process\ProcessRuntime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/** Image reviews only. Booklet/scene PDFs deliberately have a separate variable. */
class OrderReviewLinks
{
    public function forOrders(Collection $orders): string
    {
        $orders = new \Illuminate\Database\Eloquent\Collection($orders->filter(fn ($order) => $order instanceof Order && ! $order->trashed())->values()->all());
        if ($orders->isEmpty()) {
            return '';
        }
        $orders->loadMissing(['childIdentityApprovedAttempt', 'story']);
        $pending = OrderProcessIdentity::with(['attempt', 'run'])
            ->whereIn('order_id', $orders->whereNull('child_identity_approved_attempt_id')->pluck('id'))
            ->latest('id')->get()->unique('order_id')->keyBy('order_id');
        $links = [];
        $expires = now()->addDays(35);
        foreach ($orders as $order) {
            if (! $order->story_id) {
                continue;
            }
            $attempt = $order->childIdentityApprovedAttempt;
            $url = null;
            if ($attempt?->status === 'succeeded' && filled($attempt->output_storage_path)) {
                $url = URL::temporarySignedRoute('orders.approved-child-identity', $expires, ['order' => $order->id]);
            } elseif (! $order->child_identity_approved_attempt_id) {
                $manual = OrderIdentityRegeneration::where('order_id', $order->id)->latest('id')->first();
                if ($manual && app(OrderIdentityRegenerationService::class)->previewReady($manual)) {
                    $url = URL::temporarySignedRoute('orders.regenerated-identity-preview', $expires, ['regeneration' => $manual->id]);
                }
                $task = $manual ? null : $pending->get($order->id);
                if ($task && $task->status === 'succeeded' && $task->attempt?->status === 'succeeded' && filled($task->attempt->output_storage_path)
                    && $task->run && $task->run->checkout_key === $order->checkoutGroupKey()
                    && ! in_array($task->run->status, ['cancelled', 'blocked', 'uncertain'], true)) {
                    try {
                        app(ProcessRuntime::class)->guard($task->run);
                        $url = URL::temporarySignedRoute('order-process.identity-image', $expires, ['run' => $task->run_id, 'identity' => $task->id]);
                    } catch (\RuntimeException) {
                        // The public image endpoint rejects this stale or revoked run too.
                    }
                }
            }
            if ($url) {
                $label = collect(['هوية الطفل', $order->child_name, $order->story?->title])->filter()->implode(' — ');
                $links[$url] = $label."\n".$url;
            }
        }
        // A checkout gallery intentionally contains all uploaded product preview images,
        // including images owned by a removed sibling (the existing gallery contract).
        $galleries = OrderProductPreviewGallery::whereIn('checkout_group_key', $orders->map->checkoutGroupKey()->unique())
            ->where('status', 'active')->whereHas('previews')->orderBy('id')->get();
        foreach ($galleries as $gallery) {
            if ($url = $gallery->publicUrl()) {
                $links[$url] = "معاينة المنتجات\n".$url;
            }
        }

        return implode("\n\n", $links);
    }
}
