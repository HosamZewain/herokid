<?php

namespace App\Services\Mobile;

use App\Models\ChildIdentityRequest;
use App\Models\ChildProfilePhoto;
use App\Models\CustomerAddress;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\MobileCart;
use App\Models\MobileCheckoutAttempt;
use App\Models\MobilePaymentIntent;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\Notifications\AdminNotificationDispatcher;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Pricing\StoryPricingService;
use App\Support\OrderPaymentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MobileCheckoutService
{
    /** @var array<int, array{disk:string,path:string}> */
    private array $createdFiles = [];

    public function __construct(
        private readonly MobileCartService $carts,
        private readonly StoryPricingService $storyPricing,
        private readonly OrderSceneTextService $sceneTexts,
        private readonly ChildIdentityEventLogger $identityEvents,
        private readonly AdminNotificationDispatcher $notifications,
        private readonly MobileNotificationService $mobileNotifications,
    ) {}

    public function checkout(User $user, array $data): array
    {
        $this->createdFiles = [];

        try {
            $result = DB::transaction(function () use ($user, $data): array {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                $existing = MobileCheckoutAttempt::query()
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $data['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    if ($existing->response_payload) {
                        return $existing->response_payload;
                    }

                    throw ValidationException::withMessages(['idempotency_key' => 'This checkout request is already being processed.']);
                }

                $address = CustomerAddress::query()
                    ->where('user_id', $user->id)
                    ->where('uuid', $data['address_id'])
                    ->lockForUpdate()
                    ->first();
                if (! $address) {
                    throw ValidationException::withMessages(['address_id' => 'The selected delivery address is not available.']);
                }

                $country = DeliveryCountry::query()->where('active', true)->find($address->delivery_country_id);
                $governorate = DeliveryGovernorate::query()
                    ->where('active', true)
                    ->where('delivery_country_id', $country?->id)
                    ->find($address->delivery_governorate_id);
                if (! $country || ! $governorate) {
                    throw ValidationException::withMessages(['address_id' => 'The selected delivery area is no longer available.']);
                }

                $cart = MobileCart::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if (! $cart || ! $cart->items()->exists()) {
                    throw ValidationException::withMessages(['cart' => 'The cart is empty.']);
                }

                $deliveryCents = (int) round(max(0, $governorate->effectiveDeliveryFee()) * 100);
                $cart = $this->carts->reprice($cart, $deliveryCents);
                $this->lockCommerceRows($cart);
                $cart = $this->carts->reprice($cart, $deliveryCents);

                $attempt = MobileCheckoutAttempt::query()->create([
                    'user_id' => $user->id,
                    'mobile_cart_id' => $cart->id,
                    'customer_address_id' => $address->id,
                    'idempotency_key' => $data['idempotency_key'],
                    'payment_method' => $data['payment_method'],
                    'status' => 'processing',
                ]);

                $this->recordConsents($user, $cart, $data);

                if ($data['payment_method'] !== 'cash_on_delivery') {
                    return $this->pendingPaymentResponse($attempt, $cart, $data['payment_method']);
                }

                $orders = $this->createOrders($user, $cart, $address, $country, $governorate, $attempt, $data);
                $payload = [
                    'status' => 'completed',
                    'checkout_id' => $attempt->uuid,
                    'checkout_group' => $attempt->checkout_group_key,
                    'payment' => [
                        'method' => 'cash_on_delivery',
                        'status' => 'unpaid',
                        'requires_online_action' => false,
                    ],
                    'orders' => collect($orders)->map(fn (Order $order): array => [
                        'id' => $order->id,
                        'number' => $order->order_number,
                        'status' => 'under_review',
                    ])->values()->all(),
                    'totals' => [
                        'subtotal' => $cart->subtotal_cents / 100,
                        'discount' => $cart->discount_cents / 100,
                        'delivery' => $cart->delivery_cents / 100,
                        'total' => $cart->total_cents / 100,
                        'currency' => 'EGP',
                    ],
                ];

                $attempt->forceFill(['status' => 'completed', 'response_payload' => $payload, 'completed_at' => now()])->save();
                $cart->forceFill(['status' => 'converted', 'converted_at' => now(), 'last_activity_at' => now()])->save();
                $this->redeemPromo($cart, $attempt, $user);

                return $payload;
            }, 3);
        } catch (\Throwable $exception) {
            $this->deleteCreatedFiles();
            throw $exception;
        }

        foreach ($result['orders'] ?? [] as $orderPayload) {
            $order = Order::query()->with('story')->find($orderPayload['id']);
            if ($order) {
                $this->notifications->dispatchSafely('order.created', $order, [
                    'dedupe_key' => 'order.created:'.$order->id,
                    'status' => $order->status,
                    'source' => 'mobile',
                ]);
            }
        }
        if (($result['status'] ?? null) === 'completed' && ! empty($result['orders'][0]['id'])) {
            $representative = Order::query()->with('user')->find($result['orders'][0]['id']);
            if ($representative) {
                $this->mobileNotifications->notifyOrder(
                    $representative,
                    'order.confirmed',
                    'تم تأكيد طلب HeroKid',
                    'استلمنا طلبك '.$representative->order_number.' وسنبدأ مراجعته.',
                );
            }
        }

        return $result;
    }

    private function pendingPaymentResponse(MobileCheckoutAttempt $attempt, MobileCart $cart, string $method): array
    {
        $intent = MobilePaymentIntent::query()->create([
            'user_id' => $attempt->user_id,
            'mobile_checkout_attempt_id' => $attempt->id,
            'provider' => 'not_configured',
            'method' => $method,
            'status' => 'configuration_required',
            'amount_cents' => $cart->total_cents,
            'currency' => 'EGP',
            'expires_at' => now()->addMinutes(30),
        ]);
        $payload = [
            'status' => 'payment_configuration_required',
            'checkout_id' => $attempt->uuid,
            'payment' => [
                'intent_id' => $intent->uuid,
                'method' => $method,
                'status' => $intent->status,
                'requires_online_action' => true,
                'checkout_url' => null,
                'message' => 'An online payment provider must be configured on the HeroKid backend before this method can accept payment.',
            ],
            'orders' => [],
            'totals' => [
                'subtotal' => $cart->subtotal_cents / 100,
                'discount' => $cart->discount_cents / 100,
                'delivery' => $cart->delivery_cents / 100,
                'total' => $cart->total_cents / 100,
                'currency' => 'EGP',
            ],
        ];
        $attempt->forceFill(['status' => 'payment_configuration_required', 'response_payload' => $payload, 'completed_at' => now()])->save();

        return $payload;
    }

    /** @return array<int, Order> */
    private function createOrders(User $user, MobileCart $cart, CustomerAddress $address, DeliveryCountry $country, DeliveryGovernorate $governorate, MobileCheckoutAttempt $attempt, array $data): array
    {
        $checkoutGroup = 'MOB-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        $attempt->update(['checkout_group_key' => $checkoutGroup]);
        $storyItems = $cart->items->where('item_type', 'story');
        $productItems = $cart->items->whereIn('item_type', ['product', 'product_add_on']);
        $orders = [];
        $ordersByCartItem = [];
        $storyOrderItems = [];
        $firstOrder = null;
        $itemCount = $cart->items->count();

        foreach ($storyItems as $index => $cartItem) {
            $story = Story::query()->where('active', true)->lockForUpdate()->find($cartItem->story_id);
            $child = $cartItem->childProfile;
            if (! $story || ! $child || $child->user_id !== $user->id) {
                throw ValidationException::withMessages(['cart' => 'A personalized story is no longer valid.']);
            }

            $identity = $cartItem->child_identity_request_id
                ? ChildIdentityRequest::query()->with('approvedAttempt')->lockForUpdate()->find($cartItem->child_identity_request_id)
                : null;
            if ($identity && ($identity->user_id !== $user->id || $identity->converted_at || $identity->approvedAttempt?->status !== 'succeeded')) {
                throw ValidationException::withMessages(['cart' => 'A selected Child Identity is no longer valid.']);
            }

            $price = $this->storyPricing->snapshot($story);
            $delivery = $this->deliverySnapshot($cart, $address, $country, $governorate, $checkoutGroup, count($orders) + 1, $itemCount, $data['payment_method']);
            $order = Order::query()->create([
                'order_number' => $this->newOrderNumber(),
                'checkout_group_key' => $checkoutGroup,
                'discount_cents' => $cart->discount_cents,
                'discount_reason' => $cart->promoCode?->code,
                'payment_status' => OrderPaymentStatus::UNPAID,
                'paid_amount_cents' => 0,
                'payment_method' => 'cash_on_delivery',
                'user_id' => $user->id,
                'order_source' => 'mobile',
                'source_notes' => 'HeroKid mobile application',
                'parent_name' => $address->recipient_name ?: $user->name,
                'story_id' => $story->id,
                'child_identity_request_id' => $identity?->id,
                'child_identity_approved_attempt_id' => $identity?->approved_attempt_id,
                'referred_by_child_identity_share_id' => $identity?->referred_by_child_identity_share_id,
                'child_name' => $child->name,
                'child_age' => $child->age ?? $child->birth_date?->age,
                'child_gender' => $child->gender,
                'language' => data_get($cartItem->personalization, 'language', $story->language),
                'lesson' => $story->lesson_value,
                'interests' => implode(', ', $child->interests ?? []),
                'gift_note' => data_get($cartItem->personalization, 'dedication'),
                'parent_notes' => data_get($cartItem->personalization, 'additional_instructions'),
                'delivery_details' => $delivery,
                'uploaded_photos' => [],
                'status' => 'new',
            ]);

            $photoIds = data_get($cartItem->personalization, 'photo_ids', []);
            $storedPhotos = $this->copyPhotosForOrder($user, $child->id, $photoIds, $order);
            $order->update(['uploaded_photos' => $storedPhotos]);
            $order->statusLogs()->create(['status' => 'new', 'notes' => 'تم إنشاء الطلب من تطبيق HeroKid وسيتم مراجعته قريباً.']);
            $storyOrderItem = $order->items()->create([
                'item_type' => 'story',
                'story_id' => $story->id,
                'title' => $story->title,
                'unit_price_cents' => $cartItem->unit_price_cents,
                'quantity' => 1,
                'total_price_cents' => $cartItem->unit_price_cents,
                'personalization_mode' => 'collect_child_details',
                'item_snapshot' => [
                    'story_slug' => $story->slug,
                    'story_language' => $story->language,
                    'lesson' => $story->lesson_value,
                    'regular_price' => $price['regular_price'],
                    'offer_applied' => $price['offer_applied'],
                    'offer_label' => $price['offer_label'],
                    'mobile_cart_item_id' => $cartItem->uuid,
                ],
                'personalization_snapshot' => [
                    'child_profile_uuid' => $child->uuid,
                    'child_name' => $child->name,
                    'child_age' => $child->age ?? $child->birth_date?->age,
                    'child_gender' => $child->gender,
                    'uploaded_photos_count' => count($storedPhotos),
                    'dedication' => data_get($cartItem->personalization, 'dedication'),
                    'child_identity' => $identity ? [
                        'request_id' => $identity->id,
                        'request_uuid' => $identity->uuid,
                        'approved_attempt_id' => $identity->approved_attempt_id,
                    ] : null,
                ],
            ]);

            $this->sceneTexts->snapshotForOrder($order, $story);
            $orders[] = $order;
            $ordersByCartItem[$cartItem->id] = $order;
            $storyOrderItems[$cartItem->id] = $storyOrderItem;
            $firstOrder ??= $order;

            if ($identity) {
                $fromStatus = $identity->status;
                $identity->forceFill([
                    'selected_story_id' => $story->id,
                    'status' => 'converted',
                    'converted_order_id' => $order->id,
                    'converted_at' => now(),
                    'last_activity_at' => now(),
                ])->save();
                $this->identityEvents->record(
                    $identity,
                    'request.converted',
                    'تم إنشاء طلب HeroKid من تطبيق الهاتف وربطه بهوية الطفل.',
                    ['order_id' => $order->id, 'order_number' => $order->order_number, 'mobile_cart_item_id' => $cartItem->uuid],
                    $identity->approvedAttempt,
                    $order,
                    actor: $user,
                    source: 'mobile',
                    fromStatus: $fromStatus,
                    toStatus: 'converted',
                );
            }
        }

        if ($storyItems->isEmpty() && $productItems->isNotEmpty()) {
            $firstOrder = Order::query()->create([
                'order_number' => $this->newOrderNumber(),
                'checkout_group_key' => $checkoutGroup,
                'discount_cents' => $cart->discount_cents,
                'discount_reason' => $cart->promoCode?->code,
                'payment_status' => OrderPaymentStatus::UNPAID,
                'paid_amount_cents' => 0,
                'payment_method' => 'cash_on_delivery',
                'user_id' => $user->id,
                'order_source' => 'mobile',
                'source_notes' => 'HeroKid mobile application',
                'parent_name' => $address->recipient_name ?: $user->name,
                'delivery_details' => $this->deliverySnapshot($cart, $address, $country, $governorate, $checkoutGroup, 1, $itemCount, $data['payment_method']),
                'uploaded_photos' => [],
                'status' => 'new',
            ]);
            $firstOrder->statusLogs()->create(['status' => 'new', 'notes' => 'تم إنشاء طلب المتجر من تطبيق HeroKid وسيتم مراجعته قريباً.']);
            $orders[] = $firstOrder;
        }

        foreach ($productItems as $cartItem) {
            $product = Product::query()->where('is_active', true)->lockForUpdate()->find($cartItem->product_id);
            $variant = $cartItem->product_variant_id
                ? ProductVariant::query()->where('product_id', $product?->id)->where('is_active', true)->lockForUpdate()->find($cartItem->product_variant_id)
                : null;
            if (! $product || ! $product->hasStock($cartItem->quantity, $variant)) {
                throw ValidationException::withMessages(['cart' => 'A product in the cart is no longer available.']);
            }

            $targetOrder = $cartItem->linked_mobile_cart_item_id
                ? ($ordersByCartItem[$cartItem->linked_mobile_cart_item_id] ?? null)
                : $firstOrder;
            if (! $targetOrder) {
                throw ValidationException::withMessages(['cart' => 'A product add-on has lost its linked story.']);
            }

            $targetOrder->items()->create([
                'item_type' => $cartItem->linked_mobile_cart_item_id ? 'product_add_on' : 'product',
                'product_id' => $product->id,
                'product_variant_id' => $variant?->id,
                'linked_order_item_id' => $cartItem->linked_mobile_cart_item_id ? ($storyOrderItems[$cartItem->linked_mobile_cart_item_id]?->id ?? null) : null,
                'linked_cart_item_key' => $cartItem->linkedItem?->uuid,
                'title' => $product->name_ar,
                'sku' => $variant?->sku ?? $product->sku,
                'unit_price_cents' => $cartItem->unit_price_cents,
                'quantity' => $cartItem->quantity,
                'total_price_cents' => $cartItem->total_price_cents,
                'personalization_mode' => $product->personalization_mode,
                'item_snapshot' => [
                    'product_slug' => $product->slug,
                    'name_ar' => $product->name_ar,
                    'name_en' => $product->name_en,
                    'fulfillment_type' => $product->fulfillment_type,
                    'purchase_mode' => $product->purchase_mode,
                    'production_lead_time_days' => $product->production_lead_time_days,
                    'production_prompt_template' => $product->production_prompt_template,
                    'mobile_cart_item_id' => $cartItem->uuid,
                ],
                'variant_snapshot' => $variant ? ['name_ar' => $variant->name_ar, 'name_en' => $variant->name_en, 'sku' => $variant->sku] : null,
                'personalization_snapshot' => $cartItem->linkedItem ? ['child_profile_uuid' => $cartItem->linkedItem->childProfile?->uuid] : null,
            ]);
            $this->decrementStock($product, $variant, $cartItem->quantity);
        }

        return $orders;
    }

    private function lockCommerceRows(MobileCart $cart): void
    {
        $cart->load(['items.childProfile', 'items.linkedItem.childProfile', 'promoCode']);
        Story::query()->whereIn('id', $cart->items->pluck('story_id')->filter())->lockForUpdate()->get();
        Product::query()->whereIn('id', $cart->items->pluck('product_id')->filter())->lockForUpdate()->get();
        ProductVariant::query()->whereIn('id', $cart->items->pluck('product_variant_id')->filter())->lockForUpdate()->get();
        if ($cart->mobile_promo_code_id) {
            $cart->setRelation('promoCode', $cart->promoCode()->lockForUpdate()->first());
        }
    }

    private function recordConsents(User $user, MobileCart $cart, array $data): void
    {
        $profileIds = $cart->items->pluck('child_profile_id')->filter()->unique();
        foreach ($profileIds as $profileId) {
            DB::table('consent_records')->insert([
                'user_id' => $user->id,
                'child_profile_id' => $profileId,
                'consent_type' => 'order_image_processing',
                'document_version' => $data['consent_document_version'],
                'granted' => true,
                'recorded_at' => now(),
                'source' => 'mobile',
                'metadata' => json_encode(['cart_uuid' => $cart->uuid], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('consent_records')->insert([
            'user_id' => $user->id,
            'child_profile_id' => null,
            'consent_type' => 'checkout_terms',
            'document_version' => $data['terms_document_version'],
            'granted' => true,
            'recorded_at' => now(),
            'source' => 'mobile',
            'metadata' => json_encode(['cart_uuid' => $cart->uuid], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, string> $photoUuids */
    private function copyPhotosForOrder(User $user, int $childProfileId, array $photoUuids, Order $order): array
    {
        $photos = ChildProfilePhoto::query()
            ->whereHas('childProfile', fn ($query) => $query->where('user_id', $user->id))
            ->where('child_profile_id', $childProfileId)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->whereIn('uuid', $photoUuids)
            ->get();
        if ($photos->count() !== count(array_unique($photoUuids)) || ! in_array($photos->count(), [2, 3], true)) {
            throw ValidationException::withMessages(['cart' => 'Two or three selected child photos are required.']);
        }

        $targetDiskName = (string) config('photo_uploads.disk', 'local');
        $targetDisk = Storage::disk($targetDiskName);
        $paths = [];
        foreach ($photos as $photo) {
            $source = Storage::disk($photo->disk)->readStream($photo->path);
            if (! is_resource($source)) {
                throw ValidationException::withMessages(['cart' => 'A selected child photo could not be read.']);
            }
            $extension = pathinfo($photo->path, PATHINFO_EXTENSION) ?: 'jpg';
            $path = 'orders/photos/'.$order->id.'/mobile/'.Str::uuid().'.'.$extension;
            try {
                if (! $targetDisk->put($path, $source)) {
                    throw ValidationException::withMessages(['cart' => 'A selected child photo could not be copied.']);
                }
            } finally {
                fclose($source);
            }
            $paths[] = $path;
            $this->createdFiles[] = ['disk' => $targetDiskName, 'path' => $path];
        }

        return $paths;
    }

    private function deliverySnapshot(MobileCart $cart, CustomerAddress $address, DeliveryCountry $country, DeliveryGovernorate $governorate, string $checkoutGroup, int $itemIndex, int $itemCount, string $paymentMethod): array
    {
        return [
            'phone' => $address->phone,
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'country' => $country->name,
            'governorate' => $governorate->name,
            'city' => $address->city,
            'street' => $address->street,
            'address_details' => $address->details,
            'delivery_instructions' => $address->delivery_instructions,
            'address' => trim($address->street.' - '.$address->details),
            'checkout_group' => $checkoutGroup,
            'checkout_session_id' => null,
            'cart_item_index' => $itemIndex,
            'cart_items_count' => $itemCount,
            'subtotal' => $cart->subtotal_cents / 100,
            'delivery_fee' => $cart->delivery_cents / 100,
            'discount' => $cart->discount_cents / 100,
            'total' => $cart->total_cents / 100,
            'payment_required' => $paymentMethod !== 'cash_on_delivery',
            'payment_status' => OrderPaymentStatus::UNPAID,
            'payment_method' => $paymentMethod,
            'source' => 'mobile',
        ];
    }

    private function redeemPromo(MobileCart $cart, MobileCheckoutAttempt $attempt, User $user): void
    {
        if (! $cart->promoCode || $cart->discount_cents <= 0) {
            return;
        }
        DB::table('mobile_promo_code_redemptions')->insert([
            'mobile_promo_code_id' => $cart->promoCode->id,
            'user_id' => $user->id,
            'mobile_checkout_attempt_id' => $attempt->id,
            'discount_cents' => $cart->discount_cents,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cart->promoCode->increment('used_count');
    }

    private function decrementStock(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        if ($product->inventory_mode !== 'track_stock') {
            return;
        }
        if ($variant && $variant->stock_quantity !== null) {
            $variant->decrement('stock_quantity', $quantity);
        } elseif ($product->stock_quantity !== null) {
            $product->decrement('stock_quantity', $quantity);
        }
    }

    private function newOrderNumber(): string
    {
        do {
            $number = 'HK-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }

    private function deleteCreatedFiles(): void
    {
        foreach ($this->createdFiles as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
        $this->createdFiles = [];
    }
}
