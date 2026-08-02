<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\PricingPackage;
use App\Models\Story;
use App\Services\Cart\CartTrackingService;
use App\Services\Cart\StoryCartItemBuilder;
use App\Services\Pricing\PackagePricingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PackageCartController extends Controller
{
    private const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif'];

    public function store(
        Request $request,
        PricingPackage $pricingPackage,
        StoryCartItemBuilder $storyBuilder,
        PackagePricingService $pricing,
        TemporaryPhotoUploadService $uploads,
    ) {
        abort_unless($pricingPackage->active && $pricingPackage->show_in_store, 404);
        $pricingPackage->load(['items.product', 'items.variant']);

        $rules = [
            'stories' => ['array', 'size:'.$pricingPackage->story_count],
            'stories.*.story_id' => ['required', Rule::exists('stories', 'id')->where(fn ($query) => $query->where('active', true))],
            'stories.*.child_name' => ['required', 'string', 'max:255'],
            'stories.*.child_age' => ['required', 'integer', Rule::in(StoryAgeOptions::forPersonalization())],
            'stories.*.child_gender' => ['required', Rule::in(['boy', 'girl'])],
            'stories.*.photo_upload_ids' => ['nullable', 'array', 'min:2', 'max:3'],
            'stories.*.photo_upload_ids.*' => ['string', 'uuid'],
            'stories.*.photos' => ['nullable', 'array', 'min:2', 'max:3'],
            'stories.*.photos.*' => ['required', 'file', 'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024), function (string $attribute, mixed $value, \Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid() || ! in_array(strtolower($value->getClientOriginalExtension()), self::EXTENSIONS, true)) {
                    $fail('ارفع صور JPG أو PNG أو WebP أو HEIC/HEIF صحيحة.');
                }
            }],
        ];

        $messages = [
            'stories.size' => 'يجب استكمال بيانات كل قصص الباقة.',
            'stories.*.story_id.required' => 'اختر قصة لكل عنصر في الباقة.',
            'stories.*.child_name.required' => 'اكتب اسم الطفل لكل قصة.',
            'stories.*.child_age.required' => 'اختر عمر الطفل لكل قصة.',
            'stories.*.child_gender.required' => 'اختر جنس الطفل لكل قصة.',
            'stories.*.photo_upload_ids.min' => 'ارفع صورتين على الأقل لكل قصة.',
            'stories.*.photo_upload_ids.max' => 'الحد الأقصى ٣ صور لكل قصة.',
            'stories.*.photos.min' => 'ارفع صورتين على الأقل لكل قصة.',
            'stories.*.photos.max' => 'الحد الأقصى ٣ صور لكل قصة.',
        ];

        if ($pricingPackage->story_count === 0) {
            unset($rules['stories'], $rules['stories.*.story_id'], $rules['stories.*.child_name'], $rules['stories.*.child_age'], $rules['stories.*.child_gender'], $rules['stories.*.photo_upload_ids'], $rules['stories.*.photo_upload_ids.*'], $rules['stories.*.photos'], $rules['stories.*.photos.*']);
        }

        $validator = Validator::make($request->all(), $rules, $messages);
        $validator->after(function ($validator) use ($request, $pricingPackage): void {
            for ($index = 0; $index < $pricingPackage->story_count; $index++) {
                if (count((array) $request->input("stories.$index.photo_upload_ids", [])) >= 2 || count((array) $request->file("stories.$index.photos", [])) >= 2) {
                    continue;
                }
                $validator->errors()->add("stories.$index.photo_upload_ids", 'ارفع صورتين أو ٣ صور واضحة لكل قصة.');
            }
        });
        $validated = $validator->validate();
        $packageKey = (string) Str::uuid();
        $storyInputs = collect($validated['stories'] ?? []);
        $stories = Story::whereIn('id', $storyInputs->pluck('story_id'))->get()->keyBy('id');

        try {
            foreach ($storyInputs as $input) {
                if (($input['photo_upload_ids'] ?? []) !== []) {
                    $uploads->validatedUploadedIds($request, $input['photo_upload_ids'], 2, 3);
                }
            }
        } catch (UploadValidationException $exception) {
            return back()->withInput()->withErrors(['stories' => $exception->getMessage()]);
        }

        foreach ($pricingPackage->items as $packageProduct) {
            abort_unless($packageProduct->product?->is_active, 422, 'أحد منتجات الباقة غير متاح حاليًا.');
            abort_unless($packageProduct->product->hasStock($packageProduct->quantity, $packageProduct->variant), 422, 'الكمية المطلوبة من أحد منتجات الباقة غير متاحة.');
        }

        $storyItems = [];
        $componentPrices = [];
        $directPhotoPaths = [];

        try {
            foreach ($storyInputs as $index => $input) {
                $story = $stories->get((int) $input['story_id']);
                $storyKey = $packageKey.'-story-'.($index + 1);
                $paths = [];
                $uploadIds = $input['photo_upload_ids'] ?? [];
                if ($uploadIds !== []) {
                    $paths = $uploads->attachIdsToCart($request, $uploadIds, $storyKey)->pluck('path')->all();
                } else {
                    foreach ($request->file("stories.$index.photos", []) as $photo) {
                        $paths[] = $photo->store('orders/cart/'.now()->format('Y-m').'/'.$storyKey, 'local');
                    }
                    $directPhotoPaths = array_merge($directPhotoPaths, $paths);
                }
                $storyItem = $storyBuilder->build($story, $storyKey, $input, $paths);
                $componentPrices[] = (int) round($storyItem['story_price'] * 100);
                $storyItems[] = $storyItem;
            }

            foreach ($pricingPackage->items as $packageProduct) {
                $componentPrices[] = $packageProduct->product->effectivePriceCents($packageProduct->variant) * $packageProduct->quantity;
            }

            $packagePriceCents = (int) round(((float) $pricingPackage->price) * 100);
            $allocations = $pricing->allocate($packagePriceCents, $componentPrices);
            $allocationIndex = 0;
            $packageSnapshot = [
                'id' => $pricingPackage->id,
                'slug' => $pricingPackage->slug,
                'name' => $pricingPackage->name,
                'instance_key' => $packageKey,
                'package_price_cents' => $packagePriceCents,
                'regular_total_cents' => array_sum($componentPrices),
            ];

            foreach ($storyItems as &$storyItem) {
                $allocated = $allocations[$allocationIndex++] ?? 0;
                $storyItem['story_regular_price'] = $storyItem['story_price'];
                $storyItem['story_price'] = $allocated / 100;
                $storyItem['package_snapshot'] = $packageSnapshot;
            }
            unset($storyItem);

            $firstStoryKey = $storyItems[0]['key'] ?? null;
            $productItems = [];
            foreach ($pricingPackage->items as $packageProduct) {
                $product = $packageProduct->product;
                $variant = $packageProduct->variant;
                $allocated = $allocations[$allocationIndex++] ?? 0;
                $requiresStory = $product->isPersonalizedAddon();
                $productItems[] = [
                    'key' => $packageKey.'-product-'.$packageProduct->id,
                    'item_type' => $requiresStory ? 'product_add_on' : 'product',
                    'product_id' => $product->id,
                    'product_title' => $product->name_ar,
                    'product_slug' => $product->slug,
                    'product_image' => $product->featured_image,
                    'product_image_url' => $product->featured_image_url,
                    'variant_id' => $variant?->id,
                    'variant_name' => $variant?->name_ar,
                    'sku' => $variant?->sku ?: $product->sku,
                    'unit_price_cents' => $packageProduct->quantity > 0 ? intdiv($allocated, $packageProduct->quantity) : $allocated,
                    'unit_price' => $packageProduct->quantity > 0 ? intdiv($allocated, $packageProduct->quantity) / 100 : $allocated / 100,
                    'quantity' => $packageProduct->quantity,
                    'line_total_cents' => $allocated,
                    'personalization_mode' => $product->personalization_mode,
                    'linked_story_key' => $requiresStory ? $firstStoryKey : null,
                    'linked_story_snapshot' => $requiresStory && isset($storyItems[0]) ? [
                        'story_id' => $storyItems[0]['story_id'],
                        'story_title' => $storyItems[0]['story_title'],
                        'child_name' => $storyItems[0]['child_name'],
                        'child_age' => $storyItems[0]['child_age'],
                        'child_gender' => $storyItems[0]['child_gender'],
                    ] : null,
                    'package_snapshot' => $packageSnapshot,
                ];
            }

            $cart = session('cart.items', []);
            $cart[$packageKey] = [
                'key' => $packageKey,
                'item_type' => 'package',
                'pricing_package_id' => $pricingPackage->id,
                'package_name' => $pricingPackage->name,
                'package_slug' => $pricingPackage->slug,
                'story_count' => $pricingPackage->story_count,
                'product_count' => $pricingPackage->items->sum('quantity'),
                'line_total_cents' => $packagePriceCents,
                'regular_total_cents' => array_sum($componentPrices),
                'package_stories' => $storyItems,
                'package_products' => $productItems,
            ];
            session(['cart.items' => $cart]);
            app(CartTrackingService::class)->recordItemAdded($request, $packageKey);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($directPhotoPaths);
            throw $exception;
        }

        return redirect()->route('cart.index')->with('success', 'تمت إضافة باقة «'.$pricingPackage->name.'» إلى السلة.');
    }
}
