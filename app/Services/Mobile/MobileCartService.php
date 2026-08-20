<?php

namespace App\Services\Mobile;

use App\Models\ChildIdentityRequest;
use App\Models\ChildProfile;
use App\Models\MobileCart;
use App\Models\MobileCartItem;
use App\Models\MobilePromoCode;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use App\Services\Pricing\StoryPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileCartService
{
    public function __construct(private readonly StoryPricingService $storyPricing) {}

    public function activeCart(User $user): MobileCart
    {
        return DB::transaction(function () use ($user): MobileCart {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $cart = MobileCart::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->latest('id')
                ->first();

            if (! $cart) {
                $cart = MobileCart::query()->create([
                    'user_id' => $user->id,
                    'status' => 'active',
                    'currency' => 'EGP',
                    'last_activity_at' => now(),
                ]);
            }

            return $this->reprice($cart);
        });
    }

    public function add(User $user, array $data): MobileCart
    {
        return DB::transaction(function () use ($user, $data): MobileCart {
            $cart = $this->lockedActiveCart($user);
            $existing = $cart->items()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                return $this->reprice($cart);
            }

            match ($data['item_type']) {
                'story' => $this->addStory($cart, $user, $data),
                'product' => $this->addProduct($cart, $user, $data),
                default => throw ValidationException::withMessages(['item_type' => 'Unsupported cart item type.']),
            };

            return $this->reprice($cart);
        });
    }

    public function updateQuantity(User $user, string $itemUuid, int $quantity): MobileCart
    {
        return DB::transaction(function () use ($user, $itemUuid, $quantity): MobileCart {
            $cart = $this->lockedActiveCart($user);
            $item = $cart->items()->where('uuid', $itemUuid)->lockForUpdate()->firstOrFail();

            if ($item->item_type === 'story' && $quantity !== 1) {
                throw ValidationException::withMessages(['quantity' => 'Duplicate the personalized story to create another copy.']);
            }

            if ($item->product_id) {
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $variant = $item->product_variant_id
                    ? ProductVariant::query()->lockForUpdate()->findOrFail($item->product_variant_id)
                    : null;
                if (! $product->hasStock($quantity, $variant)) {
                    throw ValidationException::withMessages(['quantity' => 'The requested quantity is no longer available.']);
                }
            }

            $item->update(['quantity' => $quantity]);

            return $this->reprice($cart);
        });
    }

    public function remove(User $user, string $itemUuid): MobileCart
    {
        return DB::transaction(function () use ($user, $itemUuid): MobileCart {
            $cart = $this->lockedActiveCart($user);
            $cart->items()->where('uuid', $itemUuid)->firstOrFail()->delete();

            return $this->reprice($cart);
        });
    }

    public function duplicate(User $user, string $itemUuid, string $idempotencyKey): MobileCart
    {
        return DB::transaction(function () use ($user, $itemUuid, $idempotencyKey): MobileCart {
            $cart = $this->lockedActiveCart($user);
            if ($cart->items()->where('idempotency_key', $idempotencyKey)->exists()) {
                return $this->reprice($cart);
            }

            $item = $cart->items()->where('uuid', $itemUuid)->firstOrFail();
            if ($item->item_type === 'product_add_on') {
                throw ValidationException::withMessages(['item' => 'Duplicate the linked personalized story instead.']);
            }

            $copy = $item->replicate(['uuid']);
            $copy->uuid = null;
            $copy->idempotency_key = $idempotencyKey;
            $copy->save();

            return $this->reprice($cart);
        });
    }

    public function applyPromo(User $user, string $code): MobileCart
    {
        return DB::transaction(function () use ($user, $code): MobileCart {
            $cart = $this->lockedActiveCart($user);
            $promo = MobilePromoCode::query()->whereRaw('UPPER(code) = ?', [mb_strtoupper(trim($code))])->first();
            $this->assertPromoAvailable($promo, $cart, $user);
            $cart->update(['mobile_promo_code_id' => $promo->id]);

            return $this->reprice($cart);
        });
    }

    public function removePromo(User $user): MobileCart
    {
        return DB::transaction(function () use ($user): MobileCart {
            $cart = $this->lockedActiveCart($user);
            $cart->update(['mobile_promo_code_id' => null]);

            return $this->reprice($cart);
        });
    }

    public function payload(MobileCart $cart): array
    {
        $cart->loadMissing(['items.childProfile', 'promoCode']);

        return [
            'id' => $cart->uuid,
            'status' => $cart->status,
            'currency' => $cart->currency,
            'items' => $cart->items->map(fn (MobileCartItem $item): array => [
                'id' => $item->uuid,
                'type' => $item->item_type,
                'story_id' => $item->story_id,
                'product_id' => $item->product_id,
                'variant_id' => $item->product_variant_id,
                'linked_item_id' => $item->linkedItem?->uuid,
                'title' => $item->title,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price_cents / 100,
                'line_total' => $item->total_price_cents / 100,
                'child' => $item->childProfile ? [
                    'id' => $item->childProfile->uuid,
                    'name' => $item->childProfile->name,
                ] : null,
                'personalization' => collect($item->personalization ?? [])->except(['photo_ids'])->all(),
            ])->values(),
            'promo_code' => $cart->promoCode?->code,
            'totals' => [
                'subtotal' => $cart->subtotal_cents / 100,
                'discount' => $cart->discount_cents / 100,
                'delivery' => $cart->delivery_cents / 100,
                'total' => $cart->total_cents / 100,
            ],
            'last_activity_at' => $cart->last_activity_at?->toISOString(),
        ];
    }

    public function reprice(MobileCart $cart, int $deliveryCents = 0): MobileCart
    {
        $cart->load(['items.story', 'items.product', 'items.variant', 'items.linkedItem', 'items.childProfile', 'promoCode']);
        $subtotal = 0;

        foreach ($cart->items as $item) {
            if ($item->item_type === 'story') {
                if (! $item->story?->active) {
                    throw ValidationException::withMessages(['cart' => 'A story in the cart is no longer available.']);
                }
                $price = (int) round($this->storyPricing->effectivePrice($item->story) * 100);
            } else {
                if (! $item->product?->is_active || ($item->variant && ! $item->variant->is_active)) {
                    throw ValidationException::withMessages(['cart' => 'A product in the cart is no longer available.']);
                }
                if (! $item->product->hasStock($item->quantity, $item->variant)) {
                    throw ValidationException::withMessages(['cart' => 'A product quantity in the cart is no longer available.']);
                }
                $price = $item->product->effectivePriceCents($item->variant);
            }

            $total = $price * $item->quantity;
            if ($item->unit_price_cents !== $price || $item->total_price_cents !== $total) {
                $item->forceFill(['unit_price_cents' => $price, 'total_price_cents' => $total])->save();
            }
            $subtotal += $total;
        }

        $discount = 0;
        if ($cart->promoCode) {
            try {
                $this->assertPromoAvailable($cart->promoCode, $cart, $cart->user);
                $discount = $cart->promoCode->discountFor($subtotal);
            } catch (ValidationException) {
                $cart->mobile_promo_code_id = null;
            }
        }

        $cart->forceFill([
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'delivery_cents' => max(0, $deliveryCents),
            'total_cents' => max(0, $subtotal + max(0, $deliveryCents) - $discount),
            'last_activity_at' => now(),
        ])->save();

        return $cart->fresh(['items.story', 'items.product', 'items.variant', 'items.linkedItem', 'items.childProfile', 'promoCode']);
    }

    private function lockedActiveCart(User $user): MobileCart
    {
        User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
        $cart = MobileCart::query()->where('user_id', $user->id)->where('status', 'active')->latest('id')->lockForUpdate()->first();

        return $cart ?: MobileCart::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'currency' => 'EGP',
            'last_activity_at' => now(),
        ]);
    }

    private function addStory(MobileCart $cart, User $user, array $data): void
    {
        $story = Story::query()->where('active', true)->find($data['story_id']);
        if (! $story) {
            throw ValidationException::withMessages(['story_id' => 'The selected story is not available.']);
        }

        $child = ChildProfile::query()->where('user_id', $user->id)->where('is_active', true)->where('uuid', $data['child_profile_id'])->first();
        if (! $child) {
            throw ValidationException::withMessages(['child_profile_id' => 'The selected child profile is not available.']);
        }

        $photoIds = collect($data['child_photo_ids'] ?? [])->unique()->values();
        $photos = $child->activePhotos()->whereIn('uuid', $photoIds)->get();
        if ($photos->count() !== $photoIds->count() || ! in_array($photos->count(), [2, 3], true)) {
            throw ValidationException::withMessages(['child_photo_ids' => 'Select two or three reusable child photos.']);
        }

        $identity = null;
        if (! empty($data['child_identity_id'])) {
            $identity = ChildIdentityRequest::query()
                ->with('approvedAttempt')
                ->where('user_id', $user->id)
                ->where('child_profile_id', $child->id)
                ->where('uuid', $data['child_identity_id'])
                ->first();
            if (! $identity || ! $identity->approved_attempt_id || $identity->approvedAttempt?->status !== 'succeeded' || $identity->converted_at) {
                throw ValidationException::withMessages(['child_identity_id' => 'The selected Child Identity is not approved or is no longer reusable.']);
            }
            if ($identity->selected_story_id && $identity->selected_story_id !== $story->id) {
                throw ValidationException::withMessages(['child_identity_id' => 'This Child Identity is already linked to another story.']);
            }
        }

        $price = (int) round($this->storyPricing->effectivePrice($story) * 100);
        $cart->items()->create([
            'item_type' => 'story',
            'story_id' => $story->id,
            'child_profile_id' => $child->id,
            'child_identity_request_id' => $identity?->id,
            'title' => $story->title,
            'unit_price_cents' => $price,
            'quantity' => 1,
            'total_price_cents' => $price,
            'personalization' => [
                'photo_ids' => $photos->pluck('uuid')->all(),
                'dedication' => $data['dedication'] ?? null,
                'additional_instructions' => $data['additional_instructions'] ?? null,
                'language' => $data['language'] ?? $child->preferred_language ?? $story->language,
                'theme' => $data['theme'] ?? null,
            ],
            'idempotency_key' => $data['idempotency_key'],
        ]);
    }

    private function addProduct(MobileCart $cart, User $user, array $data): void
    {
        $product = Product::query()->publiclyVisible()->find($data['product_id']);
        if (! $product) {
            throw ValidationException::withMessages(['product_id' => 'The selected product is not available.']);
        }

        $variant = null;
        if (! empty($data['variant_id'])) {
            $variant = $product->activeVariants()->find($data['variant_id']);
            if (! $variant) {
                throw ValidationException::withMessages(['variant_id' => 'The selected product option is not available.']);
            }
        } elseif ($product->activeVariants()->exists()) {
            throw ValidationException::withMessages(['variant_id' => 'Select a product option.']);
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        if (! $product->hasStock($quantity, $variant)) {
            throw ValidationException::withMessages(['quantity' => 'The requested quantity is not available.']);
        }

        $linkedItem = null;
        if ($product->isPersonalizedAddon()) {
            $linkedItem = $cart->items()->where('uuid', $data['linked_item_id'] ?? null)->where('item_type', 'story')->first();
            if (! $linkedItem) {
                throw ValidationException::withMessages(['linked_item_id' => 'Select the personalized story this add-on belongs to.']);
            }
        }

        $price = $product->effectivePriceCents($variant);
        $cart->items()->create([
            'item_type' => $linkedItem ? 'product_add_on' : 'product',
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'linked_mobile_cart_item_id' => $linkedItem?->id,
            'child_profile_id' => $linkedItem?->child_profile_id,
            'title' => $product->name_ar,
            'sku' => $variant?->sku ?? $product->sku,
            'unit_price_cents' => $price,
            'quantity' => $quantity,
            'total_price_cents' => $price * $quantity,
            'personalization' => $linkedItem ? ['inherited_from_item_id' => $linkedItem->uuid] : null,
            'idempotency_key' => $data['idempotency_key'],
        ]);
    }

    private function assertPromoAvailable(?MobilePromoCode $promo, MobileCart $cart, User $user): void
    {
        if (! $promo || ! $promo->is_active || ($promo->starts_at && $promo->starts_at->isFuture()) || ($promo->ends_at && $promo->ends_at->isPast())) {
            throw ValidationException::withMessages(['code' => 'This promotional code is not available.']);
        }
        if ($cart->subtotal_cents < $promo->minimum_subtotal_cents) {
            throw ValidationException::withMessages(['code' => 'The cart does not meet the minimum value for this code.']);
        }
        if ($promo->usage_limit !== null && $promo->used_count >= $promo->usage_limit) {
            throw ValidationException::withMessages(['code' => 'This promotional code has reached its usage limit.']);
        }
        if ($promo->per_user_limit !== null) {
            $uses = DB::table('mobile_promo_code_redemptions')->where('mobile_promo_code_id', $promo->id)->where('user_id', $user->id)->count();
            if ($uses >= $promo->per_user_limit) {
                throw ValidationException::withMessages(['code' => 'You have already used this promotional code.']);
            }
        }
    }
}
