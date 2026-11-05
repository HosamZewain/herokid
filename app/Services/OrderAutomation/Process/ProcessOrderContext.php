<?php

namespace App\Services\OrderAutomation\Process;

use App\Models\Order;
use App\Models\OrderProcessRun;
use App\Support\Phone;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RuntimeException;

class ProcessOrderContext
{
    public const METADATA = ['actor_id', 'supersedes_run_id', 'story_recovery', 'identity_recovery', 'staff_preview_approval'];

    /** Only an explicit story-revision run may exclude its reviewed archived stories. */
    public function forRun(OrderProcessRun $run, bool $lock = false): Collection
    {
        $revision = $run->context['story_recovery'] ?? null;
        if (! $revision) {
            return $this->orders($run->checkout_key, $lock);
        }
        $source = OrderProcessRun::where('checkout_key', $run->checkout_key)->find($revision['source_run_id'] ?? 0);
        if (! $source || $source->id === $run->id) {
            throw new RuntimeException('order_ineligible');
        }
        $all = Order::withTrashed()->where('checkout_group_key', $run->checkout_key)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->with('items')->get();
        $retired = $revision['retired_order_ids'] ?? [];
        $known = array_merge($source->context['order_ids'], data_get($source->context, 'story_recovery.retired_order_ids', []));
        foreach ($retired as $id) {
            $row = $all->firstWhere('id', $id);
            if (! $row || ! $row->trashed() || ! $row->story_id || ! in_array($id, $known, true)) {
                throw new RuntimeException('order_ineligible');
            }
        }
        $orders = $all->whereNotIn('id', $retired)->values();
        if ($orders->isEmpty() || $orders->contains(fn ($row) => $row->trashed() || $row->id <= (int) config('order_process.after_order_id'))) {
            throw new RuntimeException('order_ineligible');
        }

        return $orders;
    }

    public function metadata(OrderProcessRun $run): array
    {
        return Arr::only($run->context, self::METADATA);
    }

    public function orders(string $key, bool $lock = false): Collection
    {
        $orders = Order::withTrashed()->where('checkout_group_key', $key)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->with('items')->get();
        if ($orders->isEmpty() || $orders->contains(fn ($o) => $o->trashed() || $o->id <= (int) config('order_process.after_order_id'))) {
            throw new RuntimeException('order_ineligible');
        }

        return $orders;
    }

    public function snapshot(Collection $orders, int $version = 2): array
    {
        if (! in_array($version, [1, 2], true)) {
            throw new RuntimeException('context_version_unsupported');
        }
        $phones = $orders->map(fn ($o) => Phone::forWhatsApp((string) data_get($o->delivery_details, 'phone')))->unique();
        if ($phones->count() !== 1 || ! Phone::isValidMobile($phones->first())) {
            throw new RuntimeException('contact_invalid');
        }
        $lines = $orders->flatMap(fn ($o) => $o->items->map(fn ($i) => $i->only(['id', 'order_id', 'item_type', 'story_id', 'product_id', 'product_variant_id', 'title', 'quantity', 'unit_price_cents', 'total_price_cents'])))->all();
        $types = $orders->flatMap(fn ($o) => [$o->story_id ? 'story' : 'product'])->merge(collect($lines)->map(fn ($i) => $i['item_type'] === 'story' ? 'story' : 'product'))->unique();
        $status = $orders->pluck('status')->unique();
        $facts = $orders->map(fn ($o) => $o->only(['id', 'parent_name', 'story_id', 'status', 'shipping_status', 'printing_status', 'payment_status', 'paid_amount_cents', 'discount_cents', 'delivery_details', 'uploaded_photos', 'child_name', 'child_age', 'child_gender', 'story_price', 'child_identity_request_id', 'child_identity_approved_attempt_id']))->all();

        if ($version === 2) {
            // Payments are expected while identities are being reviewed. Keep prices,
            // discounts, contact details and source media immutable, not the ledger balance.
            foreach ($facts as &$fact) {
                unset($fact['payment_status'], $fact['paid_amount_cents']);
                foreach (['payment_status', 'payment_method', 'paid_amount', 'remaining_amount'] as $field) {
                    unset($fact['delivery_details'][$field]);
                }
            }
            unset($fact);
        }

        return ['fingerprint_version' => $version, 'order_ids' => $orders->pluck('id')->all(), 'order_statuses' => $orders->pluck('status', 'id')->all(), 'phone' => $phones->first(), 'status' => $status->count() === 1 ? $status->first() : 'mixed',
            'type' => $types->count() === 1 ? $types->first() : 'mixed', 'fingerprint' => hash('sha256', json_encode([$facts, $lines], JSON_THROW_ON_ERROR)),
            'story_ids' => $orders->pluck('story_id')->filter()->unique()->values()->all(), 'product_ids' => collect($lines)->pluck('product_id')->filter()->unique()->values()->all()];
    }

    public function matches(array $definition, array $snapshot): bool
    {
        $c = $definition['conditions'];
        if ($snapshot['status'] !== $c['status']) {
            return false;
        }
        $stories = $snapshot['story_ids'] && (! $c['story_ids'] || array_intersect($snapshot['story_ids'], $c['story_ids']));
        $products = $snapshot['product_ids'] && (! $c['product_ids'] || array_intersect($snapshot['product_ids'], $c['product_ids']));

        return match ($c['type']) {
            'story' => (bool) $stories, 'product' => (bool) $products, default => (bool) ($stories || $products)
        };
    }
}
