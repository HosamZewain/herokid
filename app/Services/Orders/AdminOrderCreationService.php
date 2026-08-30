<?php

namespace App\Services\Orders;

use App\Models\DeliveryCountry;
use App\Models\DeliveryGovernorate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Story;
use App\Models\User;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\OrderPhotoUploadService;
use App\Support\AdminActivityLogger;
use App\Support\OrderPaymentStatus;
use App\Support\ProductPersonalizationSchema;
use App\Support\ProductVariantSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminOrderCreationService
{
    public function __construct(
        private readonly StoryPricingService $storyPricing,
        private readonly OrderSceneTextService $sceneTexts,
        private readonly OrderPhotoUploadService $photoUploads,
        private readonly OrderPaymentService $payments,
    ) {}

    /** @return array{representative: Order, orders: array<int, Order>} */
    public function create(array $data, User $admin, Request $request): array
    {
        $createdOrderIds = [];

        try {
            $result = DB::transaction(function () use ($data, $admin, &$createdOrderIds): array {
                $country = DeliveryCountry::query()->where('active', true)->find($data['delivery_country_id']);

                if (! $country) {
                    throw ValidationException::withMessages([
                        'delivery_country_id' => 'الدولة المختارة غير متاحة.',
                    ]);
                }

                $governorate = DeliveryGovernorate::query()
                    ->where('active', true)
                    ->where('delivery_country_id', $country->id)
                    ->find($data['delivery_governorate_id']);

                if (! $governorate) {
                    throw ValidationException::withMessages([
                        'delivery_governorate_id' => 'المحافظة المختارة لا تتبع الدولة المحددة أو لم تعد متاحة.',
                    ]);
                }

                // Preserve submitted indexes so a personalized add-on always remains
                // linked to the intended story even after a validation redirect.
                $storyInputs = collect($data['stories']);
                $storyIndexes = $storyInputs->keys()->map(fn (string|int $index): int => (int) $index)->values();
                $stories = Story::query()
                    ->where('active', true)
                    ->whereIn('id', $storyInputs->pluck('story_id')->all())
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($stories->count() !== $storyInputs->pluck('story_id')->unique()->count()) {
                    throw ValidationException::withMessages(['stories' => 'إحدى القصص المختارة لم تعد متاحة.']);
                }

                $productLines = $this->resolveProductLines($data['products'] ?? [], $storyIndexes);
                if ($storyInputs->isEmpty() && $productLines->isEmpty()) {
                    throw ValidationException::withMessages([
                        'stories' => 'أضف قصة أو منتجًا واحدًا على الأقل إلى الطلب.',
                    ]);
                }
                $storyPrices = $storyInputs->map(function (array $input) use ($stories): array {
                    $story = $stories->get($input['story_id']);

                    return $this->storyPricing->snapshot($story);
                });
                $itemsSubtotalCents = (int) $storyPrices->sum(fn (array $price): int => (int) round($price['effective_price'] * 100))
                    + (int) $productLines->sum('total_price_cents');
                $deliveryCents = (int) round(max(0, $governorate->effectiveDeliveryFee()) * 100);
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

                $checkoutGroup = 'CHK-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
                $lineCount = $storyInputs->count() + $productLines->count();
                $orders = [];
                $storyOrderItems = [];

                foreach ($storyInputs as $position => $input) {
                    $index = (int) $position;
                    $story = $stories->get($input['story_id']);
                    $price = $storyPrices->get($position);
                    $storyPriceCents = (int) round($price['effective_price'] * 100);
                    $delivery = [
                        'phone' => $data['phone'],
                        'delivery_country_id' => $country->id,
                        'delivery_governorate_id' => $governorate->id,
                        'country' => $country->name,
                        'governorate' => $governorate->name,
                        'city' => $data['city'],
                        'street' => $data['street'],
                        'address_details' => $data['address_details'],
                        'address' => trim($data['street'].' - '.$data['address_details']),
                        'checkout_group' => $checkoutGroup,
                        'checkout_session_id' => null,
                        'cart_item_index' => count($orders) + 1,
                        'cart_items_count' => $lineCount,
                        'item_price' => $storyPriceCents / 100,
                        'story_regular_price' => $price['regular_price'],
                        'story_offer_applied' => $price['offer_applied'],
                        'story_offer_label' => $price['offer_label'],
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
                        'created_manually' => true,
                    ];

                    $order = Order::create([
                        'order_number' => $this->newOrderNumber(),
                        'checkout_group_key' => $checkoutGroup,
                        'discount_cents' => $discountCents,
                        'discount_reason' => $discountCents > 0 ? $data['discount_reason'] : null,
                        'payment_status' => $payment['payment_status'],
                        'paid_amount_cents' => $payment['paid_amount_cents'],
                        'payment_method' => $payment['payment_method'],
                        'payment_updated_by_user_id' => $payment['payment_status'] !== OrderPaymentStatus::UNPAID ? $admin->id : null,
                        'payment_updated_at' => $payment['payment_status'] !== OrderPaymentStatus::UNPAID ? now() : null,
                        'user_id' => null,
                        'created_by_admin_id' => $admin->id,
                        'order_source' => $data['order_source'],
                        'source_notes' => $data['source_notes'] ?? null,
                        'parent_name' => $data['parent_name'],
                        'story_id' => $story->id,
                        'child_name' => $input['child_name'],
                        'child_age' => $input['child_age'],
                        'child_gender' => $input['child_gender'],
                        'language' => $story->language,
                        'lesson' => $story->lesson_value,
                        'interests' => $input['interests'] ?? null,
                        'gift_note' => $input['gift_note'] ?? null,
                        'notes' => $data['admin_notes'] ?? null,
                        'parent_notes' => $input['parent_notes'] ?? null,
                        'delivery_details' => $delivery,
                        'uploaded_photos' => [],
                        'status' => 'new',
                    ]);
                    $createdOrderIds[] = $order->id;

                    $order->statusLogs()->create([
                        'status' => 'new',
                        'notes' => 'تم إنشاء الطلب يدويًا من لوحة الإدارة بواسطة '.$admin->name.'.',
                    ]);

                    $storyOrderItem = $order->items()->create([
                        'item_type' => 'story',
                        'story_id' => $story->id,
                        'title' => $story->title,
                        'unit_price_cents' => $storyPriceCents,
                        'quantity' => 1,
                        'total_price_cents' => $storyPriceCents,
                        'personalization_mode' => 'collect_child_details',
                        'item_snapshot' => [
                            'story_slug' => $story->slug,
                            'story_language' => $story->language,
                            'lesson' => $story->lesson_value,
                            'regular_price' => $price['regular_price'],
                            'offer_applied' => $price['offer_applied'],
                            'offer_label' => $price['offer_label'],
                            'created_manually' => true,
                            ...(! empty($data['_package_snapshot']) ? ['package' => $data['_package_snapshot']] : []),
                        ],
                        'personalization_snapshot' => [
                            'child_name' => $input['child_name'],
                            'child_age' => $input['child_age'],
                            'child_gender' => $input['child_gender'],
                            'uploaded_photos_count' => count($input['photos']),
                            'created_manually' => true,
                        ],
                    ]);

                    $orders[$index] = $order;
                    $storyOrderItems[$index] = $storyOrderItem;
                    $this->sceneTexts->snapshotForOrder($order, $story);
                }

                $firstStoryIndex = $storyIndexes->first();
                $firstOrder = $firstStoryIndex !== null ? $orders[(int) $firstStoryIndex] : null;

                $hasRegularProduct = $productLines->contains(fn (array $line): bool => $line['linked_story_index'] === null
                    && $line['product']->personalization_mode !== 'collect_child_details'
                );

                if (! $firstOrder && $hasRegularProduct) {
                    $firstRegularLine = $productLines->first(fn (array $line): bool => $line['linked_story_index'] === null
                        && $line['product']->personalization_mode !== 'collect_child_details'
                    );
                    $firstOrder = $this->createProductOrder(
                        data: $data,
                        admin: $admin,
                        country: $country,
                        governorate: $governorate,
                        checkoutGroup: $checkoutGroup,
                        lineCount: $lineCount,
                        itemPriceCents: (int) $firstRegularLine['total_price_cents'],
                        itemsSubtotalCents: $itemsSubtotalCents,
                        deliveryCents: $deliveryCents,
                        discountCents: $discountCents,
                        payment: $payment,
                        personalizationSnapshot: [],
                    );
                    $createdOrderIds[] = $firstOrder->id;
                    $orders['product-base'] = $firstOrder;
                }

                $firstPersonalizedProductOrders = [];

                foreach ($productLines as $line) {
                    $linkedIndex = $line['linked_story_index'];
                    $targetOrder = $linkedIndex !== null ? $orders[$linkedIndex] : $firstOrder;
                    $linkedStoryItem = $linkedIndex !== null ? $storyOrderItems[$linkedIndex] : null;
                    $product = $line['product'];
                    $variant = $line['variant'];

                    if ($linkedIndex === null && $product->personalization_mode === 'collect_child_details') {
                        $targetOrder = $this->createProductOrder(
                            data: $data,
                            admin: $admin,
                            country: $country,
                            governorate: $governorate,
                            checkoutGroup: $checkoutGroup,
                            lineCount: $lineCount,
                            itemPriceCents: (int) $line['total_price_cents'],
                            itemsSubtotalCents: $itemsSubtotalCents,
                            deliveryCents: $deliveryCents,
                            discountCents: $discountCents,
                            payment: $payment,
                            personalizationSnapshot: $line['personalization_snapshot'],
                        );
                        $createdOrderIds[] = $targetOrder->id;
                        $orders['product-'.$product->id.'-'.$line['personalization_unit']] = $targetOrder;
                        $firstOrder ??= $targetOrder;

                        if (! empty($line['reuse_first'])) {
                            $sourceOrder = $firstPersonalizedProductOrders[$product->id] ?? null;
                            if (! $sourceOrder) {
                                throw ValidationException::withMessages([
                                    'products.'.$product->id.'.units' => 'تعذر استخدام صور الطفل الأول. أعد إدخال بيانات الأطفال وحاول مرة أخرى.',
                                ]);
                            }
                            $targetOrder->forceFill([
                                'uploaded_photos' => array_values($sourceOrder->fresh()->uploaded_photos ?? []),
                            ])->save();
                        } elseif ($line['photos'] !== []) {
                            $this->photoUploads->append($targetOrder, $line['photos']);
                        }

                        $firstPersonalizedProductOrders[$product->id] ??= $targetOrder;
                    }

                    if (! $targetOrder) {
                        throw ValidationException::withMessages([
                            'products.'.$product->id.'.quantity' => 'تعذر تحديد سجل الطلب المناسب لهذا المنتج.',
                        ]);
                    }

                    $targetOrder->items()->create([
                        'item_type' => $linkedStoryItem ? 'product_add_on' : 'product',
                        'product_id' => $product->id,
                        'product_variant_id' => $variant?->id,
                        'linked_order_item_id' => $linkedStoryItem?->id,
                        'linked_cart_item_key' => $linkedStoryItem ? 'admin-story-'.$linkedIndex : null,
                        'title' => ProductVariantSnapshot::title($product, $variant),
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
                            'created_manually' => true,
                            ...(! empty($data['_package_snapshot']) && in_array((int) $product->id, $data['_package_product_ids'] ?? [], true)
                                ? ['package' => $data['_package_snapshot']]
                                : []),
                        ],
                        'variant_snapshot' => ProductVariantSnapshot::make($product, $variant),
                        'personalization_snapshot' => $linkedStoryItem ? [
                            'child_name' => $targetOrder->child_name,
                            'child_age' => $targetOrder->child_age,
                            'child_gender' => $targetOrder->child_gender,
                        ] : $line['personalization_snapshot'],
                    ]);

                    $this->decrementStock($product, $variant, $line['quantity']);
                }

                foreach ($storyInputs as $position => $input) {
                    $this->photoUploads->append($orders[(int) $position], $input['photos']);
                }

                return [
                    'representative' => $firstOrder->fresh(),
                    'orders' => array_values(array_map(fn (Order $order): Order => $order->fresh(), $orders)),
                    'story_count' => $storyInputs->count(),
                    'items_subtotal_cents' => $itemsSubtotalCents,
                    'delivery_cents' => $deliveryCents,
                    'discount_cents' => $discountCents,
                    'total_cents' => $grossCents - $discountCents,
                    'payment' => $payment,
                ];
            });
        } catch (\Throwable $exception) {
            foreach ($createdOrderIds as $orderId) {
                Storage::disk((string) config('photo_uploads.disk', 'local'))->deleteDirectory('orders/photos/'.$orderId);
            }

            throw $exception;
        }

        AdminActivityLogger::log(
            action: 'order.created_manually',
            description: 'إنشاء طلب يدوي جديد: '.$result['representative']->checkoutGroupKey(),
            subject: $result['representative'],
            properties: [
                'checkout_group_key' => $result['representative']->checkoutGroupKey(),
                'order_ids' => collect($result['orders'])->pluck('id')->all(),
                'order_numbers' => collect($result['orders'])->pluck('order_number')->all(),
                'story_count' => $result['story_count'],
                'source' => $data['order_source'],
                'items_subtotal_cents' => $result['items_subtotal_cents'],
                'delivery_cents' => $result['delivery_cents'],
                'discount_cents' => $result['discount_cents'],
                'total_cents' => $result['total_cents'],
                'payment' => $result['payment'],
                'package' => $data['_package_snapshot'] ?? null,
            ],
            admin: $admin,
            request: $request,
        );

        return ['representative' => $result['representative'], 'orders' => $result['orders']];
    }

    private function resolveProductLines(array $inputs, Collection $storyIndexes)
    {
        $selected = collect($inputs)
            ->map(fn (array $input, string|int $productId): array => $input + ['product_id' => (int) $productId])
            ->filter(fn (array $input): bool => (int) ($input['quantity'] ?? 0) > 0)
            ->values();

        if ($selected->isEmpty()) {
            return collect();
        }

        $products = Product::query()
            ->where('is_active', true)
            ->whereIn('id', $selected->pluck('product_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($products->count() !== $selected->pluck('product_id')->unique()->count()) {
            throw ValidationException::withMessages(['products' => 'أحد المنتجات المختارة لم يعد متاحًا.']);
        }

        return $selected->flatMap(function (array $input) use ($products, $storyIndexes): array {
            $product = $products->get($input['product_id']);
            $quantity = max(1, (int) $input['quantity']);
            $variant = null;

            if (! empty($input['variant_id'])) {
                $variant = ProductVariant::query()
                    ->where('is_active', true)
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->find($input['variant_id']);

                if (! $variant) {
                    throw ValidationException::withMessages([
                        'products.'.$product->id.'.variant_id' => 'اختر خيارًا متاحًا للمنتج '.$product->name_ar.'.',
                    ]);
                }
            } elseif ($product->activeVariants()->exists()) {
                throw ValidationException::withMessages([
                    'products.'.$product->id.'.variant_id' => 'اختر خيار المنتج '.$product->name_ar.'.',
                ]);
            }

            if (! $product->hasStock($quantity, $variant)) {
                throw ValidationException::withMessages([
                    'products.'.$product->id.'.quantity' => 'الكمية المطلوبة من '.$product->name_ar.' غير متاحة.',
                ]);
            }

            $linkedStoryIndex = null;
            if ($product->isPersonalizedAddon()) {
                $linkedStoryIndex = (int) ($input['linked_story_index'] ?? -1);

                if (! $storyIndexes->containsStrict($linkedStoryIndex)) {
                    throw ValidationException::withMessages([
                        'products.'.$product->id.'.linked_story_index' => 'اختر القصة/الطفل المرتبط بالمنتج '.$product->name_ar.'.',
                    ]);
                }
            }

            $unitPriceCents = $product->effectivePriceCents($variant);
            if ($linkedStoryIndex === null && $product->personalization_mode === 'collect_child_details') {
                $personalizationSchema = is_array($input['personalization_schema'] ?? null)
                    ? $input['personalization_schema']
                    : ProductPersonalizationSchema::forProduct($product);
                $units = is_array($input['units'] ?? null)
                    ? array_values($input['units'])
                    : [['personalization' => (array) ($input['personalization'] ?? [])]];

                return collect($units)->take($quantity)->map(function (array $unit, int $unitIndex) use ($product, $variant, $unitPriceCents, $personalizationSchema): array {
                    $personalization = (array) ($unit['personalization'] ?? []);
                    $reuseFirst = ! empty($unit['reuse_first']);
                    $submittedPhotos = array_values($personalization['photos'] ?? []);
                    $photos = $reuseFirst ? [] : $submittedPhotos;
                    $snapshot = ProductPersonalizationSchema::snapshot($personalizationSchema, $personalization, count($submittedPhotos));

                    if (! ProductPersonalizationSchema::cartItemIsComplete($personalizationSchema, [
                        ...$personalization,
                        'uploaded_photos' => $submittedPhotos,
                        'personalization_snapshot' => $snapshot,
                    ])) {
                        throw ValidationException::withMessages([
                            'products.'.$product->id.'.units.'.$unitIndex.'.personalization' => 'استكمل بيانات الطفل رقم '.($unitIndex + 1).' للمنتج '.$product->name_ar.'.',
                        ]);
                    }

                    return [
                        'product' => $product, 'variant' => $variant, 'quantity' => 1,
                        'unit_price_cents' => $unitPriceCents, 'total_price_cents' => $unitPriceCents,
                        'linked_story_index' => null, 'personalization_schema' => $personalizationSchema,
                        'personalization_snapshot' => $snapshot, 'photos' => $photos,
                        'personalization_unit' => $unitIndex + 1,
                        'reuse_first' => $reuseFirst,
                    ];
                })->all();
            }

            return [[
                'product' => $product,
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price_cents' => $unitPriceCents,
                'total_price_cents' => $unitPriceCents * $quantity,
                'linked_story_index' => $linkedStoryIndex,
                'personalization_schema' => null,
                'personalization_snapshot' => null,
                'photos' => [],
            ]];
        });
    }

    /**
     * Create the normal Order record that owns standalone product items.
     * Personalized products get their own order so their child data and
     * photos stay isolated from stories and other personalized products.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $personalizationSnapshot
     */
    private function createProductOrder(
        array $data,
        User $admin,
        DeliveryCountry $country,
        DeliveryGovernorate $governorate,
        string $checkoutGroup,
        int $lineCount,
        int $itemPriceCents,
        int $itemsSubtotalCents,
        int $deliveryCents,
        int $discountCents,
        array $payment,
        array $personalizationSnapshot,
    ): Order {
        $delivery = [
            'phone' => $data['phone'],
            'delivery_country_id' => $country->id,
            'delivery_governorate_id' => $governorate->id,
            'country' => $country->name,
            'governorate' => $governorate->name,
            'city' => $data['city'],
            'street' => $data['street'],
            'address_details' => $data['address_details'],
            'address' => trim($data['street'].' - '.$data['address_details']),
            'checkout_group' => $checkoutGroup,
            'checkout_session_id' => null,
            'cart_item_index' => 1,
            'cart_items_count' => $lineCount,
            'item_price' => $itemPriceCents / 100,
            'subtotal' => $itemsSubtotalCents / 100,
            'delivery_fee' => $deliveryCents / 100,
            'discount' => $discountCents / 100,
            'total' => ($itemsSubtotalCents + $deliveryCents - $discountCents) / 100,
            'payment_status' => $payment['payment_status'],
            'payment_method' => $payment['payment_method'],
            'paid_amount' => $payment['paid_amount_cents'] / 100,
            'remaining_amount' => $payment['remaining_amount_cents'] / 100,
            'order_source' => $data['order_source'],
            'source_notes' => $data['source_notes'] ?? null,
            'created_manually' => true,
        ];

        $order = Order::create([
            'order_number' => $this->newOrderNumber(),
            'checkout_group_key' => $checkoutGroup,
            'discount_cents' => $discountCents,
            'discount_reason' => $discountCents > 0 ? $data['discount_reason'] : null,
            'payment_status' => $payment['payment_status'],
            'paid_amount_cents' => $payment['paid_amount_cents'],
            'payment_method' => $payment['payment_method'],
            'payment_updated_by_user_id' => $payment['payment_status'] !== OrderPaymentStatus::UNPAID ? $admin->id : null,
            'payment_updated_at' => $payment['payment_status'] !== OrderPaymentStatus::UNPAID ? now() : null,
            'user_id' => null,
            'created_by_admin_id' => $admin->id,
            'order_source' => $data['order_source'],
            'source_notes' => $data['source_notes'] ?? null,
            'parent_name' => $data['parent_name'],
            'story_id' => null,
            'child_name' => $personalizationSnapshot['child_name'] ?? null,
            'child_age' => $personalizationSnapshot['child_age'] ?? null,
            'child_gender' => $personalizationSnapshot['child_gender'] ?? null,
            'language' => null,
            'lesson' => null,
            'interests' => $personalizationSnapshot['interests'] ?? null,
            'gift_note' => null,
            'notes' => $data['admin_notes'] ?? null,
            'parent_notes' => $personalizationSnapshot['parent_notes'] ?? null,
            'delivery_details' => $delivery,
            'uploaded_photos' => [],
            'status' => 'new',
        ]);

        $order->statusLogs()->create([
            'status' => 'new',
            'notes' => $personalizationSnapshot === []
                ? 'تم إنشاء طلب منتج يدويًا من لوحة الإدارة بواسطة '.$admin->name.'.'
                : 'تم إنشاء طلب منتج مخصص يدويًا من لوحة الإدارة بواسطة '.$admin->name.'.',
        ]);

        return $order;
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

    private function newOrderNumber(): string
    {
        do {
            $orderNumber = 'HK-'.now()->format('Y').'-'.strtoupper(Str::random(6));
        } while (Order::query()->where('order_number', $orderNumber)->exists());

        return $orderNumber;
    }
}
