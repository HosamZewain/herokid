<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\ChildIdentityRequest;
use App\Models\DeliveryCountry;
use App\Models\Order;
use App\Models\Story;
use App\Services\Cart\CartTrackingService;
use App\Services\Cart\StoryCartItemBuilder;
use App\Services\ChildIdentity\ChildIdentityEventLogger;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\ProductRecommendations;
use App\Support\StoryAgeOptions;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CartController extends Controller
{
    private const ALLOWED_PHOTO_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'heic',
        'heif',
    ];

    private const ALLOWED_PHOTO_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
        'image/heic-sequence',
        'image/heif-sequence',
    ];

    public function index()
    {
        $cart = $this->cart();
        $cartCollection = collect($cart);
        $storyItems = $cartCollection->filter(fn (array $item) => ($item['item_type'] ?? 'story') === 'story');
        $cartProductIds = $cartCollection
            ->pluck('product_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $upsellStoryKey = session('upsell_story_key');
        $upsellStoryItem = $upsellStoryKey && isset($cart[$upsellStoryKey]) ? $cart[$upsellStoryKey] : $storyItems->first();
        $recommendedProducts = $upsellStoryItem
            ? app(ProductRecommendations::class)->forStoryCartItem($upsellStoryItem, 6, $cartProductIds)
            : collect();

        return view('front.cart.index', [
            'cartItems' => $cart,
            'storyItems' => $storyItems,
            'recommendedProducts' => $recommendedProducts,
            'upsellStoryKey' => $upsellStoryKey,
            'subtotal' => $this->subtotal($cart),
            'deliveryFee' => $this->defaultDeliveryFee(),
            'deliveryCountries' => $this->deliveryCountries(),
            'savedDeliveryDetails' => $this->savedDeliveryDetails(),
        ]);
    }

    public function store(
        Request $request,
        Story $story,
        TemporaryPhotoUploadService $uploads,
        StoryCartItemBuilder $itemBuilder,
    ) {
        abort_unless($story->active, 404);

        $minimumPhotos = (int) config('photo_uploads.min_files', 2);
        $maximumPhotos = (int) config('photo_uploads.max_files', 3);
        $allowedAges = StoryAgeOptions::forPersonalization();

        $validator = Validator::make($request->all(), [
            'child_name' => 'required|string|max:255',
            'child_age' => ['required', 'integer', Rule::in($allowedAges)],
            'child_gender' => 'required|in:boy,girl',
            'gift_note' => 'nullable|string|max:500',
            'interests' => 'nullable|string|max:500',
            'parent_notes' => 'nullable|string|max:1000',
            'photo_upload_ids' => 'nullable|array|min:'.$minimumPhotos.'|max:'.$maximumPhotos,
            'photo_upload_ids.*' => 'string|uuid',
            'photos' => 'nullable|array|min:'.$minimumPhotos.'|max:'.$maximumPhotos,
            'photos.*' => [
                'file',
                'max:'.((int) config('photo_uploads.max_size_mb', 15) * 1024),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile || ! $value->isValid()) {
                        $fail('تعذر رفع الصورة. يرجى إعادة اختيار الصورة والمحاولة مرة أخرى.');

                        return;
                    }

                    $extension = strtolower($value->getClientOriginalExtension());
                    $clientMime = strtolower((string) $value->getClientMimeType());
                    $detectedMime = strtolower((string) $value->getMimeType());

                    $hasAllowedExtension = in_array($extension, self::ALLOWED_PHOTO_EXTENSIONS, true);
                    $hasAllowedMime = in_array($clientMime, self::ALLOWED_PHOTO_MIME_TYPES, true)
                        || in_array($detectedMime, self::ALLOWED_PHOTO_MIME_TYPES, true);

                    if (! $hasAllowedExtension && ! $hasAllowedMime) {
                        $fail('صيغة الصورة غير مدعومة. ارفع صور JPG أو PNG أو WebP أو HEIC/HEIF من الموبايل.');
                    }
                },
            ],
        ], [
            'child_name.required' => 'يرجى إدخال اسم الطفل.',
            'child_name.max' => 'اسم الطفل يجب ألا يزيد عن 255 حرفاً.',
            'child_age.required' => 'يرجى إدخال عمر الطفل.',
            'child_age.integer' => 'يرجى إدخال عمر الطفل كرقم صحيح.',
            'child_age.in' => 'يرجى اختيار عمر الطفل من ٣ إلى ١٢ سنة.',
            'child_gender.required' => 'يرجى اختيار جنس الطفل.',
            'child_gender.in' => 'يرجى اختيار جنس صحيح للطفل.',
            'gift_note.max' => 'الإهداء يجب ألا يزيد عن 500 حرف.',
            'interests.max' => 'اهتمامات الطفل يجب ألا تزيد عن 500 حرف.',
            'parent_notes.max' => 'ملاحظات الفريق يجب ألا تزيد عن 1000 حرف.',
            'photo_upload_ids.required' => 'يرجى رفع صورتين واضحتين للطفل على الأقل.',
            'photo_upload_ids.array' => 'يرجى رفع صور الطفل بطريقة صحيحة.',
            'photo_upload_ids.min' => 'يرجى رفع صورتين واضحتين للطفل على الأقل.',
            'photo_upload_ids.max' => 'يمكنك رفع '.$maximumPhotos.' صور كحد أقصى.',
            'photo_upload_ids.*.uuid' => 'بعض الصور المرفوعة غير صالحة. احذفها وارفعها مرة أخرى.',
            'photos.array' => 'يرجى رفع صور الطفل بطريقة صحيحة.',
            'photos.min' => 'يرجى رفع صورتين واضحتين للطفل على الأقل.',
            'photos.max' => 'يمكنك رفع '.$maximumPhotos.' صور كحد أقصى.',
            'photos.*.file' => 'تعذر رفع الصورة. تأكد أن الملف صورة صحيحة وأن الاتصال لم ينقطع أثناء الرفع.',
            'photos.*.max' => 'حجم كل صورة يجب ألا يزيد عن '.config('photo_uploads.max_size_mb', 15).' ميجا.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if ($request->filled('photo_upload_ids') || $request->hasFile('photos')) {
                return;
            }

            $validator->errors()->add('photo_upload_ids', 'يرجى رفع صورتين واضحتين للطفل على الأقل.');
        });

        $validated = $validator->validate();

        $itemKey = (string) Str::uuid();
        $photoPaths = [];
        $photoUploadIds = $validated['photo_upload_ids'] ?? [];

        if ($photoUploadIds !== []) {
            try {
                $photoPaths = $uploads->attachIdsToCart($request, $photoUploadIds, $itemKey)
                    ->pluck('path')
                    ->all();
            } catch (UploadValidationException $exception) {
                return back()
                    ->withInput()
                    ->withErrors([$exception->field ?: 'photo_upload_ids' => $exception->getMessage()]);
            }
        } elseif ($request->hasFile('photos')) {
            foreach ($request->file('photos', []) as $photo) {
                $photoPaths[] = $photo->store('orders/cart/'.now()->format('Y-m').'/'.$itemKey, 'local');
            }
        }

        if ($photoPaths === []) {
            return back()
                ->withInput()
                ->withErrors(['photo_upload_ids' => 'يرجى رفع صورتين واضحتين للطفل على الأقل.']);
        }

        $cart = $this->cart();
        $cart[$itemKey] = $itemBuilder->build($story, $itemKey, $validated, $photoPaths);

        session(['cart.items' => $cart]);
        session()->flash('upsell_story_key', $itemKey);
        app(CartTrackingService::class)->recordItemAdded($request, $itemKey);

        $cartNotice = [
            'story_title' => $story->title,
        ];

        if ($request->input('next') === 'cart') {
            return redirect()
                ->route('cart.index')
                ->with('cart_added_notice', $cartNotice);
        }

        return redirect()
            ->route('shop.index')
            ->with('cart_added_notice', $cartNotice);
    }

    public function destroy(Request $request, string $key, ChildIdentityEventLogger $identityEvents)
    {
        $cart = $this->cart();
        $removedKeys = [];

        if (isset($cart[$key])) {
            $item = $cart[$key];
            $removedKeys[] = $key;
            $photoPathsStillInUse = $this->photoPathsReferencedByOtherItems($cart, $key);

            if (($item['item_type'] ?? 'story') === 'package') {
                foreach ($item['package_stories'] ?? [] as $packageStory) {
                    foreach ($packageStory['uploaded_photos'] ?? [] as $photoPath) {
                        if (is_string($photoPath)
                            && ! str_contains($photoPath, '..')
                            && ! in_array($photoPath, $photoPathsStillInUse, true)) {
                            Storage::disk('local')->delete($photoPath);
                        }
                    }
                }
            }

            if (empty($item['child_identity_request_id'])) {
                foreach ($item['uploaded_photos'] ?? [] as $photoPath) {
                    if (is_string($photoPath)
                        && ! str_contains($photoPath, '..')
                        && ! in_array($photoPath, $photoPathsStillInUse, true)) {
                        Storage::disk('local')->delete($photoPath);
                    }
                }
            } else {
                $identity = ChildIdentityRequest::query()->find($item['child_identity_request_id']);

                if ($identity?->status === 'in_cart') {
                    $identity->forceFill(['status' => 'story_selected'])->save();
                    $identityEvents->record(
                        $identity,
                        'cart.removed',
                        'أزال ولي الأمر قصة الهوية من السلة وأصبح بإمكانه تعديل الاختيار مرة أخرى.',
                        ['cart_item_key' => $key],
                    );
                }
            }

            unset($cart[$key]);

            if (($item['item_type'] ?? 'story') === 'story') {
                $linkedKeys = collect($cart)
                    ->filter(fn (array $cartItem) => ($cartItem['linked_story_key'] ?? null) === $key)
                    ->keys()
                    ->all();
                $removedKeys = array_values(array_unique(array_merge($removedKeys, $linkedKeys)));
                $cart = collect($cart)
                    ->reject(fn (array $cartItem) => ($cartItem['linked_story_key'] ?? null) === $key)
                    ->all();
            }

            session(['cart.items' => $cart]);
            app(CartTrackingService::class)->recordItemRemoved($request, $key, $item);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'تم حذف العنصر من السلة.',
                'removed_keys' => $removedKeys,
                'cart_count' => count($cart),
                'subtotal' => $this->subtotal($cart),
                'cart_empty' => $cart === [],
            ]);
        }

        return redirect()->route('cart.index')->with('success', 'تم حذف العنصر من السلة.');
    }

    private function cart(): array
    {
        return session('cart.items', []);
    }

    private function subtotal(array $cart): float
    {
        return collect($cart)->sum(function (array $item): float {
            if (($item['item_type'] ?? 'story') === 'story') {
                return (float) ($item['story_price'] ?? 0);
            }

            return ((int) ($item['line_total_cents'] ?? 0)) / 100;
        });
    }

    /**
     * @return array<int, string>
     */
    private function photoPathsReferencedByOtherItems(array $cart, string $excludedKey): array
    {
        return collect($cart)
            ->except($excludedKey)
            ->flatMap(function (array $item): array {
                $paths = is_array($item['uploaded_photos'] ?? null)
                    ? $item['uploaded_photos']
                    : [];

                foreach ($item['package_stories'] ?? [] as $packageStory) {
                    if (is_array($packageStory['uploaded_photos'] ?? null)) {
                        $paths = array_merge($paths, $packageStory['uploaded_photos']);
                    }
                }

                return $paths;
            })
            ->filter(fn ($path): bool => is_string($path))
            ->unique()
            ->values()
            ->all();
    }

    private function defaultDeliveryFee(): float
    {
        $country = $this->deliveryCountries()->first();

        if ($country) {
            return max(0, (float) $country->delivery_fee);
        }

        return max(0, (float) setting('delivery_fee', 0));
    }

    private function deliveryCountries()
    {
        return DeliveryCountry::where('active', true)
            ->with(['activeGovernorates'])
            ->orderByRaw("code = 'EG' desc")
            ->orderBy('name')
            ->get();
    }

    private function savedDeliveryDetails(): array
    {
        $user = auth()->user();

        if (! $user) {
            return [];
        }

        $latestOrder = Order::where('user_id', $user->id)
            ->whereNotNull('delivery_details')
            ->latest()
            ->first();

        return is_array($latestOrder?->delivery_details) ? $latestOrder->delivery_details : [];
    }
}
