<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Services\Cart\CartTrackingService;
use App\Services\Notifications\AdminNotificationDispatcher;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function store(Request $request, TemporaryPhotoUploadService $photoUploads, StoryPricingService $storyPricing)
    {
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

        $cart = session('cart.items', []);

        if ($cart === []) {
            return redirect()->route('cart.index')->with('error', 'السلة فارغة. أضف قصة واحدة على الأقل قبل تأكيد الطلب.');
        }

        $storyItems = collect($cart)->filter(fn (array $item) => ($item['item_type'] ?? 'story') === 'story');
        $productItems = collect($cart)->filter(fn (array $item) => ($item['item_type'] ?? 'story') !== 'story');
        $stories = Story::whereIn('id', $storyItems->pluck('story_id')->filter()->all())->get()->keyBy('id');
        $products = Product::with('variants')->whereIn('id', $productItems->pluck('product_id')->filter()->all())->get()->keyBy('id');
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
        $orderIds = [];
        app(CartTrackingService::class)->recordCheckoutStarted($request);

        if (auth()->check() && ! auth()->user()->phone) {
            auth()->user()->forceFill(['phone' => $validated['phone']])->saveQuietly();
        }

        try {
            DB::transaction(function () use ($cart, $storyItems, $productItems, $stories, $products, $validated, $country, $governorate, $subtotal, $deliveryFee, $checkoutGroup, $checkoutSessionId, $photoUploads, $storyPricing, &$orderIds): void {
                $itemCount = count($cart);
                $storyOrderItemIdsByCartKey = [];
                $ordersByStoryCartKey = [];
                $firstOrder = null;

                foreach ($storyItems as $cartKey => $item) {
                    $story = $stories->get($item['story_id'] ?? null);

                    if (! $story) {
                        continue;
                    }

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
                        'parent_name' => $validated['parent_name'],
                        'story_id' => $story->id,
                        'child_name' => $item['child_name'],
                        'child_age' => $item['child_age'],
                        'child_gender' => $item['child_gender'],
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
                        ],
                        'uploaded_photos' => $item['uploaded_photos'] ?? [],
                        'status' => 'new',
                    ]);

                    $order->statusLogs()->create([
                        'status' => 'new',
                        'notes' => 'تم إنشاء الطلب بنجاح وسيتم مراجعته قريباً.',
                    ]);

                    $photoUploads->markOrderAttached($item['uploaded_photos'] ?? [], $order);

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
                        ],
                        'personalization_snapshot' => [
                            'cart_item_key' => $cartKey,
                            'child_name' => $item['child_name'] ?? null,
                            'child_age' => $item['child_age'] ?? null,
                            'child_gender' => $item['child_gender'] ?? null,
                            'uploaded_photos_count' => count($item['uploaded_photos'] ?? []),
                        ],
                    ]);

                    $storyOrderItemIdsByCartKey[$cartKey] = $storyOrderItem->id;
                    $ordersByStoryCartKey[$cartKey] = $order;
                    $firstOrder ??= $order;
                    $orderIds[] = $order->id;
                }

                if ($storyItems->isEmpty() && $productItems->isNotEmpty()) {
                    $firstOrder = Order::create([
                        'order_number' => $this->newOrderNumber(),
                        'checkout_group_key' => $checkoutGroup,
                        'user_id' => auth()->id(),
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
                        'delivery_details' => $this->deliverySnapshot($validated, $country, $governorate, $checkoutGroup, $checkoutSessionId, 1, $itemCount, $subtotal, $deliveryFee),
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
                    $targetOrder = $linkedStoryKey && isset($ordersByStoryCartKey[$linkedStoryKey])
                        ? $ordersByStoryCartKey[$linkedStoryKey]
                        : $firstOrder;

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
                        ],
                        'variant_snapshot' => $variant ? [
                            'name_ar' => $variant->name_ar,
                            'name_en' => $variant->name_en,
                            'sku' => $variant->sku,
                        ] : null,
                        'personalization_snapshot' => $item['linked_story_snapshot'] ?? null,
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
        ]);
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

    private function deliverySnapshot(array $validated, DeliveryCountry $country, DeliveryGovernorate $governorate, string $checkoutGroup, string $checkoutSessionId, int $itemIndex, int $itemCount, float $subtotal, float $deliveryFee): array
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
        ];
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
