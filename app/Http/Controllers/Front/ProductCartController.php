<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartTrackingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductCartController extends Controller
{
    public function store(Request $request, Product $product, TemporaryPhotoUploadService $uploads)
    {
        abort_unless($product->is_active, 404);

        $collectsChildDetails = $product->personalization_mode === 'collect_child_details';
        $minimumPhotos = (int) config('photo_uploads.min_files', 2);
        $maximumPhotos = (int) config('photo_uploads.max_files', 3);
        $rules = [
            'variant_id' => 'nullable|integer|exists:product_variants,id',
            'quantity' => 'nullable|integer|min:1|max:50',
            'linked_story_key' => 'nullable|string',
        ];

        if ($collectsChildDetails) {
            $rules += [
                'child_name' => ['required', 'string', 'max:255'],
                'child_age' => ['required', 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
                'child_gender' => ['required', Rule::in(['boy', 'girl'])],
                'interests' => ['nullable', 'string', 'max:500'],
                'photo_upload_ids' => ['required', 'array', 'min:'.$minimumPhotos, 'max:'.$maximumPhotos],
                'photo_upload_ids.*' => ['required', 'string', 'uuid', 'distinct'],
            ];
        }

        $validated = Validator::make($request->all(), $rules, [
            'child_name.required' => 'يرجى إدخال اسم الطفل.',
            'child_name.max' => 'اسم الطفل يجب ألا يزيد عن 255 حرفًا.',
            'child_age.required' => 'يرجى اختيار عمر الطفل.',
            'child_age.integer' => 'يرجى اختيار عمر صحيح للطفل.',
            'child_age.in' => 'يرجى اختيار عمر الطفل من ٣ إلى ١٢ سنة.',
            'child_gender.required' => 'يرجى اختيار جنس الطفل.',
            'child_gender.in' => 'يرجى اختيار جنس صحيح للطفل.',
            'interests.max' => 'الاهتمامات والملاحظات يجب ألا تزيد عن 500 حرف.',
            'photo_upload_ids.required' => 'يرجى رفع صورتين واضحتين للطفل على الأقل.',
            'photo_upload_ids.array' => 'يرجى رفع صور الطفل بطريقة صحيحة.',
            'photo_upload_ids.min' => 'يرجى رفع صورتين واضحتين للطفل على الأقل.',
            'photo_upload_ids.max' => 'يمكنك رفع '.$maximumPhotos.' صور كحد أقصى.',
            'photo_upload_ids.*.uuid' => 'بعض الصور المرفوعة غير صالحة. احذفها وارفعها مرة أخرى.',
            'photo_upload_ids.*.distinct' => 'لا يمكن استخدام الصورة نفسها أكثر من مرة.',
        ])->validate();

        $quantity = (int) ($validated['quantity'] ?? 1);
        $variant = null;

        if (! empty($validated['variant_id'])) {
            $variant = ProductVariant::where('product_id', $product->id)
                ->where('is_active', true)
                ->findOrFail($validated['variant_id']);
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
        $itemKey = (string) Str::uuid();
        $linkedStory = $linkedStoryKey ? $cart[$linkedStoryKey] : null;
        $uploadedPhotos = [];

        if ($collectsChildDetails) {
            try {
                $uploadedPhotos = $uploads->attachIdsToCart(
                    $request,
                    $validated['photo_upload_ids'],
                    $itemKey,
                )->pluck('path')->all();
            } catch (UploadValidationException $exception) {
                return $this->errorResponse(
                    $request,
                    $exception->getMessage(),
                    $exception->field ?: 'photo_upload_ids',
                );
            }
        }

        $personalizationSnapshot = $collectsChildDetails ? [
            'child_name' => $validated['child_name'],
            'child_age' => (int) $validated['child_age'],
            'child_gender' => $validated['child_gender'],
            'interests' => $validated['interests'] ?? null,
            'uploaded_photos_count' => count($uploadedPhotos),
        ] : null;

        $cart[$itemKey] = [
            'key' => $itemKey,
            'item_type' => $linkedStoryKey ? 'product_add_on' : 'product',
            'product_id' => $product->id,
            'product_title' => $product->name_ar,
            'product_slug' => $product->slug,
            'product_image' => $product->featured_image,
            'product_image_url' => $product->featured_image_url,
            'variant_id' => $variant?->id,
            'variant_name' => $variant?->name_ar,
            'sku' => $variant?->sku ?: $product->sku,
            'unit_price_cents' => $unitPriceCents,
            'unit_price' => $unitPriceCents / 100,
            'quantity' => $quantity,
            'line_total_cents' => $unitPriceCents * $quantity,
            'personalization_mode' => $product->personalization_mode,
            'child_name' => $personalizationSnapshot['child_name'] ?? null,
            'child_age' => $personalizationSnapshot['child_age'] ?? null,
            'child_gender' => $personalizationSnapshot['child_gender'] ?? null,
            'interests' => $personalizationSnapshot['interests'] ?? null,
            'uploaded_photos' => $uploadedPhotos,
            'personalization_snapshot' => $personalizationSnapshot,
            'linked_story_key' => $linkedStoryKey,
            'linked_story_snapshot' => $linkedStory ? [
                'story_id' => $linkedStory['story_id'] ?? null,
                'story_title' => $linkedStory['story_title'] ?? null,
                'child_name' => $linkedStory['child_name'] ?? null,
                'child_age' => $linkedStory['child_age'] ?? null,
                'child_gender' => $linkedStory['child_gender'] ?? null,
            ] : null,
        ];

        session(['cart.items' => $cart]);
        app(CartTrackingService::class)->recordItemAdded($request, $itemKey);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تمت إضافة '.$product->name_ar.' إلى السلة.',
                'item_key' => $itemKey,
                'product_name' => $product->name_ar,
                'added_line_total' => ($unitPriceCents * $quantity) / 100,
                'cart_count' => count($cart),
                'mobile_item_html' => view('front.cart._mobile_item', [
                    'key' => $itemKey,
                    'item' => $cart[$itemKey],
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
