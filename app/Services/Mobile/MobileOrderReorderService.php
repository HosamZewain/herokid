<?php

namespace App\Services\Mobile;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class MobileOrderReorderService
{
    public function __construct(private readonly MobileCartService $carts) {}

    public function reorder(User $user, Order $order, string $requestKey): array
    {
        if (! in_array($order->status, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['order' => 'Only delivered or cancelled orders can be reordered.']);
        }

        return DB::transaction(function () use ($user, $order, $requestKey): array {
            $order->loadMissing(['items', 'items.story', 'items.product', 'items.variant']);
            $storyCartItems = [];

            foreach ($order->items->where('item_type', 'story') as $item) {
                $childUuid = data_get($item->personalization_snapshot, 'child_profile_uuid');
                $child = $user->childProfiles()->where('is_active', true)->where('uuid', $childUuid)->first();
                $photos = $child?->activePhotos()->limit(2)->pluck('uuid')->all() ?? [];
                if (! $child || count($photos) < 2) {
                    throw ValidationException::withMessages(['child_profile_id' => 'This story needs an active child profile with at least two reusable photos before it can be reordered.']);
                }
                $key = $this->itemKey($requestKey, $item->id);
                $cart = $this->carts->add($user, [
                    'item_type' => 'story', 'story_id' => $item->story_id, 'child_profile_id' => $child->uuid,
                    'child_photo_ids' => $photos, 'dedication' => data_get($item->personalization_snapshot, 'dedication'),
                    'language' => data_get($item->item_snapshot, 'story_language'), 'idempotency_key' => $key,
                ]);
                $storyCartItems[$item->id] = $cart->items()->where('idempotency_key', $key)->value('uuid');
            }

            foreach ($order->items->whereIn('item_type', ['product', 'product_add_on']) as $item) {
                $linked = $item->linked_order_item_id ? ($storyCartItems[$item->linked_order_item_id] ?? null) : null;
                $this->carts->add($user, [
                    'item_type' => 'product', 'product_id' => $item->product_id, 'variant_id' => $item->product_variant_id,
                    'linked_item_id' => $linked, 'quantity' => $item->quantity, 'idempotency_key' => $this->itemKey($requestKey, $item->id),
                ]);
            }

            return $this->carts->payload($this->carts->activeCart($user));
        });
    }

    private function itemKey(string $requestKey, int $itemId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'herokid:reorder:'.$requestKey.':'.$itemId)->toString();
    }
}
