<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductionProject;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductionStudio\ProductionAutomationStateMachine;
use App\Support\AdminActivityLogger;
use App\Support\ProductionAutomation;
use App\Support\ProductionStudio;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderDeletionService
{
    public function __construct(
        private readonly ProductionAutomationStateMachine $automation,
    ) {}

    public function deleteGroup(Order $representative, string $reason, User $admin, Request $request): int
    {
        return DB::transaction(function () use ($representative, $reason, $admin, $request): int {
            $orders = Order::query()
                ->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()
                ->get();
            $stockChanges = [];
            $productionEffects = [];

            foreach ($orders as $order) {
                $stockChanges = [...$stockChanges, ...$this->releaseStock($order, $admin)];
                if ($effect = $this->cancelProduction($order, $reason, $admin)) {
                    $productionEffects[] = $effect;
                }
                $order->forceFill([
                    'deleted_by_user_id' => $admin->id,
                    'deletion_reason' => $reason,
                ])->save();
                $order->delete();
            }

            AdminActivityLogger::log(
                action: 'checkout.deleted',
                description: 'نقل عملية الشراء إلى سلة المحذوفات: '.$representative->checkoutGroupKey(),
                subject: $representative,
                properties: $this->activityProperties($orders, $reason, [
                    'stock_changes' => $stockChanges,
                    'production_effects' => $productionEffects,
                ]),
                request: $request,
            );

            return $orders->count();
        });
    }

    public function deleteOrder(Order $order, string $reason, User $admin, Request $request): void
    {
        DB::transaction(function () use ($order, $reason, $admin, $request): void {
            $locked = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            $siblings = Order::query()
                ->where('checkout_group_key', $locked->checkoutGroupKey())
                ->where('id', '!=', $locked->id)
                ->lockForUpdate()
                ->get();
            $directProducts = $locked->items->where('item_type', 'product');
            $movedProducts = $directProducts->map(fn (OrderItem $item): array => [
                'item_id' => $item->id,
                'title' => $item->title,
                'quantity' => $item->quantity,
            ])->values()->all();

            $isStoryOrder = $locked->story_id !== null || $locked->items->contains('item_type', 'story');

            if ($directProducts->isNotEmpty() && $siblings->isEmpty() && $isStoryOrder) {
                throw ValidationException::withMessages([
                    'delete' => 'لا يمكن حذف القصة الأخيرة منفردة لأنها تحمل منتجات مستقلة. احذف عملية الشراء كاملة للحفاظ على ترابط المنتجات.',
                ]);
            }

            if ($directProducts->isNotEmpty() && $siblings->isNotEmpty()) {
                OrderItem::query()
                    ->whereKey($directProducts->pluck('id'))
                    ->update(['order_id' => $siblings->first()->id]);
                $locked->unsetRelation('items');
                $locked->load('items');
            }

            $stockChanges = $this->releaseStock($locked, $admin);
            $productionEffect = $this->cancelProduction($locked, $reason, $admin);
            $locked->forceFill([
                'deleted_by_user_id' => $admin->id,
                'deletion_reason' => $reason,
            ])->save();
            $locked->delete();

            AdminActivityLogger::log(
                action: 'order.deleted',
                description: 'نقل قصة/طلب إلى سلة المحذوفات: '.$locked->order_number,
                subject: $locked,
                properties: $this->activityProperties(collect([$locked]), $reason) + [
                    'independent_products_moved_to_order_id' => $directProducts->isNotEmpty() ? $siblings->first()?->id : null,
                    'independent_products_moved' => $movedProducts,
                    'stock_changes' => $stockChanges,
                    'production_effects' => array_values(array_filter([$productionEffect])),
                ],
                request: $request,
            );
        });
    }

    public function restoreGroup(Order $representative, User $admin, Request $request): int
    {
        return DB::transaction(function () use ($representative, $request): int {
            $orders = Order::onlyTrashed()
                ->where('checkout_group_key', $representative->checkoutGroupKey())
                ->lockForUpdate()
                ->get();

            $stockChanges = $this->reserveStockFor($orders);

            foreach ($orders as $order) {
                $order->restore();
            }

            AdminActivityLogger::log(
                action: 'checkout.restored',
                description: 'استعادة عملية الشراء: '.$representative->checkoutGroupKey(),
                subject: $representative,
                properties: $this->activityProperties($orders, null, ['stock_changes' => $stockChanges]),
                request: $request,
            );

            return $orders->count();
        });
    }

    public function restoreOrder(Order $order, User $admin, Request $request): void
    {
        DB::transaction(function () use ($order, $request): void {
            $locked = Order::onlyTrashed()->lockForUpdate()->findOrFail($order->id);
            $stockChanges = $this->reserveStockFor(collect([$locked]));
            $locked->restore();

            AdminActivityLogger::log(
                action: 'order.restored',
                description: 'استعادة القصة/الطلب: '.$locked->order_number,
                subject: $locked,
                properties: $this->activityProperties(collect([$locked]), null, ['stock_changes' => $stockChanges]),
                request: $request,
            );
        });
    }

    private function releaseStock(Order $order, User $admin): array
    {
        $order->loadMissing('items');
        $changes = [];

        foreach ($order->items->whereIn('item_type', ['product', 'product_add_on']) as $item) {
            if ($item->stock_released_at) {
                continue;
            }

            $product = $item->product_id ? Product::query()->lockForUpdate()->find($item->product_id) : null;
            if (! $product || $product->inventory_mode !== 'track_stock') {
                continue;
            }

            $quantity = max(1, (int) $item->quantity);
            $variant = $item->product_variant_id
                ? ProductVariant::query()->lockForUpdate()->find($item->product_variant_id)
                : null;

            if ($variant && $variant->stock_quantity !== null) {
                $before = (int) $variant->stock_quantity;
                $variant->increment('stock_quantity', $quantity);
                $changes[] = $this->stockChange($item, 'released', 'variant', $before, $before + $quantity);
            } elseif ($product->stock_quantity !== null) {
                $before = (int) $product->stock_quantity;
                $product->increment('stock_quantity', $quantity);
                $changes[] = $this->stockChange($item, 'released', 'product', $before, $before + $quantity);
            }

            $item->forceFill([
                'stock_released_at' => now(),
                'stock_released_by_user_id' => $admin->id,
            ])->save();
        }

        return $changes;
    }

    private function reserveStockFor(Collection $orders): array
    {
        $items = OrderItem::query()
            ->whereIn('order_id', $orders->pluck('id'))
            ->whereNotNull('stock_released_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $changes = [];

        foreach ($items as $item) {
            $product = $item->product_id ? Product::query()->lockForUpdate()->find($item->product_id) : null;
            if (! $product || $product->inventory_mode !== 'track_stock') {
                $item->forceFill(['stock_released_at' => null, 'stock_released_by_user_id' => null])->save();

                continue;
            }

            $quantity = max(1, (int) $item->quantity);
            $variant = $item->product_variant_id
                ? ProductVariant::query()->lockForUpdate()->find($item->product_variant_id)
                : null;
            $available = $variant?->stock_quantity ?? $product->stock_quantity;

            if ($available !== null && $available < $quantity) {
                throw ValidationException::withMessages([
                    'restore' => 'لا يمكن الاستعادة لأن مخزون «'.$item->title.'» أقل من الكمية المطلوبة ('.$quantity.').',
                ]);
            }

            if ($variant && $variant->stock_quantity !== null) {
                $before = (int) $variant->stock_quantity;
                $variant->decrement('stock_quantity', $quantity);
                $changes[] = $this->stockChange($item, 'reserved', 'variant', $before, $before - $quantity);
            } elseif ($product->stock_quantity !== null) {
                $before = (int) $product->stock_quantity;
                $product->decrement('stock_quantity', $quantity);
                $changes[] = $this->stockChange($item, 'reserved', 'product', $before, $before - $quantity);
            }

            $item->forceFill(['stock_released_at' => null, 'stock_released_by_user_id' => null])->save();
        }

        return $changes;
    }

    private function cancelProduction(Order $order, string $reason, User $admin): ?array
    {
        $project = ProductionProject::query()->where('order_id', $order->id)->first();
        if (! $project) {
            return null;
        }

        $previousStatus = $project->status;

        $runs = $project->automationRuns()
            ->whereNotIn('status', ProductionAutomation::terminalStatuses())
            ->get();

        foreach ($runs as $run) {
            $this->automation->transitionRun(
                $run,
                ProductionAutomation::STATUS_CANCELLED,
                ['safe_failure_summary' => 'تم الإلغاء بسبب حذف الطلب إدارياً.', 'reason' => $reason],
                $admin,
                'order_deletion',
            );
        }

        if ($project->status !== 'cancelled') {
            $project->update(['status' => 'cancelled', 'cancel_reason' => 'حذف الطلب: '.$reason]);
            ProductionStudio::log($project, 'project.cancelled_by_order_deletion', 'تم إلغاء المشروع بسبب حذف الطلب إدارياً.', [
                'order_id' => $order->id,
                'reason' => $reason,
            ], $admin);
        }

        return [
            'order_id' => $order->id,
            'production_project_id' => $project->id,
            'previous_project_status' => $previousStatus,
            'project_status' => 'cancelled',
            'cancelled_automation_run_ids' => $runs->pluck('id')->values()->all(),
            'assets_retained' => true,
        ];
    }

    private function activityProperties(Collection $orders, ?string $reason, array $extra = []): array
    {
        $orders->each(fn (Order $order) => $order->loadMissing('items'));
        $itemsTotalCents = (int) $orders->flatMap->items->sum('total_price_cents');
        $deliveryCents = (int) round($orders->max(fn (Order $order): float => (float) data_get($order->delivery_details, 'delivery_fee', 0)) * 100);

        return [
            'checkout_group_key' => $orders->first()?->checkoutGroupKey(),
            'reason' => $reason,
            'order_ids' => $orders->pluck('id')->values()->all(),
            'order_numbers' => $orders->pluck('order_number')->values()->all(),
            'items' => $orders->flatMap->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'type' => $item->item_type,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'total_price_cents' => $item->total_price_cents,
            ])->values()->all(),
            'items_total_cents' => $itemsTotalCents,
            'delivery_cents' => $deliveryCents,
            'checkout_total_cents' => $itemsTotalCents + $deliveryCents,
        ] + $extra;
    }

    private function stockChange(OrderItem $item, string $action, string $source, int $before, int $after): array
    {
        return [
            'item_id' => $item->id,
            'product_id' => $item->product_id,
            'product_variant_id' => $item->product_variant_id,
            'action' => $action,
            'source' => $source,
            'quantity' => max(1, (int) $item->quantity),
            'stock_before' => $before,
            'stock_after' => $after,
        ];
    }
}
