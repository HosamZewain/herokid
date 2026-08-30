<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartTrackingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\ProductPersonalizationSchema;
use App\Support\ProductVariantSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ProductCartController extends Controller
{
    public function store(Request $request, Product $product, TemporaryPhotoUploadService $uploads)
    {
        abort_unless($product->is_active, 404);

        $collectsChildDetails = $product->personalization_mode === 'collect_child_details';
        $personalizationSchema = ProductPersonalizationSchema::forProduct($product);
        $personalizationFields = ProductPersonalizationSchema::enabledFields($personalizationSchema);
        $photoField = $personalizationFields['photos'] ?? null;
        $rules = [
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1|max:50',
            'linked_story_key' => 'nullable|string',
        ];

        if ($collectsChildDetails && ! $request->has('personalizations')) {
            $rules += ProductPersonalizationSchema::validationRules($personalizationSchema);
        }

        $validated = Validator::make(
            $request->all(),
            $rules,
            $collectsChildDetails ? ProductPersonalizationSchema::validationMessages($personalizationSchema) : [],
        )->validate();

        $quantity = (int) ($validated['quantity'] ?? 1);

        if ($collectsChildDetails && $quantity > 10) {
            return $this->errorResponse($request, 'الحد الأقصى للمنتج المخصص في الطلب الواحد هو ١٠ أطفال.', 'quantity');
        }
        $variant = null;

        if (! empty($validated['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('is_active', true)
                ->findOrFail($validated['variant_id']);
        }

        if ($product->activeVariants()->exists() && ! $variant) {
            return $this->errorResponse($request, 'اختر النوع المطلوب قبل إضافته إلى السلة.', 'variant_id');
        }

        $cart = session('cart.items', []);
        $storyItems = collect($cart)->filter(fn (array $item) => ($item['item_type'] ?? 'story') === 'story');
        $requiresStory = $product->personalization_mode === 'inherit_from_linked_story' || $product->purchase_mode === 'add_on_only';
        $linkedStoryKey = $validated['linked_story_key'] ?? null;

        if ($requiresStory) {
            if ($storyItems->isEmpty()) {
                return $this->errorResponse($request, 'أضف قصة مخصصة أولًا لاستخدام صورة طفلك في هذا المنتج.');
            }

            if (! $linkedStoryKey && $storyItems->count() === 1) {
                $linkedStoryKey = (string) $storyItems->keys()->first();
            }

            if (! $linkedStoryKey || ! isset($cart[$linkedStoryKey]) || ($cart[$linkedStoryKey]['item_type'] ?? 'story') !== 'story') {
                return $this->errorResponse(
                    $request,
                    'اختر الطفل والقصة التي سيتم تخصيص المنتج لها.',
                    'linked_story_key'
                );
            }
        }

        if ($product->purchase_mode === 'add_on_only' && ! $linkedStoryKey) {
            return $this->errorResponse($request, 'هذا المنتج يضاف فقط مع قصة مخصصة.');
        }

        if (! $product->hasStock($quantity, $variant)) {
            return $this->errorResponse($request, 'الكمية المطلوبة غير متاحة حالياً.');
        }

        $unitPriceCents = $product->effectivePriceCents($variant);
        $linkedStory = $linkedStoryKey ? $cart[$linkedStoryKey] : null;
        $personalizationUnits = [];

        if ($collectsChildDetails) {
            $submittedUnits = $request->input('personalizations');
            $submittedUnits = is_array($submittedUnits) ? array_values($submittedUnits) : [$request->all()];

            if (count($submittedUnits) < $quantity) {
                return $this->errorResponse($request, 'أدخل بيانات كل طفل أو اختر استخدام بيانات الطفل الأول.', 'personalizations');
            }

            for ($index = 0; $index < $quantity; $index++) {
                $unit = (array) ($submittedUnits[$index] ?? []);
                $reuseFirst = $index > 0 && filter_var($unit['reuse_first'] ?? false, FILTER_VALIDATE_BOOL);

                if ($reuseFirst) {
                    $personalizationUnits[] = [
                        ...$personalizationUnits[0],
                        'key' => (string) Str::uuid(),
                        'reused_from_unit' => 1,
                    ];

                    continue;
                }

                $unitValidator = Validator::make(
                    $unit,
                    ProductPersonalizationSchema::validationRules($personalizationSchema),
                    ProductPersonalizationSchema::validationMessages($personalizationSchema),
                );

                if ($unitValidator->fails()) {
                    $errors = [];
                    foreach ($unitValidator->errors()->toArray() as $field => $messages) {
                        $errors['personalizations.'.$index.'.'.$field] = $messages;
                    }

                    if ($request->expectsJson()) {
                        return response()->json([
                            'message' => 'استكمل بيانات الطفل رقم '.($index + 1).'.',
                            'errors' => $errors,
                        ], 422);
                    }

                    return back()->withErrors($errors)->withInput();
                }

                $unitData = $unitValidator->validated();
                $unitKey = (string) Str::uuid();
                $uploadedPhotos = [];

                if ($photoField && ! empty($unitData['photo_upload_ids'])) {
                    try {
                        $uploadedPhotos = $uploads->attachIdsToCart(
                            $request,
                            $unitData['photo_upload_ids'],
                            $unitKey,
                        )->pluck('path')->all();
                    } catch (UploadValidationException $exception) {
                        return $this->errorResponse(
                            $request,
                            $exception->getMessage(),
                            'personalizations.'.$index.'.'.($exception->field ?: 'photo_upload_ids'),
                        );
                    }
                }

                $personalizationUnits[] = [
                    'key' => $unitKey,
                    'data' => $unitData,
                    'uploaded_photos' => $uploadedPhotos,
                    'snapshot' => ProductPersonalizationSchema::snapshot($personalizationSchema, $unitData, count($uploadedPhotos)),
                    'reused_from_unit' => null,
                ];
            }
        }

        $unitsToAdd = $collectsChildDetails ? $personalizationUnits : [[
            'key' => (string) Str::uuid(),
            'data' => [],
            'uploaded_photos' => [],
            'snapshot' => null,
            'quantity' => $quantity,
        ]];
        $addedKeys = [];

        foreach ($unitsToAdd as $unitIndex => $unit) {
            $itemKey = $unit['key'] ?? (string) Str::uuid();
            $personalizationSnapshot = $unit['snapshot'] ?? null;
            $uploadedPhotos = $unit['uploaded_photos'] ?? [];
            $lineQuantity = $collectsChildDetails ? 1 : $quantity;

            $cart[$itemKey] = [
                'key' => $itemKey,
                'item_type' => $linkedStoryKey ? 'product_add_on' : 'product',
                'product_id' => $product->id,
                'product_title' => ProductVariantSnapshot::title($product, $variant),
                'product_slug' => $product->slug,
                'product_image' => ProductVariantSnapshot::imagePath($product, $variant),
                'product_image_url' => ProductVariantSnapshot::imageUrl($product, $variant),
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name_ar,
                'variant_snapshot' => ProductVariantSnapshot::make($product, $variant),
                'sku' => $variant?->sku ?: $product->sku,
                'unit_price_cents' => $unitPriceCents,
                'unit_price' => $unitPriceCents / 100,
                'quantity' => $lineQuantity,
                'line_total_cents' => $unitPriceCents * $lineQuantity,
                'personalization_mode' => $product->personalization_mode,
                'child_name' => $personalizationSnapshot['child_name'] ?? null,
                'child_age' => $personalizationSnapshot['child_age'] ?? null,
                'child_gender' => $personalizationSnapshot['child_gender'] ?? null,
                'interests' => $personalizationSnapshot['interests'] ?? null,
                'school_name' => $personalizationSnapshot['school_name'] ?? null,
                'class_name' => $personalizationSnapshot['class_name'] ?? null,
                'parent_notes' => $personalizationSnapshot['parent_notes'] ?? null,
                'uploaded_photos' => $uploadedPhotos,
                'personalization_snapshot' => $personalizationSnapshot,
                'personalization_unit' => $collectsChildDetails ? $unitIndex + 1 : null,
                'reused_from_unit' => $unit['reused_from_unit'] ?? null,
                'linked_story_key' => $linkedStoryKey,
                'linked_story_snapshot' => $linkedStory ? [
                    'story_id' => $linkedStory['story_id'] ?? null,
                    'story_title' => $linkedStory['story_title'] ?? null,
                    'child_name' => $linkedStory['child_name'] ?? null,
                    'child_age' => $linkedStory['child_age'] ?? null,
                    'child_gender' => $linkedStory['child_gender'] ?? null,
                ] : null,
            ];
            $addedKeys[] = $itemKey;
        }

        session(['cart.items' => $cart]);
        foreach ($addedKeys as $addedKey) {
            app(CartTrackingService::class)->recordItemAdded($request, $addedKey);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تمت إضافة '.$product->name_ar.' إلى السلة.',
                'item_key' => $addedKeys[0],
                'product_name' => $product->name_ar,
                'added_line_total' => ($unitPriceCents * $quantity) / 100,
                'cart_count' => count($cart),
                'mobile_item_html' => view('front.cart._mobile_item', [
                    'key' => $addedKeys[0],
                    'item' => $cart[$addedKeys[0]],
                    'addOnItems' => collect(),
                ])->render(),
            ]);
        }

        return redirect()->route('cart.index')->with('success', 'تمت إضافة المنتج إلى السلة.');
    }

    private function errorResponse(Request $request, string $message, ?string $field = null)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'errors' => $field ? [$field => [$message]] : [],
            ], 422);
        }

        if ($field) {
            return back()->withErrors([$field => $message])->withInput();
        }

        return back()->with('error', $message);
    }
}
