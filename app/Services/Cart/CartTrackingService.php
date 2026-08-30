<?php

namespace App\Services\Cart;

use App\Models\Order;
use App\Models\VisitorCart;
use App\Models\VisitorCartActivity;
use App\Models\VisitorCartItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CartTrackingService
{
    public function captureAttribution(Request $request): void
    {
        $attribution = collect([
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'campaign_id',
            'adset_id',
            'ad_id',
            'fbclid',
        ])
            ->mapWithKeys(fn (string $key): array => [$key => $request->query($key)])
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::limit(trim($value), 512, ''))
            ->all();
        $existing = $request->session()->get('marketing_attribution', []);

        if (! is_array($existing)) {
            $existing = [];
        }

        if (! array_key_exists('landing_url', $existing)) {
            $existing['landing_url'] = Str::limit($request->fullUrl(), 2000, '');
        }

        if (! array_key_exists('referrer', $existing)) {
            $referrer = trim((string) $request->headers->get('referer', ''));
            $existing['referrer'] = $referrer === '' ? null : Str::limit($referrer, 2000, '');
        }

        $request->session()->put('marketing_attribution', $existing + $attribution);
    }

    public function recordItemAdded(Request $request, string $cartItemKey): void
    {
        $this->safely(fn () => $this->sync($request, 'item_added', $cartItemKey));
    }

    public function recordItemRemoved(Request $request, string $cartItemKey, ?array $removedItem = null): void
    {
        $this->safely(function () use ($request, $cartItemKey, $removedItem): void {
            $cart = $this->ensureCart($request);
            $item = $cart->items()->where('cart_item_key', $cartItemKey)->first();

            if ($item) {
                $item->forceFill(['removed_at' => now(), 'last_activity_at' => now()])->save();
                $this->activity($cart, 'item_removed', 'تم حذف عنصر من السلة.', $item, [
                    'cart_item_key' => $cartItemKey,
                    'title' => $removedItem ? $this->titleFromSessionItem($removedItem) : $item->title_snapshot,
                ]);
            }

            $this->sync($request, null, null);
        });
    }

    public function recordCheckoutStarted(Request $request): void
    {
        $this->safely(function () use ($request): void {
            $cart = $this->sync($request, 'checkout_started', null);
            if ($cart) {
                $cart->forceFill([
                    'checkout_started_at' => $cart->checkout_started_at ?: now(),
                    'last_activity_at' => now(),
                ])->save();
            }
        });
    }

    public function recordConverted(Request $request, array $orderIds): void
    {
        $this->safely(function () use ($request, $orderIds): void {
            $cart = $this->ensureCart($request);
            $firstOrderId = collect($orderIds)->filter()->first();

            $cart->forceFill([
                'user_id' => $request->user()?->id ?: $cart->user_id,
                'status' => 'converted',
                'related_order_id' => $firstOrderId ?: null,
                'converted_at' => now(),
                'last_activity_at' => now(),
            ])->save();

            $this->activity($cart, 'order_completed', 'تم تحويل السلة إلى طلب.', null, [
                'order_ids' => array_values($orderIds),
                'order_numbers' => Order::whereIn('id', $orderIds)->pluck('order_number')->values()->all(),
            ]);
        });
    }

    public function maintainStatuses(?int $abandonedAfterMinutes = null, ?int $retentionDays = null): array
    {
        $abandonedAfterMinutes ??= (int) config('cart_tracking.abandoned_after_minutes', 360);
        $retentionDays ??= (int) config('cart_tracking.activity_retention_days', 60);

        $abandonedCutoff = now()->subMinutes(max(1, $abandonedAfterMinutes));
        $retentionCutoff = now()->subDays(max(1, $retentionDays));

        $abandoned = VisitorCart::query()
            ->where('status', 'active')
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<=', $abandonedCutoff)
            ->update([
                'status' => 'abandoned',
                'abandoned_at' => now(),
                'updated_at' => now(),
            ]);

        $expired = VisitorCart::query()
            ->whereIn('status', ['abandoned', 'expired'])
            ->where('last_activity_at', '<=', $retentionCutoff)
            ->update([
                'status' => 'expired',
                'expired_at' => now(),
                'updated_at' => now(),
            ]);

        $deletedActivities = VisitorCartActivity::query()
            ->where('created_at', '<=', $retentionCutoff)
            ->delete();

        return compact('abandoned', 'expired', 'deletedActivities');
    }

    private function sync(Request $request, ?string $activityType, ?string $focusedCartItemKey): ?VisitorCart
    {
        $cart = $this->ensureCart($request);
        $sessionItems = collect($request->session()->get('cart.items', []));

        if ($sessionItems->isEmpty()) {
            $this->recalculateTotals($cart, collect());

            return $cart;
        }

        $seenKeys = [];

        foreach ($sessionItems as $cartItemKey => $sessionItem) {
            if (! is_array($sessionItem)) {
                continue;
            }

            $seenKeys[] = (string) $cartItemKey;
            $trackedItem = $this->upsertItem($cart, (string) $cartItemKey, $sessionItem);

            if ($activityType === 'item_added' && $focusedCartItemKey === (string) $cartItemKey) {
                $this->activity($cart, 'item_added', 'تمت إضافة عنصر إلى السلة.', $trackedItem, [
                    'cart_item_key' => (string) $cartItemKey,
                    'title' => $trackedItem->title_snapshot,
                ]);
            } elseif ($trackedItem->wasChanged('quantity')) {
                $this->activity($cart, 'quantity_updated', 'تم تحديث كمية عنصر في السلة.', $trackedItem, [
                    'cart_item_key' => (string) $cartItemKey,
                    'quantity' => $trackedItem->quantity,
                ]);
            }
        }

        $cart->items()
            ->whereNull('removed_at')
            ->whereNotIn('cart_item_key', $seenKeys)
            ->get()
            ->each(function (VisitorCartItem $item) use ($cart): void {
                $item->forceFill(['removed_at' => now(), 'last_activity_at' => now()])->save();
                $this->activity($cart, 'item_removed', 'تم حذف عنصر من السلة.', $item, [
                    'cart_item_key' => $item->cart_item_key,
                    'title' => $item->title_snapshot,
                ]);
            });

        if ($activityType === 'checkout_started') {
            $this->activity($cart, 'checkout_started', 'بدأ الزائر إدخال بيانات التوصيل.');
        }

        $this->recalculateTotals($cart, $sessionItems);

        return $cart->refresh();
    }

    private function ensureCart(Request $request): VisitorCart
    {
        $identifier = $request->session()->get('cart.tracking_id');

        if (! is_string($identifier) || ! Str::isUuid($identifier)) {
            $identifier = (string) Str::uuid();
            $request->session()->put('cart.tracking_id', $identifier);
        }

        $attribution = $request->session()->get('marketing_attribution', []);
        $cart = VisitorCart::firstOrCreate(
            ['cart_identifier' => $identifier],
            [
                'user_id' => $request->user()?->id,
                'visitor_hash' => $this->visitorHash($request),
                'status' => 'active',
                'currency' => 'EGP',
                'utm_source' => $attribution['utm_source'] ?? null,
                'utm_medium' => $attribution['utm_medium'] ?? null,
                'utm_campaign' => $attribution['utm_campaign'] ?? null,
                'utm_content' => $attribution['utm_content'] ?? null,
                'utm_term' => $attribution['utm_term'] ?? null,
                'campaign_id' => $attribution['campaign_id'] ?? null,
                'adset_id' => $attribution['adset_id'] ?? null,
                'ad_id' => $attribution['ad_id'] ?? null,
                'fbclid' => $attribution['fbclid'] ?? null,
                'landing_url' => $attribution['landing_url'] ?? null,
                'referrer' => $attribution['referrer'] ?? null,
                'last_activity_at' => now(),
            ],
        );

        $updates = [
            'user_id' => $request->user()?->id ?: $cart->user_id,
            'visitor_hash' => $cart->visitor_hash ?: $this->visitorHash($request),
            'last_activity_at' => now(),
            'utm_source' => $cart->utm_source ?: ($attribution['utm_source'] ?? null),
            'utm_medium' => $cart->utm_medium ?: ($attribution['utm_medium'] ?? null),
            'utm_campaign' => $cart->utm_campaign ?: ($attribution['utm_campaign'] ?? null),
            'utm_content' => $cart->utm_content ?: ($attribution['utm_content'] ?? null),
            'utm_term' => $cart->utm_term ?: ($attribution['utm_term'] ?? null),
            'campaign_id' => $cart->campaign_id ?: ($attribution['campaign_id'] ?? null),
            'adset_id' => $cart->adset_id ?: ($attribution['adset_id'] ?? null),
            'ad_id' => $cart->ad_id ?: ($attribution['ad_id'] ?? null),
            'fbclid' => $cart->fbclid ?: ($attribution['fbclid'] ?? null),
            'landing_url' => $cart->landing_url ?: ($attribution['landing_url'] ?? null),
            'referrer' => $cart->referrer ?: ($attribution['referrer'] ?? null),
        ];

        if (! in_array($cart->status, ['converted', 'expired'], true)) {
            $updates['status'] = 'active';
            $updates['abandoned_at'] = null;
        }

        $cart->forceFill($updates)->save();

        return $cart;
    }

    private function upsertItem(VisitorCart $cart, string $cartItemKey, array $item): VisitorCartItem
    {
        $quantity = max(1, (int) ($item['quantity'] ?? 1));
        $unitPriceCents = $this->unitPriceCents($item);
        $totalPriceCents = $this->totalPriceCents($item, $unitPriceCents, $quantity);

        return VisitorCartItem::updateOrCreate(
            [
                'visitor_cart_id' => $cart->id,
                'cart_item_key' => $cartItemKey,
            ],
            [
                'item_type' => (string) ($item['item_type'] ?? 'story'),
                'story_id' => $item['story_id'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'product_variant_id' => $item['variant_id'] ?? null,
                'title_snapshot' => $this->titleFromSessionItem($item),
                'variant_snapshot' => $item['variant_snapshot'] ?? ($item['variant_name'] ?? null),
                'package_snapshot' => $item['sku'] ?? null,
                'linked_cart_item_key' => $item['linked_story_key'] ?? null,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'total_price_cents' => $totalPriceCents,
                'currency' => 'EGP',
                'item_snapshot' => $this->safeItemSnapshot($item),
                'first_added_at' => VisitorCartItem::where('visitor_cart_id', $cart->id)->where('cart_item_key', $cartItemKey)->value('first_added_at') ?: now(),
                'last_activity_at' => now(),
                'removed_at' => null,
            ],
        );
    }

    private function recalculateTotals(VisitorCart $cart, Collection $sessionItems): void
    {
        $subtotalCents = $sessionItems
            ->filter(fn ($item): bool => is_array($item))
            ->sum(fn (array $item): int => $this->totalPriceCents($item, $this->unitPriceCents($item), max(1, (int) ($item['quantity'] ?? 1))));

        $itemCount = $sessionItems->filter(fn ($item): bool => is_array($item))->count();

        $cart->forceFill([
            'item_count' => $itemCount,
            'items_subtotal_cents' => $subtotalCents,
            'cart_total_cents' => $subtotalCents,
            'first_added_at' => $cart->first_added_at ?: now(),
            'last_activity_at' => now(),
        ])->save();
    }

    private function activity(VisitorCart $cart, string $type, ?string $description = null, ?VisitorCartItem $item = null, array $metadata = []): void
    {
        $cart->activities()->create([
            'visitor_cart_item_id' => $item?->id,
            'user_id' => $cart->user_id,
            'type' => $type,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private function unitPriceCents(array $item): int
    {
        if (($item['item_type'] ?? 'story') === 'story') {
            return (int) round(((float) ($item['story_price'] ?? 0)) * 100);
        }

        return (int) ($item['unit_price_cents'] ?? round(((float) ($item['unit_price'] ?? 0)) * 100));
    }

    private function totalPriceCents(array $item, int $unitPriceCents, int $quantity): int
    {
        if (($item['item_type'] ?? 'story') === 'story') {
            return $unitPriceCents;
        }

        return (int) ($item['line_total_cents'] ?? ($unitPriceCents * $quantity));
    }

    private function titleFromSessionItem(array $item): string
    {
        return (string) match ($item['item_type'] ?? 'story') {
            'product', 'product_add_on' => $item['product_title'] ?? $item['product_name'] ?? 'منتج',
            'package' => $item['package_name'] ?? 'باقة HeroKid',
            default => $item['story_title'] ?? 'قصة مخصصة',
        };
    }

    private function safeItemSnapshot(array $item): array
    {
        $snapshot = collect($item)
            ->except(['uploaded_photos'])
            ->all();

        if (isset($snapshot['package_stories']) && is_array($snapshot['package_stories'])) {
            $snapshot['package_stories'] = array_map(
                fn (array $story): array => collect($story)->except(['uploaded_photos'])->all(),
                $snapshot['package_stories'],
            );
        }

        return $snapshot;
    }

    private function visitorHash(Request $request): ?string
    {
        $sessionId = $request->session()->getId();

        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        return hash_hmac('sha256', $sessionId, (string) config('app.key'));
    }

    private function safely(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            Log::warning('Local cart tracking failed without blocking customer flow.', [
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ]);
        }
    }
}
