<?php

namespace App\Services\CodexScenes;

use App\Models\CodexSceneRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\OrderAutomationSummary;

/** Read-only checkout summary. Saved scenes are counted only after the S3 collector accepted them. */
class OrderGenerationBanner
{
    public function data(Order $order): array
    {
        if ($order->trashed()) {
            return ['generationRows' => collect()];
        }

        $orders = Order::query()->where(function ($query) use ($order) {
            $query->whereKey($order->id);
            if ($order->checkout_group_key) {
                $query->orWhere('checkout_group_key', $order->checkout_group_key);
            }
        })->pluck('id');
        $runs = CodexSceneRun::query()->whereIn('order_id', $orders)->where('active_slot', 1)
            ->with(['order:id,child_name,story_id', 'order.story:id,title'])
            ->orderBy('created_at')->get([
                'id', 'order_id', 'order_item_id', 'production_kind', 'status', 'scenes', 'scene_count', 'created_at',
            ]);
        $items = OrderItem::whereIn('id', $runs->pluck('order_item_id')->filter())->pluck('title', 'id');

        return ['workspaceOrder' => $order, 'workspaceSummary' => app(OrderAutomationSummary::class)->forKeys([$order->checkoutGroupKey()])[$order->checkoutGroupKey()], 'unstartedBooks' => OrderItem::whereIn('order_id', $orders)->whereHas('productionComponents', fn ($q) => $q->where('production_stage', 'book_interior'))->whereNotIn('id', CodexSceneRun::where('production_kind', 'coloring_book')->whereNotNull('order_item_id')->select('order_item_id'))->get(['id', 'order_id', 'title', 'updated_at']), 'generationRows' => $runs->map(function ($run) use ($items) {
            $total = max(1, (int) ($run->scene_count ?? 13));
            $done = collect($run->scenes ?? [])->filter(fn ($scene, $number) => ctype_digit((string) $number)
                && (int) $number >= 1 && (int) $number <= $total && is_array($scene) && ! empty($scene['path']) && ! empty($scene['sha256']))->count();

            return [
                'id' => $run->id, 'order_id' => $run->order_id,
                'label' => $run->production_kind === 'coloring_book'
                    ? 'كتاب تلوين — '.($items[$run->order_item_id] ?? 'منتج #'.$run->order_item_id)
                    : 'قصة — '.($run->order?->story?->title ?? 'طلب #'.$run->order_id),
                'child' => $run->order?->child_name,
                'status' => $run->status, 'done' => $done, 'total' => $total,
            ];
        })];
    }
}
