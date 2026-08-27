<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Models\Order;
use App\Models\Product;
use App\Models\Story;
use App\Services\Orders\AdminOrderGroupService;
use App\Services\Orders\AdminOrderUpdateService;
use App\Services\Pricing\StoryPricingService;
use App\Support\OrderPaymentStatus;
use App\Support\OrderSource;
use App\Support\OrderStatusRegistry;
use App\Support\Phone;
use App\Support\ProductPersonalizationSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderEditController extends Controller
{
    public function edit(
        Order $representative,
        AdminOrderGroupService $groups,
        StoryPricingService $storyPricing,
    ) {
        $group = $groups->findByRepresentative($representative->id);
        abort_if($group === [] || $group['trashed'], 404);

        $orders = $group['active_orders']->loadMissing([
            'story',
            'items.order',
            'items.product',
            'items.variant',
        ]);
        $storyOrders = $orders
            ->filter(fn (Order $order): bool => $order->story_id !== null || $order->items->contains('item_type', 'story'))
            ->values();
        $storyOrderIndex = $storyOrders
            ->mapWithKeys(fn (Order $order, int $index): array => [$order->id => $index]);
        $currentStoryIds = $storyOrders->pluck('story_id')->filter()->unique();
        $currentProductIds = $orders->flatMap->items->pluck('product_id')->filter()->unique();
        $currentVariantIds = $orders->flatMap->items->pluck('product_variant_id')->filter()->unique();

        $stories = Story::query()
            ->where(fn ($query) => $query->where('active', true)->orWhereIn('id', $currentStoryIds))
            ->orderBy('title')
            ->get();
        $products = Product::query()
            ->with('variants')
            ->where(fn ($query) => $query->where('is_active', true)->orWhereIn('id', $currentProductIds))
            ->orderBy('sort_order')
            ->orderBy('name_ar')
            ->get();
        $products->each(fn (Product $product) => $product->setRelation(
            'activeVariants',
            $product->variants
                ->filter(fn ($variant): bool => $variant->is_active || $currentVariantIds->contains($variant->id))
                ->values(),
        ));

        $first = $orders->first();
        $delivery = $group['delivery'];
        $initialStories = $storyOrders->map(function (Order $order): array {
            $storyItem = $order->items->firstWhere('item_type', 'story');

            return [
                'existing_order_id' => $order->id,
                'order_number' => $order->order_number,
                'story_id' => $order->story_id ?: $storyItem?->story_id,
                'story_price_cents' => $storyItem?->unit_price_cents,
                'child_name' => $order->child_name,
                'child_age' => $order->child_age,
                'child_gender' => $order->child_gender,
                'interests' => $order->interests,
                'gift_note' => $order->gift_note,
                'parent_notes' => $order->parent_notes,
                'photo_count' => count($order->uploaded_photos ?? []),
            ];
        })->all();
        $initialProducts = [];

        foreach ($orders->flatMap->items->whereIn('item_type', ['product', 'product_add_on']) as $item) {
            if (! $item->product_id) {
                continue;
            }

            $product = $products->firstWhere('id', $item->product_id);
            $linkedOrderId = $item->item_type === 'product_add_on' ? $item->order_id : null;
            $existing = $initialProducts[$item->product_id] ?? [
                'quantity' => 0,
                'variant_id' => $item->product_variant_id,
                'linked_story_index' => $linkedOrderId !== null ? $storyOrderIndex->get($linkedOrderId) : null,
                'unit_price_cents' => (int) $item->unit_price_cents,
            ];
            $existing['quantity'] += (int) $item->quantity;
            if ($product?->personalization_mode === 'collect_child_details') {
                $orderPersonalization = array_filter([
                    'child_name' => $item->order?->child_name,
                    'child_age' => $item->order?->child_age,
                    'child_gender' => $item->order?->child_gender,
                    'interests' => $item->order?->interests,
                    'parent_notes' => $item->order?->parent_notes,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');

                $existing['units'][] = [
                    'existing_order_id' => (int) $item->order_id,
                    'existing_photo_count' => count($item->order->uploaded_photos ?? []),
                    'personalization' => array_replace(
                        $orderPersonalization,
                        ProductPersonalizationSchema::formValues($item->personalization_snapshot ?? []),
                    ),
                ];
            }
            $initialProducts[$item->product_id] = $existing;
        }

        $paymentStatuses = OrderPaymentStatus::labels();
        if (! array_key_exists($group['payment_status'], $paymentStatuses)) {
            $paymentStatuses[$group['payment_status']] = OrderStatusRegistry::label(
                OrderStatusRegistry::TYPE_PAYMENT,
                $group['payment_status'],
            );
        }

        return view('admin.orders.create', [
            'editingGroup' => $group,
            'representative' => $first,
            'initialStories' => $initialStories,
            'initialProducts' => $initialProducts,
            'orderForm' => [
                'parent_name' => $group['customer_name'],
                'phone' => $group['phone'],
                'order_source' => $group['order_source'],
                'source_notes' => $group['source_notes'],
                'delivery_country_id' => data_get($delivery, 'delivery_country_id'),
                'delivery_governorate_id' => data_get($delivery, 'delivery_governorate_id'),
                'city' => data_get($delivery, 'city'),
                'street' => data_get($delivery, 'street'),
                'address_details' => data_get($delivery, 'address_details'),
                'discount_amount' => $group['discount_cents'] / 100,
                'discount_reason' => $group['discount_reason'],
                'admin_notes' => $first?->notes,
                'payment_status' => $group['payment_status'],
                'paid_amount' => $group['paid_amount_cents'] / 100,
                'payment_method' => $group['payment_method'],
            ],
            'stories' => $stories,
            'storyPrices' => $stories->mapWithKeys(fn (Story $story): array => [
                $story->id => $storyPricing->snapshot($story),
            ]),
            'products' => $products,
            'countries' => DeliveryCountry::query()
                ->with('activeGovernorates')
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            'sourceOptions' => OrderSource::options(),
            'paymentStatuses' => $paymentStatuses,
            'paymentMethods' => OrderPaymentStatus::paymentMethods(),
        ]);
    }

    public function update(Request $request, Order $representative, AdminOrderUpdateService $updater)
    {
        $request->merge([
            'phone' => Phone::normalize($request->input('phone')),
            'payment_status' => $request->input('payment_status', OrderPaymentStatus::UNPAID),
            'stories' => $request->input('stories', []),
        ]);

        $currentStoryIds = Order::query()
            ->where('checkout_group_key', $representative->checkoutGroupKey())
            ->pluck('story_id')
            ->filter()
            ->unique();

        $validated = $request->validate([
            'parent_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'order_source' => ['required', Rule::in(array_keys(OrderSource::options()))],
            'source_notes' => ['nullable', 'string', 'max:500'],
            'delivery_country_id' => ['required', 'integer', 'exists:delivery_countries,id'],
            'delivery_governorate_id' => ['required', 'integer', 'exists:delivery_governorates,id'],
            'city' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'address_details' => ['required', 'string', 'max:1000'],
            'stories' => ['array', 'max:10'],
            'stories.*.existing_order_id' => ['nullable', 'integer', 'distinct'],
            'stories.*.story_id' => [
                'required',
                Rule::exists('stories', 'id')->where(fn ($query) => $query
                    ->where('active', true)
                    ->orWhereIn('id', $currentStoryIds)),
            ],
            'stories.*.child_name' => ['required', 'string', 'max:100'],
            'stories.*.child_age' => ['required', 'integer', 'min:3', 'max:12'],
            'stories.*.child_gender' => ['required', Rule::in(['boy', 'girl'])],
            'stories.*.interests' => ['nullable', 'string', 'max:1000'],
            'stories.*.gift_note' => ['nullable', 'string', 'max:1000'],
            'stories.*.parent_notes' => ['nullable', 'string', 'max:2000'],
            'stories.*.photos' => ['nullable', 'array', 'max:3'],
            'stories.*.photos.*' => ['required', 'file', 'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024)],
            'products' => ['nullable', 'array'],
            'products.*.quantity' => ['nullable', 'integer', 'min:0', 'max:99'],
            'products.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'products.*.linked_story_index' => ['nullable', 'integer', 'min:0', 'max:99'],
            'products.*.personalization' => ['nullable', 'array'],
            'products.*.units' => ['nullable', 'array', 'max:10'],
            'products.*.units.*.existing_order_id' => ['nullable', 'integer'],
            'products.*.units.*.reuse_first' => ['nullable', 'boolean'],
            'products.*.units.*.reuse_source_order_id' => ['nullable', 'integer'],
            'products.*.units.*.personalization' => ['nullable', 'array'],
            'discount_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'discount_reason' => ['nullable', 'string', 'max:500'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
            'payment_status' => ['required', Rule::in(OrderPaymentStatus::statuses(false))],
            'paid_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999.99'],
            'payment_method' => ['nullable', Rule::in(OrderPaymentStatus::paymentMethods())],
            'change_reason' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'change_reason.required' => 'اكتب سبب تعديل الطلب لحفظه في سجل النشاط.',
            'stories.*.photos.max' => 'الحد الأقصى 3 صور جديدة لكل قصة.',
        ]);

        foreach ($validated['stories'] as $index => $story) {
            if (empty($story['existing_order_id']) && count($story['photos'] ?? []) < 2) {
                throw ValidationException::withMessages([
                    "stories.$index.photos" => 'ارفع صورتين أو 3 صور عند إضافة قصة جديدة.',
                ]);
            }
        }

        $selectedProducts = collect($validated['products'] ?? [])
            ->contains(fn (array $product): bool => (int) ($product['quantity'] ?? 0) > 0);
        if ($validated['stories'] === [] && ! $selectedProducts) {
            throw ValidationException::withMessages([
                'stories' => 'يجب أن تحتوي عملية الشراء على قصة أو منتج واحد على الأقل.',
            ]);
        }

        if ((float) ($validated['discount_amount'] ?? 0) > 0 && blank($validated['discount_reason'] ?? null)) {
            throw ValidationException::withMessages([
                'discount_reason' => 'اكتب سبب الخصم لحفظه مع الطلب.',
            ]);
        }

        $selectedProductInputs = collect($validated['products'] ?? [])
            ->filter(fn (array $product): bool => (int) ($product['quantity'] ?? 0) > 0);
        $selectedProductModels = Product::query()
            ->whereIn('id', $selectedProductInputs->keys()->map(fn (string|int $id): int => (int) $id))
            ->get()
            ->keyBy('id');
        $existingProductItems = Order::query()
            ->with(['items.order', 'items.product'])
            ->where('checkout_group_key', $representative->checkoutGroupKey())
            ->get()
            ->flatMap->items
            ->whereIn('item_type', ['product', 'product_add_on']);

        foreach ($selectedProductInputs as $productId => $productInput) {
            $product = $selectedProductModels->get((int) $productId);
            if (! $product || $product->personalization_mode !== 'collect_child_details') {
                continue;
            }

            $schema = ProductPersonalizationSchema::forProduct($product);
            $quantity = (int) ($productInput['quantity'] ?? 1);
            if ($quantity > 10) {
                throw ValidationException::withMessages(["products.$productId.quantity" => 'الحد الأقصى للمنتج المخصص هو ١٠ أطفال.']);
            }
            $productExistingItems = $existingProductItems
                ->filter(fn ($item) => (int) $item->product_id === (int) $productId)
                ->values();
            $usesLegacyPersonalizationInput = ! is_array($productInput['units'] ?? null)
                && is_array($productInput['personalization'] ?? null);

            if (is_array($productInput['units'] ?? null)) {
                $units = $productInput['units'];
                ksort($units, SORT_NUMERIC);
                $units = array_values($units);
            } elseif ($usesLegacyPersonalizationInput) {
                $units = [[
                    'existing_order_id' => $productExistingItems->first()?->order_id,
                    'personalization' => $productInput['personalization'],
                ]];
            } else {
                $units = $productExistingItems->map(fn ($item): array => [
                    'existing_order_id' => $item->order_id,
                    'personalization' => [],
                ])->all();
            }
            $validatedUnits = [];

            for ($unitIndex = 0; $unitIndex < $quantity; $unitIndex++) {
                $unit = (array) ($units[$unitIndex] ?? []);
                $reuseFirst = filter_var($unit['reuse_first'] ?? false, FILTER_VALIDATE_BOOL)
                    || $request->boolean("products.$productId.units.$unitIndex.reuse_first");
                if ($unitIndex > 0 && $reuseFirst) {
                    $validatedUnits[] = [
                        'personalization' => $validatedUnits[0]['personalization'],
                        'reuse_first' => true,
                        'existing_order_id' => null,
                        'existing_photo_count' => $validatedUnits[0]['existing_photo_count'],
                        'reuse_source_order_id' => $validatedUnits[0]['existing_order_id'] ?? null,
                    ];

                    continue;
                }

                $existingItem = $existingProductItems->first(fn ($item) => (int) $item->product_id === (int) $productId
                    && (int) $item->order_id === (int) ($unit['existing_order_id'] ?? 0));
                $submittedPersonalization = (array) ($unit['personalization'] ?? []);
                $originalPersonalization = ProductPersonalizationSchema::formValues($existingItem?->personalization_snapshot ?? []);
                $personalizationInput = array_replace(
                    $originalPersonalization,
                    $submittedPersonalization,
                );
                $photoInputPath = $usesLegacyPersonalizationInput
                    ? "products.$productId.personalization.photos"
                    : "products.$productId.units.$unitIndex.personalization.photos";
                $newPhotos = array_values($request->file($photoInputPath, []));
                $existingPhotoCount = count($existingItem?->order?->uploaded_photos ?? []);
                $rules = ProductPersonalizationSchema::adminOrderValidationRules($schema);
                if (isset($rules['photos'])) {
                    $photoField = ProductPersonalizationSchema::enabledFields($schema)['photos'];
                    $remaining = max(0, (int) $photoField['max_files'] - $existingPhotoCount);
                    $needed = max(0, (int) $photoField['min_files'] - $existingPhotoCount);
                    $rules['photos'] = [$needed > 0 ? 'required' : 'nullable', 'array', ...($needed > 0 ? ['min:'.$needed] : []), 'max:'.$remaining];
                    $personalizationInput['photos'] = $newPhotos;
                }
                try {
                    $personalization = Validator::make($personalizationInput, $rules, ProductPersonalizationSchema::adminOrderValidationMessages($schema))->validate();
                } catch (ValidationException $exception) {
                    $errors = [];
                    $errorPrefix = $usesLegacyPersonalizationInput
                        ? "products.$productId.personalization"
                        : "products.$productId.units.$unitIndex.personalization";
                    foreach ($exception->errors() as $field => $messages) {
                        $errors["$errorPrefix.$field"] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
                $validatedUnits[] = [
                    'personalization' => $personalization,
                    'reuse_first' => false,
                    'existing_order_id' => $unit['existing_order_id'] ?? null,
                    'existing_photo_count' => $existingPhotoCount,
                    'preserve_original_snapshot' => $existingItem !== null
                        && $newPhotos === []
                        && collect($submittedPersonalization)->every(
                            fn (mixed $value, string $key): bool => (string) ($originalPersonalization[$key] ?? '') === (string) ($value ?? ''),
                        ),
                ];
            }

            $validated['products'][$productId]['units'] = $validatedUnits;
            $validated['products'][$productId]['personalization_schema'] = $schema;
        }

        $result = $updater->update($representative, $validated, $request->user(), $request);

        return redirect()
            ->route('admin.orders.groups.show', $result['representative']->id)
            ->with('success', 'تم تحديث عملية الشراء كاملة بنجاح.');
    }
}
