<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\DeliveryCountry;
use App\Models\Order;
use App\Models\Story;
use App\Services\Cart\CartTrackingService;
use App\Services\Pricing\StoryPricingService;
use App\Services\Uploads\TemporaryPhotoUploadService;
use App\Services\Uploads\UploadValidationException;
use App\Support\ProductRecommendations;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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
        $upsellStoryKey = session('upsell_story_key');
        $upsellStoryItem = $upsellStoryKey && isset($cart[$upsellStoryKey]) ? $cart[$upsellStoryKey] : $storyItems->first();
        $recommendedProducts = $upsellStoryItem
            ? app(ProductRecommendations::class)->forStoryCartItem($upsellStoryItem, 6)
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

    public function store(Request $request, Story $story, TemporaryPhotoUploadService $uploads, StoryPricingService $storyPricing)
    {
        abort_unless($story->active, 404);

        $validator = Validator::make($request->all(), [
            'child_name' => 'required|string|max:255',
            'child_age' => 'required|integer|min:1|max:18',
            'child_gender' => 'required|in:boy,girl',
            'gift_note' => 'nullable|string|max:500',
            'interests' => 'nullable|string|max:500',
            'parent_notes' => 'nullable|string|max:1000',
            'privacy_consent' => 'required|accepted',
            'photo_upload_ids' => 'nullable|array|max:'.config('photo_uploads.max_files', 5),
            'photo_upload_ids.*' => 'string|uuid',
            'photos' => 'nullable|array|min:1|max:'.config('photo_uploads.max_files', 5),
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
            'child_age.min' => 'عمر الطفل يجب ألا يقل عن سنة واحدة.',
            'child_age.max' => 'عمر الطفل يجب ألا يزيد عن 18 سنة.',
            'child_gender.required' => 'يرجى اختيار جنس الطفل.',
            'child_gender.in' => 'يرجى اختيار جنس صحيح للطفل.',
            'gift_note.max' => 'الإهداء يجب ألا يزيد عن 500 حرف.',
            'interests.max' => 'اهتمامات الطفل يجب ألا تزيد عن 500 حرف.',
            'parent_notes.max' => 'ملاحظات الفريق يجب ألا تزيد عن 1000 حرف.',
            'privacy_consent.required' => 'يجب الموافقة على استخدام الصور لإكمال الطلب.',
            'privacy_consent.accepted' => 'يجب الموافقة على استخدام الصور لإكمال الطلب.',
            'photo_upload_ids.required' => 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.',
            'photo_upload_ids.array' => 'يرجى رفع صور الطفل بطريقة صحيحة.',
            'photo_upload_ids.min' => 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.',
            'photo_upload_ids.max' => 'يمكنك رفع '.config('photo_uploads.max_files', 5).' صور كحد أقصى.',
            'photo_upload_ids.*.uuid' => 'بعض الصور المرفوعة غير صالحة. احذفها وارفعها مرة أخرى.',
            'photos.array' => 'يرجى رفع صور الطفل بطريقة صحيحة.',
            'photos.min' => 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.',
            'photos.max' => 'يمكنك رفع '.config('photo_uploads.max_files', 5).' صور كحد أقصى.',
            'photos.*.file' => 'تعذر رفع الصورة. تأكد أن الملف صورة صحيحة وأن الاتصال لم ينقطع أثناء الرفع.',
            'photos.*.max' => 'حجم كل صورة يجب ألا يزيد عن '.config('photo_uploads.max_size_mb', 15).' ميجا.',
        ]);

        $validator->after(function ($validator) use ($request): void {
            if ($request->filled('photo_upload_ids') || $request->hasFile('photos')) {
                return;
            }

            $validator->errors()->add('photo_upload_ids', 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.');
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
                ->withErrors(['photo_upload_ids' => 'يرجى رفع صورة واحدة واضحة للطفل على الأقل.']);
        }

        $priceSnapshot = $storyPricing->snapshot($story);
        $cart = $this->cart();
        $cart[$itemKey] = [
            'key' => $itemKey,
            'item_type' => 'story',
            'story_id' => $story->id,
            'story_title' => $story->title,
            'story_slug' => $story->slug,
            'story_price' => $priceSnapshot['effective_price'],
            'story_regular_price' => $priceSnapshot['regular_price'],
            'story_offer_applied' => $priceSnapshot['offer_applied'],
            'story_offer_label' => $priceSnapshot['offer_label'],
            'story_cover_url' => $story->cover_url,
            'story_language' => $story->language,
            'story_lesson' => $story->lesson_value,
            'child_name' => $validated['child_name'],
            'child_age' => $validated['child_age'],
            'child_gender' => $validated['child_gender'],
            'interests' => $validated['interests'] ?? null,
            'gift_note' => $validated['gift_note'] ?? null,
            'parent_notes' => $validated['parent_notes'] ?? null,
            'uploaded_photos' => $photoPaths,
        ];

        session(['cart.items' => $cart]);
        session()->flash('upsell_story_key', $itemKey);
        app(CartTrackingService::class)->recordItemAdded($request, $itemKey);

        if ($request->input('next') === 'cart') {
            return redirect()->route('cart.index')->with('success', 'تمت إضافة القصة إلى السلة بنجاح.');
        }

        return redirect()
            ->route('stories.index')
            ->with('success', 'تمت إضافة القصة إلى السلة. يمكنك اختيار قصة أخرى أو إتمام الطلب من السلة.');
    }

    public function destroy(Request $request, string $key)
    {
        $cart = $this->cart();

        if (isset($cart[$key])) {
            $item = $cart[$key];

            foreach ($item['uploaded_photos'] ?? [] as $photoPath) {
                if (is_string($photoPath) && ! str_contains($photoPath, '..')) {
                    Storage::disk('local')->delete($photoPath);
                }
            }

            unset($cart[$key]);

            if (($item['item_type'] ?? 'story') === 'story') {
                $cart = collect($cart)
                    ->reject(fn (array $cartItem) => ($cartItem['linked_story_key'] ?? null) === $key)
                    ->all();
            }

            session(['cart.items' => $cart]);
            app(CartTrackingService::class)->recordItemRemoved($request, $key, $item);
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
