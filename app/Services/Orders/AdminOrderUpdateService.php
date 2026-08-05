<?php

namespace App\Services\Orders;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use App\Support\OrderPaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminOrderUpdateService
{
    public function __construct(
        private readonly StoryPricingService $storyPricing,
        private readonly OrderSceneTextService $sceneTexts,
        private readonly OrderPhotoUploadService $photoUploads,
        private readonly OrderPaymentService $payments,
        private readonly OrderDetailsUpdateService $details,
        private readonly OrderDeletionService $deletions,
        private readonly AdminOrderGroupService $groups,
    ) {}

    /** @return array{representative: Order, orders: Collection<int, Order>} */
    public function update(Order $representative, array $data, User $admin, Request $request): array
    {
        $disk = Storage::disk((string) config('photo_uploads.disk', 'local'));
        $beforeFiles = collect($disk->allFiles('orders/photos'))->flip();

        try {
            $result = DB::transaction(function () use ($representative, $data, $admin, $request): array {
                $groupKey = $representative->checkoutGroupKey();
                $orders = Order::query()
                    ->with(['story.sceneTemplates', 'items.product', 'items.variant'])
                    ->where('checkout_group_key', $groupKey)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($orders->isEmpty()) {
                    abort(404);
                }

                $beforeGroup = $this->groups->present($orders);
                $storyInputs = collect($data['stories'] ?? []);
                $existingStoryOrders = $orders
                    ->filter(fn (Order $order): bool => $this->isStoryOrder($order))
                    ->keyBy('id');
                $submittedExistingIds = $storyInputs
                    ->pluck('existing_order_id')
                    ->filter()
                    ->map(fn ($id): int => (int) $id);

                if ($submittedExistingIds->diff($existingStoryOrders->keys())->isNotEmpty()) {
                    throw ValidationException::withMessages([
                        'stories' => 'إحدى القصص المطلوب تعديلها لا تتبع عملية الشراء الحالية.',
                    ]);
                }

                $country = DeliveryCountry::query()->where('active', true)->find($data['delivery_country_id']);
                $governorate = DeliveryGovernorate::query()
                    ->where('active', true)
                    ->where('delivery_country_id', $country?->id)
                    ->find($data['delivery_governorate_id']);

                if (! $country || ! $governorate) {
                    throw ValidationException::withMessages([
                        'delivery_governorate_id' => 'اختر دولة ومحافظة توصيل متاحتين ومتطابقتين.',
                    ]);
                }

                $storyModels = Story::query()
                    ->whereIn('id', $storyInputs->pluck('story_id')->filter()->unique())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($storyModels->count() !== $storyInputs->pluck('story_id')->filter()->unique()->count()) {
                    throw ValidationException::withMessages(['stories' => 'إحدى القصص المختارة غير موجودة.']);
                }
                $existingStoryIds = $existingStoryOrders->pluck('story_id')->filter()->map(fn ($id): int => (int) $id);
                if ($storyModels->contains(fn (Story $story): bool => ! $story->active && ! $existingStoryIds->containsStrict((int) $story->id))) {
                    throw ValidationException::withMessages(['stories' => 'إحدى القصص الجديدة المختارة غير متاحة.']);
                }

                $removedOrders = $existingStoryOrders->except($submittedExistingIds->all());
                $hasSelectedProducts = collect($data['products'] ?? [])
                    ->contains(fn (array $input): bool => (int) ($input['quantity'] ?? 0) > 0);
                $ordersByInput = collect();

                foreach ($storyInputs as $index => $input) {
                    $story = $storyModels->get((int) $input['story_id']);
                    $existingId = (int) ($input['existing_order_id'] ?? 0);

                    if ($existingId > 0) {
                        $order = $existingStoryOrders->get($existingId);
                        $oldStoryId = (int) $order->story_id;
                        $order->forceFill(['story_id' => $story->id])->save();
                        $order->unsetRelation('story');
                        $order->setRelation('story', $story);
                        $this->upsertStoryItem($order, $story, $oldStoryId === (int) $story->id);
                        $this->details->update($order, [
                            ...$input,
                            'parent_name' => $data['parent_name'],
                            'phone' => $data['phone'],
                            'language' => $story->language,
                            'lesson' => $story->lesson_value,
                            'change_reason' => $data['change_reason'],
                            '_previous_story_id' => $oldStoryId,
                        ], $admin, $request);
                        $order = $order->fresh(['story.sceneTemplates', 'items']);
                    } else {
                        $order = $this->createStoryOrder(
                            $groupKey,
                            $story,
                            $input,
                            $orders->first(),
                            $admin,
                        );
                    }

                    $ordersByInput->put((int) $index, $order);
                }

                if ($ordersByInput->isEmpty() && $hasSelectedProducts) {
                    $carrier = $orders
                        ->reject(fn (Order $order): bool => $removedOrders->has($order->id))
                        ->first(fn (Order $order): bool => ! $this->isStoryOrder($order));

                    if (! $carrier) {
                        $carrier = $this->createProductCarrier($groupKey, $orders->first(), $data, $admin);
                    }
                }

                foreach ($removedOrders as $removedOrder) {
                    $this->deletions->deleteOrder(
                        $removedOrder,
                        'حذف القصة أثناء تعديل عملية الشراء: '.$data['change_reason'],
                        $admin,
                        $request,
                    );
                }

                $activeOrders = Order::query()
                    ->with(['items.product', 'items.variant'])
                    ->where('checkout_group_key', $groupKey)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $oldProductPrices = $this->releaseAndRemoveProductItems($activeOrders, $admin);
                $productLines = $this->resolveProductLines(
                    $data['products'] ?? [],
                    $ordersByInput->keys(),
                    $oldProductPrices,
                );

                if ($ordersByInput->isEmpty() && $productLines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'stories' => 'يجب أن تحتوي عملية الشراء على قصة أو منتج واحد على الأقل.',
                    ]);
                }

                $activeOrders = Order::query()
                    ->with(['items'])
                    ->where('checkout_group_key', $groupKey)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $carrier = $activeOrders->first(fn (Order $order): bool => ! $this->isStoryOrder($order))
                    ?: $ordersByInput->first()
                    ?: $activeOrders->first();

                foreach ($productLines as $line) {
                    $linkedIndex = $line['linked_story_index'];
                    $targetOrder = $linkedIndex !== null ? $ordersByInput->get($linkedIndex) : $carrier;
                    $linkedStoryItem = $linkedIndex !== null
                        ? $targetOrder?->items()->where('item_type', 'story')->first()
                        : null;

                    if (! $targetOrder) {
                        throw ValidationException::withMessages(['products' => 'تعذر تحديد سجل الطلب الذي سيحمل المنتج.']);
                    }

                    $this->createProductItem($targetOrder, $linkedStoryItem, $line, $linkedIndex);
                    $this->decrementStock($line['product'], $line['variant'], $line['quantity']);
                }

                $prunedOrderIds = [];
                $candidateOrders = Order::query()
                    ->with('items')
                    ->where('checkout_group_key', $groupKey)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($candidateOrders as $candidate) {
                    if ($candidateOrders->count() - count($prunedOrderIds) <= 1) {
                        break;
                    }
                    if (! $this->isStoryOrder($candidate) && $candidate->items->isEmpty()) {
                        $this->deletions->deleteOrder(
                            $candidate,
                            'إزالة سجل منتجات فارغ أثناء تعديل عملية الشراء: '.$data['change_reason'],
                            $admin,
                            $request,
                        );
                        $prunedOrderIds[] = $candidate->id;
                    }
                }

                $activeOrders = Order::query()
                    ->with(['story', 'items.product', 'items.variant'])
                    ->where('checkout_group_key', $groupKey)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $itemsSubtotalCents = (int) $activeOrders->flatMap->items->sum('total_price_cents');
                $existingGovernorateId = (int) data_get($orders->first()->delivery_details, 'delivery_governorate_id');
                $deliveryCents = $existingGovernorateId === (int) $governorate->id
                    ? (int) round(max(0, (float) data_get($orders->first()->delivery_details, 'delivery_fee', 0)) * 100)
                    : (int) round(max(0, $governorate->effectiveDeliveryFee()) * 100);
                $discountCents = (int) round(max(0, (float) ($data['discount_amount'] ?? 0)) * 100);
                $grossCents = $itemsSubtotalCents + $deliveryCents;

                if ($discountCents > $grossCents) {
                    throw ValidationException::withMessages([
                        'discount_amount' => 'لا يمكن أن يتجاوز الخصم إجمالي العناصر والتوصيل.',
                    ]);
                }

                $payment = $this->payments->resolve(
                    (string) ($data['payment_status'] ?? OrderPaymentStatus::UNPAID),
                    $data['paid_amount'] ?? null,
                    $data['payment_method'] ?? null,
                    $grossCents - $discountCents,
                    $deliveryCents,
                );
                $lineCount = $ordersByInput->count() + $productLines->count();

                foreach ($activeOrders as $position => $order) {
                    $storyItem = $order->items->firstWhere('item_type', 'story');
                    $delivery = [
                        ...(is_array($order->delivery_details) ? $order->delivery_details : []),
                        'phone' => $data['phone'],
                        'delivery_country_id' => $country->id,
                        'delivery_governorate_id' => $governorate->id,
                        'country' => $country->name,
                        'governorate' => $governorate->name,
                        'city' => $data['city'],
                        'street' => $data['street'],
                        'address_details' => $data['address_details'],
                        'address' => trim($data['street'].' - '.$data['address_details']),
                        'checkout_group' => $groupKey,
                        'cart_item_index' => $position + 1,
                        'cart_items_count' => $lineCount,
                        'item_price' => ((int) ($storyItem?->total_price_cents ?? $order->items->sum('total_price_cents'))) / 100,
                        'subtotal' => $itemsSubtotalCents / 100,
                        'delivery_fee' => $deliveryCents / 100,
                        'discount' => $discountCents / 100,
                        'total' => ($grossCents - $discountCents) / 100,
                        'payment_status' => $payment['payment_status'],
                        'payment_method' => $payment['payment_method'],
                        'paid_amount' => $payment['paid_amount_cents'] / 100,
                        'remaining_amount' => $payment['remaining_amount_cents'] / 100,
                        'order_source' => $data['order_source'],
                        'source_notes' => $data['source_notes'] ?? null,
                    ];
                    if ($storyItem) {
                        $storySnapshot = $storyItem->item_snapshot ?? [];
                        $delivery['story_regular_price'] = $storySnapshot['regular_price'] ?? ($storyItem->unit_price_cents / 100);
                        $delivery['story_offer_applied'] = (bool) ($storySnapshot['offer_applied'] ?? false);
                        $delivery['story_offer_label'] = $storySnapshot['offer_label'] ?? null;
                    }

                    $order->forceFill([
                        'parent_name' => $data['parent_name'],
                        'discount_cents' => $discountCents,
                        'discount_reason' => $discountCents > 0 ? $data['discount_reason'] : null,
                        'payment_status' => $payment['payment_status'],
                        'paid_amount_cents' => $payment['paid_amount_cents'],
                        'payment_method' => $payment['payment_method'],
                        'payment_updated_by_user_id' => $admin->id,
                        'payment_updated_at' => now(),
                        'order_source' => $data['order_source'],
                        'source_notes' => $data['source_notes'] ?? null,
                        'notes' => $data['admin_notes'] ?? null,
                        'delivery_details' => $delivery,
                    ])->save();
                }

                foreach ($storyInputs as $index => $input) {
                    $photos = $input['photos'] ?? [];
                    if ($photos !== []) {
                        $photoOrder = $ordersByInput->get((int) $index);
                        $this->photoUploads->append($photoOrder, $photos);
                        $storyItem = $photoOrder->items()->where('item_type', 'story')->first();
                        if ($storyItem) {
                            $snapshot = $storyItem->personalization_snapshot ?? [];
                            $snapshot['uploaded_photos_count'] = count($photoOrder->refresh()->uploaded_photos ?? []);
                            $storyItem->forceFill(['personalization_snapshot' => $snapshot])->save();
                        }
                    }
                }

                $activeOrders = Order::query()
                    ->with(['story:id,title,price', 'items.product:id,name_ar', 'items.variant:id,product_id,name_ar'])
                    ->where('checkout_group_key', $groupKey)
                    ->orderBy('id')
                    ->get();
                $afterGroup = $this->groups->present($activeOrders);
                $representativeOrder = $activeOrders->firstOrFail();

                AdminActivityLogger::log(
                    action: 'checkout.full_order_updated',
                    description: 'تعديل عملية الشراء كاملة: '.$groupKey,
                    subject: $representativeOrder,
                    properties: [
                        'checkout_group_key' => $groupKey,
                        'reason' => $data['change_reason'],
                        'before' => $this->auditSnapshot($beforeGroup),
                        'after' => $this->auditSnapshot($afterGroup),
                        'added_order_ids' => $activeOrders->pluck('id')->diff($orders->pluck('id'))->values()->all(),
                        'removed_order_ids' => $removedOrders->keys()->values()->all(),
                        'pruned_empty_order_ids' => $prunedOrderIds,
                        'historical_assets_preserved' => true,
                    ],
                    admin: $admin,
                    request: $request,
                );

                return ['representative' => $representativeOrder, 'orders' => $activeOrders];
            });
        } catch (\Throwable $exception) {
            $newFiles = collect($disk->allFiles('orders/photos'))
                ->reject(fn (string $path): bool => $beforeFiles->has($path));
            if ($newFiles->isNotEmpty()) {
                $disk->delete($newFiles->all());
            }

            throw $exception;
        }

        return $result;
    }

    private function createStoryOrder(
        string $groupKey,
        Story $story,
        array $input,
        Order $source,
        User $admin,
    ): Order {
        $price = $this->storyPricing->snapshot($story);
        $storyPriceCents = (int) round($price['effective_price'] * 100);
        $order = Order::create([
            'order_number' => $this->newOrderNumber(),
            'checkout_group_key' => $groupKey,
            'user_id' => $source->user_id,
            'created_by_admin_id' => $admin->id,
            'order_source' => $source->order_source,
            'source_notes' => $source->source_notes,
            'parent_name' => $source->parent_name,
            'story_id' => $story->id,
            'child_name' => $input['child_name'],
            'child_age' => $input['child_age'],
            'child_gender' => $input['child_gender'],
            'language' => $story->language,
            'lesson' => $story->lesson_value,
            'interests' => $input['interests'] ?? null,
            'gift_note' => $input['gift_note'] ?? null,
            'parent_notes' => $input['parent_notes'] ?? null,
            'delivery_details' => $source->delivery_details,
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
        $order->statusLogs()->create([
            'status' => 'new',
            'notes' => 'تمت إضافة القصة إلى عملية شراء موجودة بواسطة '.$admin->name.'.',
        ]);
        $this->createStoryItem($order, $story, $storyPriceCents, $price);
        $this->sceneTexts->snapshotForOrder($order, $story);

        return $order->fresh(['story.sceneTemplates', 'items']);
    }

    private function createProductCarrier(string $groupKey, Order $source, array $data, User $admin): Order
    {
        return Order::create([
            'order_number' => $this->newOrderNumber(),
            'checkout_group_key' => $groupKey,
            'user_id' => $source->user_id,
            'created_by_admin_id' => $admin->id,
            'order_source' => $data['order_source'],
            'source_notes' => $data['source_notes'] ?? null,
            'parent_name' => $data['parent_name'],
            'delivery_details' => $source->delivery_details,
            'uploaded_photos' => [],
            'status' => 'new',
        ]);
    }

    private function upsertStoryItem(Order $order, Story $story, bool $preservePrice): void
    {
        $item = $order->items()->where('item_type', 'story')->first();
        $price = $this->storyPricing->snapshot($story);
        $priceCents = $preservePrice && $item
            ? (int) $item->unit_price_cents
            : (int) round($price['effective_price'] * 100);

        if (! $item) {
            $this->createStoryItem($order, $story, $priceCents, $price);

            return;
        }

        $item->forceFill([
            'story_id' => $story->id,
            'title' => $story->title,
            'unit_price_cents' => $priceCents,
            'quantity' => 1,
            'total_price_cents' => $priceCents,
            'item_snapshot' => [
                ...($item->item_snapshot ?? []),
                'story_slug' => $story->slug,
                'story_language' => $story->language,
                'lesson' => $story->lesson_value,
                'regular_price' => $price['regular_price'],
                'offer_applied' => $price['offer_applied'],
                'offer_label' => $price['offer_label'],
                'updated_manually' => true,
            ],
        ])->save();
    }

    private function createStoryItem(Order $order, Story $story, int $priceCents, array $price): OrderItem
    {
        return $order->items()->create([
            'item_type' => 'story',
            'story_id' => $story->id,
            'title' => $story->title,
            'unit_price_cents' => $priceCents,
            'quantity' => 1,
            'total_price_cents' => $priceCents,
            'personalization_mode' => 'collect_child_details',
            'item_snapshot' => [
                'story_slug' => $story->slug,
                'story_language' => $story->language,
                'lesson' => $story->lesson_value,
                'regular_price' => $price['regular_price'],
                'offer_applied' => $price['offer_applied'],
                'offer_label' => $price['offer_label'],
                'created_manually' => true,
            ],
            'personalization_snapshot' => [
                'child_name' => $order->child_name,
                'child_age' => $order->child_age,
                'child_gender' => $order->child_gender,
                'uploaded_photos_count' => count($order->uploaded_photos ?? []),
                'created_manually' => true,
            ],
        ]);
    }

    private function releaseAndRemoveProductItems(Collection $orders, User $admin): array
    {
        $items = $orders->flatMap->items->whereIn('item_type', ['product', 'product_add_on']);
        $prices = [];

        foreach ($items as $item) {
            $prices[(int) $item->product_id] = [
                'variant_id' => $item->product_variant_id ? (int) $item->product_variant_id : null,
                'unit_price_cents' => (int) $item->unit_price_cents,
            ];
            $this->incrementStock($item, $admin);
        }

        if ($items->isNotEmpty()) {
            OrderItem::query()->whereKey($items->pluck('id'))->delete();
        }

        return $prices;
    }

    private function resolveProductLines(array $inputs, Collection $storyIndexes, array $oldPrices): Collection
    {
        $selected = collect($inputs)
            ->map(fn (array $input, string|int $productId): array => $input + ['product_id' => (int) $productId])
            ->filter(fn (array $input): bool => (int) ($input['quantity'] ?? 0) > 0)
            ->values();

        if ($selected->isEmpty()) {
            return collect();
        }

        $products = Product::query()
            ->whereIn('id', $selected->pluck('product_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return $selected->map(function (array $input) use ($products, $storyIndexes, $oldPrices): array {
            $product = $products->get($input['product_id']);
            if (! $product) {
                throw ValidationException::withMessages(['products' => 'أحد المنتجات المختارة غير موجود.']);
            }
            if (! $product->is_active && ! array_key_exists($product->id, $oldPrices)) {
                throw ValidationException::withMessages(['products' => 'أحد المنتجات الجديدة المختارة غير متاح.']);
            }

            $quantity = max(1, (int) $input['quantity']);
            $variant = null;
            if (! empty($input['variant_id'])) {
                $variant = ProductVariant::query()
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->find($input['variant_id']);
                if (! $variant) {
                    throw ValidationException::withMessages([
                        'products.'.$product->id.'.variant_id' => 'خيار المنتج المحدد غير صالح.',
                    ]);
                }
            } elseif ($product->variants()->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'products.'.$product->id.'.variant_id' => 'اختر خيار المنتج '.$product->name_ar.'.',
                ]);
            }

            if (! $product->hasStock($quantity, $variant)) {
                throw ValidationException::withMessages([
                    'products.'.$product->id.'.quantity' => 'الكمية المطلوبة من '.$product->name_ar.' غير متاحة.',
                ]);
            }

            $linkedIndex = null;
            if ($product->isPersonalizedAddon()) {
                $linkedIndex = (int) ($input['linked_story_index'] ?? -1);
                if (! $storyIndexes->containsStrict($linkedIndex)) {
                    throw ValidationException::withMessages([
                        'products.'.$product->id.'.linked_story_index' => 'اختر القصة/الطفل المرتبط بالمنتج '.$product->name_ar.'.',
                    ]);
                }
            }

            $old = $oldPrices[$product->id] ?? null;
            $variantId = $variant?->id;
            $unitPriceCents = $old && $old['variant_id'] === $variantId
                ? (int) $old['unit_price_cents']
                : $product->effectivePriceCents($variant);

            return [
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'total_price_cents' => $unitPriceCents * $quantity,
                'linked_story_index' => $linkedIndex,
            ];
        });
    }

    private function createProductItem(Order $order, ?OrderItem $linkedStoryItem, array $line, ?int $linkedIndex): void
    {
        $product = $line['product'];
        $variant = $line['variant'];
        $order->items()->create([
            'item_type' => $linkedStoryItem ? 'product_add_on' : 'product',
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'linked_order_item_id' => $linkedStoryItem?->id,
            'linked_cart_item_key' => $linkedStoryItem ? 'admin-story-'.$linkedIndex : null,
            'title' => $product->name_ar,
            'sku' => $variant?->sku ?? $product->sku,
            'unit_price_cents' => $line['unit_price_cents'],
            'quantity' => $line['quantity'],
            'total_price_cents' => $line['total_price_cents'],
            'personalization_mode' => $product->personalization_mode,
            'item_snapshot' => [
                'product_slug' => $product->slug,
                'name_ar' => $product->name_ar,
                'name_en' => $product->name_en,
                'fulfillment_type' => $product->fulfillment_type,
                'purchase_mode' => $product->purchase_mode,
                'updated_manually' => true,
            ],
            'variant_snapshot' => $variant ? [
                'name_ar' => $variant->name_ar,
                'name_en' => $variant->name_en,
                'sku' => $variant->sku,
            ] : null,
            'personalization_snapshot' => $linkedStoryItem ? [
                'child_name' => $order->child_name,
                'child_age' => $order->child_age,
                'child_gender' => $order->child_gender,
            ] : null,
        ]);
    }

    private function incrementStock(OrderItem $item, User $admin): void
    {
        if ($item->stock_released_at) {
            return;
        }

        $product = $item->product_id ? Product::query()->lockForUpdate()->find($item->product_id) : null;
        if (! $product || $product->inventory_mode !== 'track_stock') {
            return;
        }

        $quantity = max(1, (int) $item->quantity);
        $variant = $item->product_variant_id
            ? ProductVariant::query()->lockForUpdate()->find($item->product_variant_id)
            : null;

        if ($variant && $variant->stock_quantity !== null) {
            $variant->increment('stock_quantity', $quantity);
        } elseif ($product->stock_quantity !== null) {
            $product->increment('stock_quantity', $quantity);
        }
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

    private function isStoryOrder(Order $order): bool
    {
        return $order->story_id !== null || $order->items->contains('item_type', 'story');
    }

    private function newOrderNumber(): string
    {
        do {
            $number = 'HK-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        } while (Order::query()->where('order_number', $number)->exists());

        return $number;
    }

    private function auditSnapshot(array $group): array
    {
        return [
            'order_ids' => $group['active_orders']->pluck('id')->values()->all(),
            'order_numbers' => $group['order_numbers'],
            'story_count' => $group['story_count'],
            'product_quantity' => $group['product_quantity'],
            'add_on_quantity' => $group['add_on_quantity'],
            'items_cents' => $group['items_cents'],
            'delivery_cents' => $group['delivery_cents'],
            'discount_cents' => $group['discount_cents'],
            'total_cents' => $group['total_cents'],
            'payment_status' => $group['payment_status'],
            'paid_amount_cents' => $group['paid_amount_cents'],
        ];
    }
}
