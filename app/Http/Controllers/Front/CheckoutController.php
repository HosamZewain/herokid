<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityRequest;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Services\Analytics\MetaPurchaseTrackingService;
use App\Services\Cart\CartTrackingService;
use App\Services\Cart\PackageCartExpander;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\Notifications\AdminNotificationDispatcher;
use App\Services\Orders\OrderSceneTextService;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Support\Phone;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(
        Request $request,
        TemporaryPhotoUploadService $photoUploads,
        StoryPricingService $storyPricing,
        ChildIdentityEventLogger $identityEvents,
        OrderSceneTextService $sceneTexts,
        MetaPurchaseTrackingService $metaPurchaseTracking,
        PackageCartExpander $packageCartExpander,
    ) {
        $request->merge([
            'phone' => Phone::normalize($request->input('phone')),
        ]);

        $validated = $request->validate([
            'parent_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'delivery_country_id' => [
                'required',
                Rule::exists('delivery_countries', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'delivery_governorate_id' => [
                'required',
                Rule::exists('delivery_governorates', 'id')->where(fn ($query) => $query->where('active', true)),
            ],
            'city' => 'required|string|max:255',
            'street' => 'required|string|max:255',
            'address_details' => 'required|string|max:1000',
        ]);

        $country = DeliveryCountry::where('active', true)->findOrFail($validated['delivery_country_id']);
        $governorate = DeliveryGovernorate::where('active', true)
            ->where('delivery_country_id', $country->id)
            ->findOrFail($validated['delivery_governorate_id']);

        $sessionCart = session('cart.items', []);
        $cart = $packageCartExpander->expand($sessionCart);

        if ($cart === []) {
            return redirect()->route('cart.index')->with('error', 'السلة فارغة. أضف قصة واحدة على الأقل قبل تأكيد الطلب.');
        }

        $storyItems = collect($cart)->filter(fn (array $item) => ($item['item_type'] ?? 'story') === 'story');
        $productItems = collect($cart)->filter(fn (array $item) => ($item['item_type'] ?? 'story') !== 'story');
        $stories = Story::whereIn('id', $storyItems->pluck('story_id')->filter()->all())->get()->keyBy('id');
        $products = Product::with('variants')->whereIn('id', $productItems->pluck('product_id')->filter()->all())->get()->keyBy('id');

        $incompletePersonalizedProduct = $productItems->first(function (array $item) use ($products): bool {
            $product = $products->get($item['product_id'] ?? null);

            if (! $product || $product->personalization_mode !== 'collect_child_details') {
                return false;
            }

            $photos = array_values(array_filter($item['uploaded_photos'] ?? []));

            return trim((string) ($item['child_name'] ?? '')) === ''
                || ! in_array((int) ($item['child_age'] ?? 0), StoryAgeOptions::forPersonalization(), true)
                || ! in_array($item['child_gender'] ?? null, ['boy', 'girl'], true)
                || count($photos) < (int) config('photo_uploads.min_files', 2)
                || count($photos) > (int) config('photo_uploads.max_files', 3);
        });

        if ($incompletePersonalizedProduct) {
            return redirect()->route('cart.index')->with(
                'error',
                'هذا المنتج المخصص يحتاج اسم الطفل وعمره وجنسه وصورتين على الأقل. احذف المنتج من السلة ثم أضفه مرة أخرى بعد استكمال البيانات.'
            );
        }

        $subtotal = collect($cart)->sum(function (array $item) use ($stories, $storyPricing): float {
            if (($item['item_type'] ?? 'story') === 'story') {
                $story = $stories->get($item['story_id'] ?? null);

                return (float) ($item['story_price'] ?? ($story ? $storyPricing->effectivePrice($story) : 0));
            }

            return ((int) ($item['line_total_cents'] ?? 0)) / 100;
        });
        $deliveryFee = $this->deliveryFee($country, $governorate);
        $checkoutGroup = 'CHK-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
        $checkoutSessionId = $request->session()->getId();
        $attribution = $this->attributionSnapshot($request);
        $isNewCustomer = $this->isNewCustomer($validated['phone']);
        $orderIds = [];
        app(CartTrackingService::class)->recordCheckoutStarted($request);

        if (auth()->check() && ! auth()->user()->phone) {
            auth()->user()->forceFill(['phone' => $validated['phone']])->saveQuietly();
        }

        try {
            DB::transaction(function () use ($request, $cart, $storyItems, $productItems, $stories, $products, $validated, $country, $governorate, $subtotal, $deliveryFee, $checkoutGroup, $checkoutSessionId, $attribution, $photoUploads, $storyPricing, $identityEvents, $sceneTexts, &$orderIds): void {
                $itemCount = count($cart);
                $storyOrderItemIdsByCartKey = [];
                $ordersByStoryCartKey = [];
                $firstOrder = null;

                foreach ($storyItems as $cartKey => $item) {
                    $story = $stories->get($item['story_id'] ?? null);

                    if (! $story) {
                        continue;
                    }

                    $identity = null;

                    if (! empty($item['child_identity_request_id'])) {
                        $identity = ChildIdentityRequest::query()
                            ->with(['approvedAttempt', 'validPhotos'])
                            ->lockForUpdate()
                            ->findOrFail($item['child_identity_request_id']);

                        if ($identity->status === 'converted'
                            || $identity->selected_story_id !== $story->id
                            || $identity->approvedAttempt?->status !== 'succeeded'
                            || $identity->validPhotos->count() < 2) {
                            throw new \RuntimeException('Child identity cart item is no longer valid.');
                        }
                    }

                    $childName = $identity ? $identity->child_name : $item['child_name'];
                    $childAge = $identity ? $identity->child_age : $item['child_age'];
                    $childAgeRange = $identity ? $identity->age_range : ($item['child_age_range'] ?? null);
                    $childGender = $identity ? $identity->gender : ($item['child_gender'] ?? null);
                    $uploadedPhotos = $identity
                        ? $identity->validPhotos->pluck('path')->values()->all()
                        : ($item['uploaded_photos'] ?? []);
                    $currentPriceSnapshot = $storyPricing->snapshot($story);
                    $storyPrice = (float) ($item['story_price'] ?? $currentPriceSnapshot['effective_price']);
                    $storyRegularPrice = (float) ($item['story_regular_price'] ?? $currentPriceSnapshot['regular_price']);
                    $storyOfferApplied = (bool) ($item['story_offer_applied'] ?? ($storyPrice < $storyRegularPrice));
                    $storyOfferLabel = $storyOfferApplied
                        ? ($item['story_offer_label'] ?? $currentPriceSnapshot['offer_label'] ?? $storyPricing->offerLabel())
                        : null;

                    $order = Order::create([
                        'order_number' => $this->newOrderNumber(),
                        'checkout_group_key' => $checkoutGroup,
                        'user_id' => auth()->id(),
                        'order_source' => 'website',
                        'referred_by_child_identity_share_id' => $identity?->referred_by_child_identity_share_id,
                        'parent_name' => $validated['parent_name'],
                        'story_id' => $story->id,
                        'child_identity_request_id' => $identity?->id,
                        'child_identity_approved_attempt_id' => $identity?->approved_attempt_id,
                        'child_name' => $childName,
                        'child_age' => $childAge,
                        'child_gender' => $childGender,
                        'language' => $story->language,
                        'lesson' => $story->lesson_value,
                        'interests' => $item['interests'] ?? null,
                        'gift_note' => $item['gift_note'] ?? null,
                        'notes' => null,
                        'parent_notes' => $item['parent_notes'] ?? null,
                        'delivery_details' => [
                            'phone' => $validated['phone'],
                            'delivery_country_id' => $country->id,
                            'delivery_governorate_id' => $governorate->id,
                            'country' => $country->name,
                            'governorate' => $governorate->name,
                            'city' => $validated['city'],
                            'street' => $validated['street'],
                            'address_details' => $validated['address_details'],
                            'address' => trim($validated['street'].' - '.$validated['address_details']),
                            'checkout_group' => $checkoutGroup,
                            'checkout_session_id' => $checkoutSessionId,
                            'cart_item_index' => count($orderIds) + 1,
                            'cart_items_count' => $itemCount,
                            'item_price' => $storyPrice,
                            'story_regular_price' => $storyRegularPrice,
                            'story_offer_applied' => $storyOfferApplied,
                            'story_offer_label' => $storyOfferLabel,
                            'subtotal' => $subtotal,
                            'delivery_fee' => $deliveryFee,
                            'total' => $subtotal + $deliveryFee,
                            'source' => 'website',
                            'marketing_attribution' => $attribution,
                        ],
                        'uploaded_photos' => $uploadedPhotos,
                        'status' => 'new',
                    ]);

                    $order->statusLogs()->create([
                        'status' => 'new',
                        'notes' => 'تم إنشاء الطلب بنجاح وسيتم مراجعته قريباً.',
                    ]);

                    $photoUploads->markOrderAttached($identity ? [] : $uploadedPhotos, $order);

                    $storyOrderItem = $order->items()->create([
                        'item_type' => 'story',
                        'story_id' => $story->id,
                        'title' => $story->title,
                        'unit_price_cents' => (int) round($storyPrice * 100),
                        'quantity' => 1,
                        'total_price_cents' => (int) round($storyPrice * 100),
                        'personalization_mode' => 'collect_child_details',
                        'item_snapshot' => [
                            'story_slug' => $story->slug,
                            'story_language' => $story->language,
                            'lesson' => $story->lesson_value,
                            'regular_price' => $storyRegularPrice,
                            'offer_applied' => $storyOfferApplied,
                            'offer_label' => $storyOfferLabel,
                            'package' => $item['package_snapshot'] ?? null,
                            'source_context' => $item['source_context'] ?? null,
                        ],
                        'personalization_snapshot' => [
                            'cart_item_key' => $cartKey,
                            'child_name' => $childName,
                            'child_age' => $childAge,
                            'child_age_range' => $childAgeRange,
                            'child_gender' => $childGender,
                            'uploaded_photos_count' => count($uploadedPhotos),
                            'child_identity' => $identity ? [
                                'request_id' => $identity->id,
                                'request_uuid' => $identity->uuid,
                                'approved_attempt_id' => $identity->approved_attempt_id,
                                'original_photo_ids' => $identity->validPhotos->pluck('id')->all(),
                                'generation_cost_usd' => $identity->total_cost_usd,
                                'billing_currency' => 'USD',
                            ] : null,
                        ],
                    ]);

                    $storyOrderItemIdsByCartKey[$cartKey] = $storyOrderItem->id;
                    $ordersByStoryCartKey[$cartKey] = $order;
                    $firstOrder ??= $order;
                    $orderIds[] = $order->id;
                    $sceneTexts->snapshotForOrder($order, $story);

                    if ($identity) {
                        $fromStatus = $identity->status;
                        $identity->forceFill([
                            'status' => 'converted',
                            'converted_order_id' => $order->id,
                            'converted_at' => now(),
                            'last_activity_at' => now(),
                        ])->save();
                        $identityEvents->record(
                            $identity,
                            'request.converted',
                            'تم إنشاء طلب HeroKid عادي وربطه بهوية الطفل.',
                            [
                                'order_id' => $order->id,
                                'order_number' => $order->order_number,
                                'cart_item_key' => $cartKey,
                            ],
                            $identity->approvedAttempt,
                            $order,
                            actor: $request->user(),
                            source: 'checkout',
                            fromStatus: $fromStatus,
                            toStatus: 'converted',
                        );
                    }
                }

                $hasRegularStandaloneProduct = $productItems->contains(function (array $item) use ($products): bool {
                    $product = $products->get($item['product_id'] ?? null);

                    return $product
                        && empty($item['linked_story_key'])
                        && $product->personalization_mode !== 'collect_child_details';
                });

                if ($storyItems->isEmpty() && $hasRegularStandaloneProduct) {
                    $firstOrder = Order::create([
                        'order_number' => $this->newOrderNumber(),
                        'checkout_group_key' => $checkoutGroup,
                        'user_id' => auth()->id(),
                        'order_source' => 'website',
                        'parent_name' => $validated['parent_name'],
                        'story_id' => null,
                        'child_name' => null,
                        'child_age' => null,
                        'child_gender' => null,
                        'language' => null,
                        'lesson' => null,
                        'interests' => null,
                        'gift_note' => null,
                        'notes' => null,
                        'parent_notes' => null,
                        'delivery_details' => $this->deliverySnapshot($validated, $country, $governorate, $checkoutGroup, $checkoutSessionId, 1, $itemCount, $subtotal, $deliveryFee, $attribution),
                        'uploaded_photos' => [],
                        'status' => 'new',
                    ]);

                    $firstOrder->statusLogs()->create([
                        'status' => 'new',
                        'notes' => 'تم إنشاء طلب متجر بنجاح وسيتم مراجعته قريباً.',
                    ]);

                    $orderIds[] = $firstOrder->id;
                }

                foreach ($productItems as $cartKey => $item) {
                    $product = $products->get($item['product_id'] ?? null);

                    if (! $product) {
                        continue;
                    }

                    $variant = ! empty($item['variant_id'])
                        ? $product->variants->firstWhere('id', $item['variant_id'])
                        : null;
                    $quantity = max(1, (int) ($item['quantity'] ?? 1));

                    if (! $product->hasStock($quantity, $variant)) {
                        throw new \RuntimeException('Product stock is not available.');
                    }

                    $linkedStoryKey = $item['linked_story_key'] ?? null;
                    $collectsChildDetails = $product->personalization_mode === 'collect_child_details';
                    $targetOrder = null;

                    if ($linkedStoryKey && isset($ordersByStoryCartKey[$linkedStoryKey])) {
                        $targetOrder = $ordersByStoryCartKey[$linkedStoryKey];
                    } elseif ($collectsChildDetails) {
                        $uploadedPhotos = array_values($item['uploaded_photos'] ?? []);
                        $targetOrder = Order::create([
                            'order_number' => $this->newOrderNumber(),
                            'checkout_group_key' => $checkoutGroup,
                            'user_id' => auth()->id(),
                            'order_source' => 'website',
                            'parent_name' => $validated['parent_name'],
                            'story_id' => null,
                            'child_name' => $item['child_name'] ?? null,
                            'child_age' => $item['child_age'] ?? null,
                            'child_gender' => $item['child_gender'] ?? null,
                            'language' => null,
                            'lesson' => null,
                            'interests' => $item['interests'] ?? null,
                            'gift_note' => null,
                            'notes' => null,
                            'parent_notes' => null,
                            'delivery_details' => $this->deliverySnapshot(
                                $validated,
                                $country,
                                $governorate,
                                $checkoutGroup,
                                $checkoutSessionId,
                                count($orderIds) + 1,
                                $itemCount,
                                $subtotal,
                                $deliveryFee,
                                $attribution,
                            ),
                            'uploaded_photos' => $uploadedPhotos,
                            'status' => 'new',
                        ]);

                        $targetOrder->statusLogs()->create([
                            'status' => 'new',
                            'notes' => 'تم إنشاء طلب منتج مخصص بنجاح وسيتم مراجعته قريباً.',
                        ]);

                        $photoUploads->markOrderAttached($uploadedPhotos, $targetOrder);
                        $orderIds[] = $targetOrder->id;
                        $firstOrder ??= $targetOrder;
                    } else {
                        $targetOrder = $firstOrder;
                    }

                    if (! $targetOrder) {
                        continue;
                    }

                    $targetOrder->items()->create([
                        'item_type' => $linkedStoryKey ? 'product_add_on' : 'product',
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'linked_order_item_id' => $linkedStoryKey ? ($storyOrderItemIdsByCartKey[$linkedStoryKey] ?? null) : null,
                        'linked_cart_item_key' => $linkedStoryKey,
                        'title' => $item['product_title'] ?? $product->name_ar,
                        'sku' => $item['sku'] ?? $variant?->sku ?? $product->sku,
                        'unit_price_cents' => (int) ($item['unit_price_cents'] ?? $product->effectivePriceCents($variant)),
                        'quantity' => $quantity,
                        'total_price_cents' => (int) ($item['line_total_cents'] ?? (($item['unit_price_cents'] ?? $product->effectivePriceCents($variant)) * $quantity)),
                        'personalization_mode' => $product->personalization_mode,
                        'item_snapshot' => [
                            'product_slug' => $product->slug,
                            'name_ar' => $product->name_ar,
                            'name_en' => $product->name_en,
                            'fulfillment_type' => $product->fulfillment_type,
                            'purchase_mode' => $product->purchase_mode,
                            'production_lead_time_days' => $product->production_lead_time_days,
                            'package' => $item['package_snapshot'] ?? null,
                        ],
                        'variant_snapshot' => $variant ? [
                            'name_ar' => $variant->name_ar,
                            'name_en' => $variant->name_en,
                            'sku' => $variant->sku,
                        ] : null,
                        'personalization_snapshot' => $collectsChildDetails
                            ? ($item['personalization_snapshot'] ?? [
                                'child_name' => $item['child_name'] ?? null,
                                'child_age' => $item['child_age'] ?? null,
                                'child_gender' => $item['child_gender'] ?? null,
                                'interests' => $item['interests'] ?? null,
                                'uploaded_photos_count' => count($item['uploaded_photos'] ?? []),
                            ])
                            : ($item['linked_story_snapshot'] ?? null),
                    ]);

                    $this->decrementStock($product, $variant, $quantity);
                }
            });
        } catch (\RuntimeException) {
            return redirect()->route('cart.index')->with('error', 'بعض المنتجات لم تعد متاحة بالكمية المطلوبة. يرجى مراجعة السلة.');
        }

        if ($orderIds === []) {
            return redirect()->route('cart.index')->with('error', 'تعذر إنشاء الطلب لأن بعض القصص لم تعد متاحة.');
        }

        Order::query()
            ->with('story')
            ->whereKey($orderIds)
            ->get()
            ->each(fn (Order $order) => app(AdminNotificationDispatcher::class)->dispatchSafely('order.created', $order, [
                'dedupe_key' => 'order.created:'.$order->id,
                'status' => $order->status,
            ]));

        session()->forget('cart.items');
        session(['checkout.last_order_ids' => $orderIds]);
        app(CartTrackingService::class)->recordConverted($request, $orderIds);
        $metaPurchaseEvent = $metaPurchaseTracking->record($request, $orderIds, $checkoutGroup);
        if ($metaPurchaseEvent !== []) {
            session([
                'meta.purchase_event' => $metaPurchaseEvent,
                'google_ads.purchase_event' => [
                    'value' => (float) $metaPurchaseEvent['value'],
                    'currency' => 'EGP',
                    'transaction_id' => $checkoutGroup,
                    'new_customer' => $isNewCustomer,
                ],
            ]);
        }

        return redirect()->route('checkout.success');
    }

    public function success()
    {
        $orderIds = session('checkout.last_order_ids', []);
        $orders = Order::with(['story', 'items.product', 'items.variant'])->whereIn('id', $orderIds)->get();

        if ($orders->isEmpty()) {
            return redirect()->route('stories.index');
        }

        return view('front.checkout.success', [
            'orders' => $orders,
            'order' => $orders->first(),
            'metaPurchaseEvent' => session()->pull('meta.purchase_event'),
            'googleAdsPurchaseEvent' => session()->pull('google_ads.purchase_event'),
        ]);
    }

    private function isNewCustomer(string $phone): bool
    {
        $whatsAppPhone = Phone::forWhatsApp($phone);
        $phoneCandidates = array_values(array_unique(array_filter([
            Phone::normalize($phone),
            $whatsAppPhone,
            $whatsAppPhone ? '+'.$whatsAppPhone : null,
            $whatsAppPhone && str_starts_with($whatsAppPhone, '20') ? '0'.substr($whatsAppPhone, 2) : null,
        ])));

        return ! Order::query()
            ->where(function ($query) use ($phoneCandidates): void {
                if (auth()->check()) {
                    $query->where('user_id', auth()->id());

                    if ($phoneCandidates !== []) {
                        $query->orWhereIn('delivery_details->phone', $phoneCandidates);
                    }

                    return;
                }

                $query->whereIn('delivery_details->phone', $phoneCandidates);
            })
            ->exists();
    }

    private function newOrderNumber(): string
    {
        do {
            $orderNumber = 'HK-'.date('Y').'-'.strtoupper(Str::random(6));
        } while (Order::where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }

    private function deliveryFee(DeliveryCountry $country, DeliveryGovernorate $governorate): float
    {
        return max(0, (float) ($governorate->delivery_fee ?? $country->delivery_fee));
    }

    private function deliverySnapshot(array $validated, DeliveryCountry $country, DeliveryGovernorate $governorate, string $checkoutGroup, string $checkoutSessionId, int $itemIndex, int $itemCount, float $subtotal, float $deliveryFee, array $attribution): array
    {
        return [
            'phone' => $validated['phone'],
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'country' => $country->name,
            'governorate' => $governorate->name,
            'city' => $validated['city'],
            'street' => $validated['street'],
            'address_details' => $validated['address_details'],
            'address' => trim($validated['street'].' - '.$validated['address_details']),
            'checkout_group' => $checkoutGroup,
            'checkout_session_id' => $checkoutSessionId,
            'cart_item_index' => $itemIndex,
            'cart_items_count' => $itemCount,
            'item_price' => 0,
            'subtotal' => $subtotal,
            'delivery_fee' => $deliveryFee,
            'total' => $subtotal + $deliveryFee,
            'source' => 'website',
            'marketing_attribution' => $attribution,
        ];
    }

    private function attributionSnapshot(Request $request): array
    {
        $allowed = [
            'utm_source',
            'utm_medium',
            'utm_campaign',
            'utm_content',
            'utm_term',
            'campaign_id',
            'adset_id',
            'ad_id',
            'fbclid',
            'landing_url',
            'referrer',
        ];

        return collect($request->session()->get('marketing_attribution', []))
            ->only($allowed)
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::limit(trim($value), 2000, ''))
            ->all();
    }

    private function decrementStock(Product $product, ?ProductVariant $variant, int $quantity): void
    {
        if ($product->inventory_mode !== 'track_stock') {
            return;
        }

        if ($variant && $variant->stock_quantity !== null) {
            $variant->decrement('stock_quantity', $quantity);

            return;
        }

        if ($product->stock_quantity !== null) {
            $product->decrement('stock_quantity', $quantity);
        }
    }
}
